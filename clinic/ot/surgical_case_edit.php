<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['id']);

// Get current case details with patient info
$sql = "SELECT sc.*, 
               p.patient_first_name, p.patient_last_name, p.patient_mrn,
               u_surgeon.user_name as surgeon_name,
               u_anesthetist.user_name as anesthetist_name,
               u_referring.user_name as referring_doctor_name
        FROM surgical_cases sc 
        LEFT JOIN patients p ON sc.patient_id = p.patient_id
        LEFT JOIN users u_surgeon ON sc.primary_surgeon_id = u_surgeon.user_id
        LEFT JOIN users u_anesthetist ON sc.anesthetist_id = u_anesthetist.user_id
        LEFT JOIN users u_referring ON sc.referring_doctor_id = u_referring.user_id
        WHERE sc.case_id = $case_id";
$result = $mysqli->query($sql);

if ($result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$case = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = intval($_POST['patient_id']);
    $visit_id = intval($_POST['visit_id']);
    $referral_source = sanitizeInput($_POST['referral_source']);
    $surgical_urgency = sanitizeInput($_POST['surgical_urgency']);
    $surgical_specialty = sanitizeInput($_POST['surgical_specialty']);
    $pre_op_diagnosis = sanitizeInput($_POST['pre_op_diagnosis']);
    $planned_procedure = sanitizeInput($_POST['planned_procedure']);
    $asa_score = intval($_POST['asa_score']);
    $estimated_duration_minutes = intval($_POST['estimated_duration_minutes']);
    $primary_surgeon_id = intval($_POST['primary_surgeon_id']);
    $anesthetist_id = intval($_POST['anesthetist_id']);
    $referring_doctor_id = intval($_POST['referring_doctor_id']);
    $presentation_date = sanitizeInput($_POST['presentation_date']);
    $decision_date = sanitizeInput($_POST['decision_date']);
    $target_or_date = sanitizeInput($_POST['target_or_date']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Update surgical case
    $sql = "UPDATE surgical_cases SET
            patient_id = $patient_id,
            visit_id = $visit_id,
            referral_source = '$referral_source',
            surgical_urgency = '$surgical_urgency',
            surgical_specialty = '$surgical_specialty',
            pre_op_diagnosis = '$pre_op_diagnosis',
            planned_procedure = '$planned_procedure',
            asa_score = $asa_score,
            estimated_duration_minutes = $estimated_duration_minutes,
            primary_surgeon_id = $primary_surgeon_id,
            anesthetist_id = $anesthetist_id,
            referring_doctor_id = $referring_doctor_id,
            presentation_date = " . ($presentation_date ? "'$presentation_date'" : "NULL") . ",
            decision_date = " . ($decision_date ? "'$decision_date'" : "NULL") . ",
            target_or_date = " . ($target_or_date ? "'$target_or_date'" : "NULL") . ",
            notes = '$notes',
            updated_at = NOW(),
            updated_by = $session_user_id
            WHERE case_id = $case_id";
    
    if ($mysqli->query($sql)) {
        // Log activity
        $log_description = "Updated surgical case: " . $case['case_number'];
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Case', log_action = 'Update', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_message'] = "Surgical case updated successfully";
        header("Location: surgical_case_view.php?id=$case_id");
        exit();
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating surgical case: " . $mysqli->error;
    }
}

// Get patients for dropdown
$patients_sql = "SELECT patient_id, patient_first_name, patient_last_name, patient_mrn FROM patients ORDER BY patient_last_name";
$patients_result = $mysqli->query($patients_sql);

// Get surgeons for dropdown (users with doctor role)
$surgeons_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$surgeons_result = $mysqli->query($surgeons_sql);

// Get anesthetists for dropdown
$anesthetists_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$anesthetists_result = $mysqli->query($anesthetists_sql);

// Get referring doctors for dropdown
$doctors_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$doctors_result = $mysqli->query($doctors_sql);

// Get specialties
$specialties = [
    'General Surgery',
    'Orthopedic Surgery',
    'Cardiac Surgery',
    'Neurosurgery',
    'Plastic Surgery',
    'Ophthalmology',
    'ENT',
    'Urology',
    'Gynecology',
    'Pediatric Surgery',
    'Trauma Surgery',
    'Vascular Surgery'
];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-edit mr-2"></i>Edit Surgical Case: <?php echo htmlspecialchars($case['case_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Case
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

        <!-- Case Information Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Case:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($case['case_number']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Patient:</strong> 
                            <span class="badge badge-success ml-2">
                                <?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>MRN:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo htmlspecialchars($case['patient_mrn']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <?php
                            $status_badge = '';
                            switch($case['case_status']) {
                                case 'referred': $status_badge = 'info'; break;
                                case 'scheduled': $status_badge = 'primary'; break;
                                case 'confirmed': $status_badge = 'info'; break;
                                case 'in_or': $status_badge = 'warning'; break;
                                case 'completed': $status_badge = 'success'; break;
                                case 'cancelled': $status_badge = 'danger'; break;
                                default: $status_badge = 'secondary';
                            }
                            ?>
                            <span class="badge badge-<?php echo $status_badge; ?> ml-2">
                                <?php echo ucfirst(str_replace('_', ' ', $case['case_status'])); ?>
                            </span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="editCaseForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="" id="editCaseForm">
            <div class="row">
                <!-- Left Column: Patient & Surgical Details -->
                <div class="col-md-6">
                    <!-- Patient Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Patient Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="required">Patient</label>
                                <select class="form-control select2" name="patient_id" required>
                                    <option value="">Select Patient</option>
                                    <?php 
                                    mysqli_data_seek($patients_result, 0);
                                    while($patient = $patients_result->fetch_assoc()): ?>
                                        <option value="<?php echo $patient['patient_id']; ?>" <?php if($case['patient_id'] == $patient['patient_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($patient['patient_first_name'] . ' ' . $patient['patient_last_name'] . ' (MRN: ' . $patient['patient_mrn'] . ')'); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Visit ID</label>
                                        <input type="number" class="form-control" name="visit_id" value="<?php echo htmlspecialchars($case['visit_id']); ?>" placeholder="Visit/Encounter ID">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Referral Source</label>
                                        <select class="form-control" name="referral_source">
                                            <option value="">Select Source</option>
                                            <option value="OPD" <?php if($case['referral_source'] == 'OPD') echo 'selected'; ?>>OPD</option>
                                            <option value="Emergency" <?php if($case['referral_source'] == 'Emergency') echo 'selected'; ?>>Emergency</option>
                                            <option value="External Referral" <?php if($case['referral_source'] == 'External Referral') echo 'selected'; ?>>External Referral</option>
                                            <option value="Internal Referral" <?php if($case['referral_source'] == 'Internal Referral') echo 'selected'; ?>>Internal Referral</option>
                                            <option value="Other" <?php if($case['referral_source'] && !in_array($case['referral_source'], ['OPD', 'Emergency', 'External Referral', 'Internal Referral'])) echo 'selected'; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Surgical Details Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-stethoscope mr-2"></i>Surgical Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Surgical Urgency</label>
                                        <select class="form-control" name="surgical_urgency" required>
                                            <option value="">Select Urgency</option>
                                            <option value="emergency" <?php if($case['surgical_urgency'] == 'emergency') echo 'selected'; ?>>Emergency</option>
                                            <option value="urgent" <?php if($case['surgical_urgency'] == 'urgent') echo 'selected'; ?>>Urgent</option>
                                            <option value="elective" <?php if($case['surgical_urgency'] == 'elective') echo 'selected'; ?>>Elective</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Surgical Specialty</label>
                                        <select class="form-control select2" name="surgical_specialty" required>
                                            <option value="">Select Specialty</option>
                                            <?php foreach($specialties as $specialty): ?>
                                                <option value="<?php echo $specialty; ?>" <?php if($case['surgical_specialty'] == $specialty) echo 'selected'; ?>><?php echo $specialty; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">Pre-operative Diagnosis</label>
                                <textarea class="form-control" name="pre_op_diagnosis" rows="3" required placeholder="Enter pre-operative diagnosis"><?php echo htmlspecialchars($case['pre_op_diagnosis']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">Planned Procedure</label>
                                <textarea class="form-control" name="planned_procedure" rows="3" required placeholder="Describe the planned surgical procedure"><?php echo htmlspecialchars($case['planned_procedure']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Estimated Duration (minutes)</label>
                                        <input type="number" class="form-control" name="estimated_duration_minutes" value="<?php echo htmlspecialchars($case['estimated_duration_minutes']); ?>" min="15" step="15" placeholder="e.g., 120">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>ASA Score</label>
                                        <select class="form-control" name="asa_score">
                                            <option value="">Select ASA Score</option>
                                            <option value="1" <?php if($case['asa_score'] == 1) echo 'selected'; ?>>I - Healthy patient</option>
                                            <option value="2" <?php if($case['asa_score'] == 2) echo 'selected'; ?>>II - Mild systemic disease</option>
                                            <option value="3" <?php if($case['asa_score'] == 3) echo 'selected'; ?>>III - Severe systemic disease</option>
                                            <option value="4" <?php if($case['asa_score'] == 4) echo 'selected'; ?>>IV - Severe systemic disease that is a constant threat to life</option>
                                            <option value="5" <?php if($case['asa_score'] == 5) echo 'selected'; ?>>V - Moribund patient not expected to survive without the operation</option>
                                            <option value="6" <?php if($case['asa_score'] == 6) echo 'selected'; ?>>VI - Declared brain-dead patient</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Medical Team & Timeline -->
                <div class="col-md-6">
                    <!-- Medical Team Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <h4 class="card-title mb-0"><i class="fas fa-user-md mr-2"></i>Medical Team</h4>
                                <a href="surgical_team_management.php?case_id=<?php echo $case_id; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-users mr-1"></i>Manage Team
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Primary Surgeon</label>
                                        <select class="form-control select2" name="primary_surgeon_id" required>
                                            <option value="">Select Surgeon</option>
                                            <?php 
                                            mysqli_data_seek($surgeons_result, 0);
                                            while($surgeon = $surgeons_result->fetch_assoc()): ?>
                                                <option value="<?php echo $surgeon['user_id']; ?>" <?php if($case['primary_surgeon_id'] == $surgeon['user_id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($surgeon['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Anesthetist</label>
                                        <select class="form-control select2" name="anesthetist_id">
                                            <option value="">Select Anesthetist</option>
                                            <?php 
                                            mysqli_data_seek($anesthetists_result, 0);
                                            while($anesthetist = $anesthetists_result->fetch_assoc()): ?>
                                                <option value="<?php echo $anesthetist['user_id']; ?>" <?php if($case['anesthetist_id'] == $anesthetist['user_id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($anesthetist['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Referring Doctor</label>
                                <select class="form-control select2" name="referring_doctor_id">
                                    <option value="">Select Referring Doctor</option>
                                    <?php 
                                    mysqli_data_seek($doctors_result, 0);
                                    while($doctor = $doctors_result->fetch_assoc()): ?>
                                        <option value="<?php echo $doctor['user_id']; ?>" <?php if($case['referring_doctor_id'] == $doctor['user_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($doctor['user_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-calendar-alt mr-2"></i>Case Timeline</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Presentation Date</label>
                                        <input type="date" class="form-control" name="presentation_date" value="<?php echo $case['presentation_date'] ? date('Y-m-d', strtotime($case['presentation_date'])) : ''; ?>">
                                        <small class="form-text text-muted">Date patient presented</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Decision Date</label>
                                        <input type="date" class="form-control" name="decision_date" value="<?php echo $case['decision_date'] ? date('Y-m-d', strtotime($case['decision_date'])) : ''; ?>">
                                        <small class="form-text text-muted">Decision for surgery</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Target OR Date</label>
                                        <input type="date" class="form-control" name="target_or_date" value="<?php echo $case['target_or_date'] ? date('Y-m-d', strtotime($case['target_or_date'])) : ''; ?>">
                                        <small class="form-text text-muted">Planned surgery date</small>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if($case['surgery_date']): ?>
                            <div class="mt-3 p-3 bg-light rounded">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>Scheduled Surgery</strong><br>
                                        <small class="text-muted">
                                            Date: <?php echo date('M j, Y', strtotime($case['surgery_date'])); ?>
                                            <?php if($case['surgery_start_time']): ?>
                                                | Time: <?php echo date('H:i', strtotime($case['surgery_start_time'])); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php if($case['case_status'] == 'scheduled' || $case['case_status'] == 'confirmed'): ?>
                                        <a href="?id=<?php echo $case_id; ?>&schedule" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit mr-1"></i>Reschedule
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Additional Notes Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-notes-medical mr-2"></i>Additional Notes</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Case Notes</label>
                                <textarea class="form-control" name="notes" rows="4" placeholder="Additional notes, comments, or special considerations"><?php echo htmlspecialchars($case['notes']); ?></textarea>
                                <small class="form-text text-muted">Any additional information about this surgical case</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Discard Changes
                                    </a>
                                    <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-eye mr-2"></i>Preview
                                    </a>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-2"></i>Save Changes
                                    </button>
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Save and schedule this case?')" name="save_and_schedule">
                                        <i class="fas fa-calendar-check mr-2"></i>Save & Schedule
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.form-group {
    margin-bottom: 1rem;
}
</style>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap',
        width: '100%'
    });

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Form validation
    $('#editCaseForm').submit(function(e) {
        var isValid = true;
        var requiredFields = $(this).find('[required]');
        
        requiredFields.each(function() {
            if ($(this).val() === '' || $(this).val() === null) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            // Scroll to first invalid field
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            
            // Show error message
            if (!$('.alert-danger').length) {
                $('#editCaseForm').prepend(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields marked with *' +
                    '</div>'
                );
            }
        }
    });

    // Remove invalid class when field is filled
    $('[required]').on('input change', function() {
        if ($(this).val() !== '') {
            $(this).removeClass('is-invalid');
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#editCaseForm').submit();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            if (confirm('Are you sure you want to discard changes?')) {
                window.location.href = 'surgical_case_view.php?id=<?php echo $case_id; ?>';
            }
        }
    });
});

// Show preview in new tab
function previewCase() {
    var form = document.getElementById('editCaseForm');
    var tempForm = document.createElement('form');
    tempForm.target = '_blank';
    tempForm.method = 'POST';
    tempForm.action = 'surgical_case_preview.php';
    
    // Copy all form data
    var inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(function(input) {
        var clone = input.cloneNode(true);
        tempForm.appendChild(clone);
    });
    
    document.body.appendChild(tempForm);
    tempForm.submit();
    document.body.removeChild(tempForm);
}
</script>

<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
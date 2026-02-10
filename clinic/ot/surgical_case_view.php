<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['id']);

// Get surgical case details -

$sql = "SELECT sc.*, 
               p.first_name AS patient_first_name, 
               p.last_name AS patient_last_name, 
               p.patient_mrn, 
               p.sex AS patient_gender, 
               p.date_of_birth AS patient_dob, 
               p.phone_primary AS patient_phone,
               ps.user_name AS surgeon_name,
               a.user_name AS anesthetist_name,
               rd.user_name AS referring_doctor_name,
               t.theatre_name, 
               t.theatre_number,
               creator.user_name AS created_by_name,
               updater.user_name AS updated_by_name,
               TIMESTAMPDIFF(
                   MINUTE, 
                   sc.surgery_start_time, 
                   sc.surgery_end_time
               ) AS actual_duration,

               (SELECT COUNT(*) 
                FROM surgical_complications scc 
                WHERE scc.case_id = sc.case_id 
                AND scc.complication_status != 'resolved'
               ) AS complication_count,

               (SELECT COUNT(*) 
                FROM patient_files pf 
                WHERE pf.file_related_type = 'surgical_case' 
                AND pf.file_related_id = sc.case_id 
                AND pf.file_archived_at IS NULL
               ) AS document_count,

               (SELECT COUNT(*) 
                FROM surgical_team st 
                WHERE st.case_id = sc.case_id
               ) AS team_member_count,

               EXISTS (
                   SELECT 1 
                   FROM anaesthesia_records ar 
                   WHERE ar.surgery_id = sc.case_id
               ) AS has_anaesthesia_record

        FROM surgical_cases sc
        LEFT JOIN patients p ON sc.patient_id = p.patient_id
        LEFT JOIN users ps ON sc.primary_surgeon_id = ps.user_id
        LEFT JOIN users a ON sc.anesthetist_id = a.user_id
        LEFT JOIN users rd ON sc.referring_doctor_id = rd.user_id
        LEFT JOIN theatres t ON sc.theater_id = t.theatre_id
        LEFT JOIN users creator ON sc.created_by = creator.user_id
        LEFT JOIN users updater ON sc.updated_by = updater.user_id
        WHERE sc.case_id = $case_id";

$result = $mysqli->query($sql);

if ($result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}


$case = $result->fetch_assoc();

// Get surgical team members
$team_sql = "SELECT st.*, u.user_name
             FROM surgical_team st 
             JOIN users u ON st.user_id = u.user_id 
             WHERE st.case_id = $case_id 
             ORDER BY st.is_primary DESC, u.user_name";
$team_result = $mysqli->query($team_sql);

// Get complications from surgical_complications table
$complications_sql = "SELECT sc.*, u.user_name as reported_by_name
                      FROM surgical_complications sc
                      LEFT JOIN users u ON sc.reported_by = u.user_id 
                      WHERE sc.case_id = $case_id 
                      ORDER BY sc.occurred_at DESC, sc.complication_date DESC";
$complications_result = $mysqli->query($complications_sql);

// Get documents for this surgical case from patient_files table
$documents_sql = "SELECT pf.*, u.user_name as uploaded_by_name 
                  FROM patient_files pf 
                  LEFT JOIN users u ON pf.file_uploaded_by = u.user_id 
                  WHERE pf.file_related_type = 'surgical_case' 
                  AND pf.file_related_id = ? 
                  AND pf.file_archived_at IS NULL 
                  ORDER BY pf.file_uploaded_at DESC";
$documents_stmt = $mysqli->prepare($documents_sql);
$documents_stmt->bind_param("i", $case_id);
$documents_stmt->execute();
$documents_result = $documents_stmt->get_result();

// Get anesthesia record from anaesthesia_records table
$anesthesia_sql = "SELECT ar.*, u.user_name as anaesthetist_name
                   FROM anaesthesia_records ar
                   LEFT JOIN users u ON ar.anaesthetist_id = u.user_id 
                   WHERE ar.surgery_id = $case_id 
                   LIMIT 1";
$anesthesia_result = $mysqli->query($anesthesia_sql);
$anesthesia = $anesthesia_result->num_rows > 0 ? $anesthesia_result->fetch_assoc() : null;

// Get equipment used from surgical_equipment_usage table
$equipment_sql = "SELECT seu.*, a.asset_tag, a.asset_name, a.manufacturer, a.model, 
                         a.is_critical, u.user_name as recorded_by_name
                  FROM surgical_equipment_usage seu
                  LEFT JOIN assets a ON seu.asset_id = a.asset_id
                  LEFT JOIN users u ON seu.created_by = u.user_id
                  WHERE seu.case_id = $case_id 
                  ORDER BY seu.usage_start_time, seu.created_at DESC";
$equipment_result = $mysqli->query($equipment_sql);
$equipment_count = $equipment_result->num_rows;

// Calculate equipment usage statistics
$total_quantity = 0;
$critical_equipment_count = 0;
while ($equipment = $equipment_result->fetch_assoc()) {
    $total_quantity += $equipment['quantity_used'] ?: 1;
    if ($equipment['is_critical']) {
        $critical_equipment_count++;
    }
}
$equipment_result->data_seek(0); // Reset pointer for display

// Handle status change
if (isset($_POST['update_status'])) {
    $new_status = sanitizeInput($_POST['case_status']);
    $surgery_date = sanitizeInput($_POST['surgery_date'] ?? '');
    $surgery_start_time = sanitizeInput($_POST['surgery_start_time'] ?? '');
    $surgery_end_time = sanitizeInput($_POST['surgery_end_time'] ?? '');
    $theater_id = intval($_POST['theater_id'] ?? 0);
    $surgical_outcome = sanitizeInput($_POST['surgical_outcome'] ?? '');
    
    $update_sql = "UPDATE surgical_cases SET 
                   case_status = '$new_status',
                   surgery_date = " . ($surgery_date ? "'$surgery_date'" : "NULL") . ",
                   surgery_start_time = " . ($surgery_start_time ? "'$surgery_start_time'" : "NULL") . ",
                   surgery_end_time = " . ($surgery_end_time ? "'$surgery_end_time'" : "NULL") . ",
                   theater_id = $theater_id,
                   surgical_outcome = '$surgical_outcome',
                   updated_at = NOW()
                   WHERE case_id = $case_id";
    
    if ($mysqli->query($update_sql)) {
        // Log activity
        $log_description = "Updated surgical case status: " . $case['case_number'] . " to $new_status";
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Case', log_action = 'Update', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_message'] = "Case status updated to " . ucfirst($new_status);
        header("Location: surgical_case_view.php?id=$case_id");
        exit();
    }
}

// Get theatres for scheduling
$theatres_sql = "SELECT * FROM theatres WHERE is_active = 1 AND archived_at IS NULL ORDER BY theatre_number";
$theatres_result = $mysqli->query($theatres_sql);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-procedures mr-2"></i>Surgical Case: <?php echo htmlspecialchars($case['case_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="theatre_dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Theatre
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-file-medical-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Documents</span>
                        <span class="info-box-number"><?php echo $case['document_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-user-md"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Team Members</span>
                        <span class="info-box-number"><?php echo $case['team_member_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Complications</span>
                        <span class="info-box-number"><?php echo $case['complication_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-tools"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Equipment</span>
                        <span class="info-box-number"><?php echo $equipment_count; ?></span>
                    </div>
                </div>
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

        <!-- Page Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <?php
                        $status_badge = '';
                        $status_text = strtoupper(str_replace('_', ' ', $case['case_status']));
                        switch($case['case_status']) {
                            case 'referred': $status_badge = 'badge-info'; break;
                            case 'scheduled': $status_badge = 'badge-primary'; break;
                            case 'confirmed': $status_badge = 'badge-info'; break;
                            case 'in_or': $status_badge = 'badge-warning'; break;
                            case 'completed': $status_badge = 'badge-success'; break;
                            case 'cancelled': $status_badge = 'badge-danger'; break;
                            default: $status_badge = 'badge-secondary';
                        }
                        ?>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge <?php echo $status_badge; ?> ml-2"><?php echo $status_text; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Patient:</strong> 
                            <span class="badge badge-info ml-2">
                                <?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>MRN:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo htmlspecialchars($case['patient_mrn']); ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="surgical_case_edit.php?id=<?php echo $case_id; ?>" class="btn btn-success">
                            <i class="fas fa-edit mr-2"></i>Edit Case
                        </a>
                        <a href="surgical_case_print.php?id=<?php echo $case_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-cog mr-2"></i>Actions
                            </button>
                            <div class="dropdown-menu">
                                <?php if($case['case_status'] == 'referred'): ?>
                                    <a class="dropdown-item text-primary" href="?id=<?php echo $case_id; ?>&schedule">
                                        <i class="fas fa-calendar-check mr-2"></i>Schedule Surgery
                                    </a>
                                <?php endif; ?>
                                
                                <?php if($case['case_status'] == 'scheduled'): ?>
                                    <a class="dropdown-item text-warning confirm-link" href="post.php?update_case_status=<?php echo $case_id; ?>&status=in_or">
                                        <i class="fas fa-running mr-2"></i>Mark as In OR
                                    </a>
                                <?php endif; ?>
                                
                                <?php if($case['case_status'] == 'in_or'): ?>
                                    <a class="dropdown-item text-success confirm-link" href="post.php?update_case_status=<?php echo $case_id; ?>&status=completed">
                                        <i class="fas fa-check-circle mr-2"></i>Mark as Completed
                                    </a>
                                <?php endif; ?>
                                
                                <?php if($case['case_status'] == 'scheduled'): ?>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?cancel_case=<?php echo $case_id; ?>">
                                        <i class="fas fa-times mr-2"></i>Cancel Case
                                    </a>
                                <?php endif; ?>
                                
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="surgical_team_management.php?case_id=<?php echo $case_id; ?>">
                                    <i class="fas fa-user-md mr-2"></i>Manage Team
                                </a>
                                <a class="dropdown-item" href="surgical_documents.php?case_id=<?php echo $case_id; ?>">
                                    <i class="fas fa-file-medical-alt mr-2"></i>Manage Documents
                                </a>
                                <?php if($anesthesia): ?>
                                    <a class="dropdown-item" href="anesthesia_records_view.php?record_id=<?php echo $anesthesia['record_id']; ?>">
                                        <i class="fas fa-syringe mr-2"></i>View Anesthesia Record
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item" href="anaesthesia_new.php?case_id=<?php echo $case_id; ?>">
                                        <i class="fas fa-syringe mr-2"></i>Add Anesthesia Record
                                    </a>
                                <?php endif; ?>
                                <a class="dropdown-item" href="surgical_equipment_usage.php?case_id=<?php echo $case_id; ?>">
                                    <i class="fas fa-tools mr-2"></i>Equipment Usage
                                </a>
                                <a class="dropdown-item" href="surgical_complications_new.php?case_id=<?php echo $case_id; ?>">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Complications
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Update Form (for scheduling) -->
        <?php if(isset($_GET['schedule']) || $case['case_status'] == 'referred'): ?>
        <div class="card mb-4" id="schedule">
            <div class="card-header bg-warning py-2">
                <h5 class="card-title mb-0"><i class="fas fa-calendar-check mr-2"></i>Schedule This Case</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Theatre</label>
                                <select class="form-control" name="theater_id" required>
                                    <option value="">Select Theatre</option>
                                    <?php 
                                    $theatres_result->data_seek(0); // Reset pointer
                                    while($theatre = $theatres_result->fetch_assoc()): ?>
                                        <option value="<?php echo $theatre['theatre_id']; ?>" <?php if($case['theater_id'] == $theatre['theatre_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($theatre['theatre_number'] . ' - ' . $theatre['theatre_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Surgery Date</label>
                                <input type="date" class="form-control" name="surgery_date" value="<?php echo $case['surgery_date'] ? date('Y-m-d', strtotime($case['surgery_date'])) : ''; ?>" required>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Start Time</label>
                                <input type="time" class="form-control" name="surgery_start_time" value="<?php echo $case['surgery_start_time']; ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>End Time</label>
                                <input type="time" class="form-control" name="surgery_end_time" value="<?php echo $case['surgery_end_time']; ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Status</label>
                                <select class="form-control" name="case_status" required>
                                    <option value="scheduled" <?php if($case['case_status'] == 'scheduled') echo 'selected'; ?>>Scheduled</option>
                                    <option value="confirmed" <?php if($case['case_status'] == 'confirmed') echo 'selected'; ?>>Confirmed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <button type="submit" name="update_status" class="btn btn-success">
                            <i class="fas fa-calendar-plus mr-2"></i>Schedule Case
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Main Content -->
            <div class="col-md-8">
                <!-- Patient & Procedure Details -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Case Details</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Patient Name:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong><?php echo htmlspecialchars($case['patient_mrn']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date of Birth:</th>
                                            <td><?php echo $case['patient_dob'] ? date('M j, Y', strtotime($case['patient_dob'])) : 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Gender:</th>
                                            <td>
                                                <?php 
                                                $gender_text = '';
                                                switch($case['patient_gender']) {
                                                    case 'M': $gender_text = 'Male'; break;
                                                    case 'F': $gender_text = 'Female'; break;
                                                    case 'I': $gender_text = 'Intersex'; break;
                                                    default: $gender_text = 'N/A';
                                                }
                                                echo $gender_text;
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Phone:</th>
                                            <td><?php echo htmlspecialchars($case['patient_phone'] ?? 'N/A'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Surgical Urgency:</th>
                                            <td>
                                                <?php
                                                $urgency_badge = '';
                                                switch($case['surgical_urgency']) {
                                                    case 'emergency': $urgency_badge = 'danger'; break;
                                                    case 'urgent': $urgency_badge = 'warning'; break;
                                                    case 'elective': $urgency_badge = 'info'; break;
                                                    default: $urgency_badge = 'secondary';
                                                }
                                                ?>
                                                <span class="badge badge-<?php echo $urgency_badge; ?>">
                                                    <?php echo ucfirst($case['surgical_urgency']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Surgical Specialty:</th>
                                            <td><?php echo htmlspecialchars($case['surgical_specialty']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">ASA Score:</th>
                                            <td>
                                                <?php if($case['asa_score']): ?>
                                                    <span class="badge badge-secondary">ASA <?php echo $case['asa_score']; ?></span>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Referral Source:</th>
                                            <td>
                                                <?php 
                                                $source_text = ucfirst($case['referral_source'] ?? 'opd');
                                                echo $source_text;
                                                ?>
                                            </td>
                                        </tr>
                                        <?php if($case['actual_duration']): ?>
                                        <tr>
                                            <th class="text-muted">Actual Duration:</th>
                                            <td><strong class="text-success"><?php echo $case['actual_duration']; ?> minutes</strong></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="p-3 bg-light rounded">
                                    <h6><i class="fas fa-file-medical mr-2"></i>Pre-op Diagnosis</h6>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($case['pre_op_diagnosis'])); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-light rounded">
                                    <h6><i class="fas fa-procedures mr-2"></i>Planned Procedure</h6>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($case['planned_procedure'])); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if($case['notes']): ?>
                        <div class="mt-3 p-3 bg-light rounded">
                            <h6><i class="fas fa-notes-medical mr-2"></i>Notes</h6>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($case['notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Medical Team -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-user-md mr-2"></i>Medical Team</h4>
                            <a href="surgical_team_management.php?case_id=<?php echo $case_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus mr-1"></i>Manage Team
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded mb-3">
                                    <i class="fas fa-user-md fa-2x text-primary mb-2"></i>
                                    <h6>Primary Surgeon</h6>
                                    <p class="mb-0 font-weight-bold"><?php echo htmlspecialchars($case['surgeon_name'] ?? 'Not assigned'); ?></p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded mb-3">
                                    <i class="fas fa-syringe fa-2x text-info mb-2"></i>
                                    <h6>Anesthetist</h6>
                                    <p class="mb-0 font-weight-bold"><?php echo htmlspecialchars($case['anesthetist_name'] ?? 'Not assigned'); ?></p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded mb-3">
                                    <i class="fas fa-user-md fa-2x text-secondary mb-2"></i>
                                    <h6>Referring Doctor</h6>
                                    <p class="mb-0 font-weight-bold"><?php echo htmlspecialchars($case['referring_doctor_name'] ?? 'Not assigned'); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if($team_result->num_rows > 0): ?>
                        <div class="mt-4">
                            <h6>Surgical Team Members</h6>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Role</th>
                                            <th>Primary</th>
                                            <th>Assigned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $team_result->data_seek(0); // Reset pointer
                                        while($member = $team_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($member['user_name']); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['role']); ?></td>
                                            <td>
                                                <?php if($member['is_primary']): ?>
                                                    <span class="badge badge-success">Yes</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($member['assigned_at'])); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Anesthesia Record -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-syringe mr-2"></i>Anesthesia Record</h4>
                            <?php if($anesthesia): ?>
                                <a href="anaesthesia_new.php?case_id=<?php echo $case_id; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>
                            <?php else: ?>
                                <a href="anaesthesia_new.php?case_id=<?php echo $case_id; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-plus mr-1"></i>Add Anesthesia Record
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if($anesthesia): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="30%" class="text-muted">Anesthetist:</th>
                                        <td><?php echo htmlspecialchars($anesthesia['anaesthetist_name'] ?? 'Not specified'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Anesthesia Type:</th>
                                        <td><?php echo htmlspecialchars($anesthesia['anaesthesia_type'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php if($anesthesia['start_time']): ?>
                                    <tr>
                                        <th class="text-muted">Start Time:</th>
                                        <td><?php echo date('M j, Y H:i', strtotime($anesthesia['start_time'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if($anesthesia['end_time']): ?>
                                    <tr>
                                        <th class="text-muted">End Time:</th>
                                        <td><?php echo date('M j, Y H:i', strtotime($anesthesia['end_time'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if($anesthesia['agents_used']): ?>
                                    <tr>
                                        <th class="text-muted">Agents Used:</th>
                                        <td><?php echo htmlspecialchars($anesthesia['agents_used']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if($anesthesia['monitoring_parameters']): ?>
                                    <tr>
                                        <th class="text-muted">Monitoring:</th>
                                        <td><?php echo htmlspecialchars($anesthesia['monitoring_parameters']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <?php if($anesthesia['complications']): ?>
                            <div class="mt-3 p-3 bg-light rounded">
                                <h6><i class="fas fa-exclamation-triangle mr-2 text-warning"></i>Anesthesia Complications</h6>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($anesthesia['complications'])); ?></p>
                            </div>
                            <?php endif; ?>
                            <div class="mt-3 text-right">
                                <a href="anaesthesia_view.php?record_id=<?php echo $anesthesia['record_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-external-link-alt mr-1"></i> View Full Record
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-syringe fa-2x text-muted mb-2"></i>
                                <h5 class="text-muted">No Anesthesia Record</h5>
                                <p class="text-muted mb-0">No anesthesia record has been created for this case.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Complications -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-exclamation-triangle mr-2"></i>Complications</h4>
                            <a href="surgical_complications_new.php?case_id=<?php echo $case_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus mr-1"></i>Add Complication
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if($complications_result->num_rows > 0): ?>
                            <div class="list-group">
                                <?php while($complication = $complications_result->fetch_assoc()): ?>
                                    <?php 
                                    // Get Clavien-Dindo badge color
                                    $clavien_badge = '';
                                    switch($complication['clavien_dindo_grade']) {
                                        case 'I': $clavien_badge = 'badge-success'; break;
                                        case 'II': $clavien_badge = 'badge-info'; break;
                                        case 'IIIa': $clavien_badge = 'badge-warning'; break;
                                        case 'IIIb': $clavien_badge = 'badge-warning'; break;
                                        case 'IVa': $clavien_badge = 'badge-danger'; break;
                                        case 'IVb': $clavien_badge = 'badge-danger'; break;
                                        case 'V': $clavien_badge = 'badge-dark'; break;
                                        default: $clavien_badge = 'badge-secondary';
                                    }
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="font-weight-bold">
                                                    <?php echo htmlspecialchars($complication['complication_category'] ?? $complication['complication_type']); ?>
                                                    <span class="badge <?php echo $clavien_badge; ?> ml-2">Clavien <?php echo $complication['clavien_dindo_grade']; ?></span>
                                                </div>
                                                <div class="small text-muted">
                                                    <?php echo date('M j, Y H:i', strtotime($complication['occurred_at'] ?? $complication['complication_date'])); ?>
                                                    <?php if($complication['reported_by_name']): ?> • Reported by: <?php echo htmlspecialchars($complication['reported_by_name']); ?><?php endif; ?>
                                                </div>
                                            </div>
                                            <a href="surgical_complications_view.php?complication_id=<?php echo $complication['complication_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                        <div class="mt-2">
                                            <p class="mb-1 small"><?php echo htmlspecialchars(substr($complication['complication_description'], 0, 200)); ?>...</p>
                                            <?php if($complication['outcome']): ?>
                                                <span class="badge badge-info">Outcome: <?php echo $complication['outcome']; ?></span>
                                            <?php endif; ?>
                                            <?php if($complication['severity']): ?>
                                                <span class="badge badge-warning ml-1">Severity: <?php echo $complication['severity']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-check-circle fa-2x text-muted mb-2"></i>
                                <h5 class="text-muted">No Complications</h5>
                                <p class="text-muted mb-0">No complications have been reported for this case.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Equipment Usage -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-tools mr-2"></i>Equipment Usage</h4>
                            <a href="surgical_equipment_usage.php?case_id=<?php echo $case_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus mr-1"></i>Add Equipment
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if($equipment_count > 0): ?>
                            <div class="mb-3">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-primary mb-1"><?php echo $equipment_count; ?></div>
                                            <small class="text-muted">Equipment Items</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-success mb-1"><?php echo $total_quantity; ?></div>
                                            <small class="text-muted">Total Quantity</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-warning mb-1"><?php echo $critical_equipment_count; ?></div>
                                            <small class="text-muted">Critical Equipment</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Equipment</th>
                                            <th>Quantity</th>
                                            <th>Usage Time</th>
                                            <th>Recorded By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($equipment = $equipment_result->fetch_assoc()): ?>
                                        <tr class="<?php echo $equipment['is_critical'] ? 'table-warning' : ''; ?>">
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($equipment['asset_name']); ?></div>
                                                <div class="small">
                                                    <span class="text-muted"><?php echo htmlspecialchars($equipment['asset_tag'] ?? 'N/A'); ?></span>
                                                    <?php if($equipment['manufacturer']): ?> | <?php echo htmlspecialchars($equipment['manufacturer']); ?><?php endif; ?>
                                                    <?php if($equipment['is_critical']): ?> <span class="badge badge-danger">Critical</span><?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo $equipment['quantity_used']; ?></td>
                                            <td>
                                                <?php if($equipment['usage_start_time']): ?>
                                                    <?php echo date('H:i', strtotime($equipment['usage_start_time'])); ?>
                                                    <?php if($equipment['usage_end_time']): ?> - <?php echo date('H:i', strtotime($equipment['usage_end_time'])); ?><?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($equipment['recorded_by_name']); ?></small>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-tools fa-2x text-muted mb-2"></i>
                                <h5 class="text-muted">No Equipment Recorded</h5>
                                <p class="text-muted mb-0">No equipment usage has been recorded for this case.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Visit Information -->
                <?php 
                $visit_id = $case['visit_id'];
                if ($visit_id): 
                    $visit_sql = "SELECT 
                        v.visit_number, 
                        v.visit_type, 
                        v.visit_datetime,
                        v.visit_status,
                        d.department_name
                    FROM visits v
                    LEFT JOIN departments d ON v.department_id = d.department_id
                    WHERE v.visit_id = $visit_id";
                    
                    $visit_result = $mysqli->query($visit_sql);
                    if ($visit_result->num_rows > 0):
                        $visit = $visit_result->fetch_assoc();
                ?>
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-hospital mr-2"></i>Visit Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="30%" class="text-muted">Visit Number:</th>
                                    <td><strong><?php echo htmlspecialchars($visit['visit_number']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Visit Type:</th>
                                    <td><span class="badge badge-info"><?php echo htmlspecialchars($visit['visit_type']); ?></span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Visit Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($visit['visit_datetime'])); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Visit Status:</th>
                                    <td>
                                        <?php 
                                        $status_badge = '';
                                        switch($visit['visit_status']) {
                                            case 'ACTIVE': $status_badge = 'success'; break;
                                            case 'CLOSED': $status_badge = 'secondary'; break;
                                            case 'CANCELLED': $status_badge = 'danger'; break;
                                            default: $status_badge = 'light';
                                        }
                                        ?>
                                        <span class="badge badge-<?php echo $status_badge; ?>">
                                            <?php echo htmlspecialchars($visit['visit_status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php if($visit['department_name']): ?>
                                <tr>
                                    <th class="text-muted">Department:</th>
                                    <td><?php echo htmlspecialchars($visit['department_name']); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <div class="mt-3">
                            <a href="visits_view.php?id=<?php echo $visit_id; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-external-link-alt mr-1"></i> View Full Visit Details
                            </a>
                        </div>
                    </div>
                </div>
                <?php 
                    endif;
                endif; 
                ?>
            </div>

            <!-- Sidebar Information -->
            <div class="col-md-4">
                <!-- Surgery Schedule -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-calendar-alt mr-2"></i>Surgery Schedule</h4>
                    </div>
                    <div class="card-body">
                        <?php if($case['surgery_date']): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%" class="text-muted">Surgery Date:</th>
                                        <td><strong><?php echo date('M j, Y', strtotime($case['surgery_date'])); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Surgery Time:</th>
                                        <td>
                                            <?php if($case['surgery_start_time']): ?>
                                                <?php echo date('H:i', strtotime($case['surgery_start_time'])); ?>
                                                <?php if($case['surgery_end_time']): ?> - <?php echo date('H:i', strtotime($case['surgery_end_time'])); ?><?php endif; ?>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Theatre:</th>
                                        <td>
                                            <?php if($case['theatre_name']): ?>
                                                <span class="badge badge-info"><?php echo htmlspecialchars($case['theatre_number'] . ' - ' . $case['theatre_name']); ?></span>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-calendar-times fa-2x text-muted mb-2"></i>
                                <h5 class="text-muted">Not Scheduled</h5>
                                <p class="text-muted mb-0">This case has not been scheduled yet.</p>
                                <a href="?id=<?php echo $case_id; ?>&schedule" class="btn btn-primary btn-sm mt-2">
                                    <i class="fas fa-calendar-check mr-1"></i>Schedule Now
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Case Timeline -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-stream mr-2"></i>Case Timeline</h4>
                    </div>
                    <div class="card-body">
                        <div class="timeline mini">
                            <div class="timeline-item <?php echo $case['presentation_date'] ? 'active' : 'pending'; ?>">
                                <div class="timeline-time">
                                    <?php echo $case['presentation_date'] ? date('M j', strtotime($case['presentation_date'])) : 'Pending'; ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="font-weight-bold">Presentation</div>
                                    <small class="text-muted">Initial consultation</small>
                                </div>
                            </div>
                            
                            <div class="timeline-item <?php echo $case['decision_date'] ? 'active' : 'pending'; ?>">
                                <div class="timeline-time">
                                    <?php echo $case['decision_date'] ? date('M j', strtotime($case['decision_date'])) : 'Pending'; ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="font-weight-bold">Decision for Surgery</div>
                                    <small class="text-muted">Surgery decision made</small>
                                </div>
                            </div>
                            
                            <div class="timeline-item <?php echo $case['target_or_date'] ? 'active' : 'pending'; ?>">
                                <div class="timeline-time">
                                    <?php echo $case['target_or_date'] ? date('M j', strtotime($case['target_or_date'])) : 'Not set'; ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="font-weight-bold">Target OR Date</div>
                                    <small class="text-muted">Planned surgery date</small>
                                </div>
                            </div>
                            
                            <div class="timeline-item <?php echo $case['surgery_date'] ? 'active' : 'pending'; ?>">
                                <div class="timeline-time">
                                    <?php echo $case['surgery_date'] ? date('M j', strtotime($case['surgery_date'])) : 'Not scheduled'; ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="font-weight-bold">Surgery Date</div>
                                    <small class="text-muted">Actual surgery date</small>
                                </div>
                            </div>
                            
                            <div class="timeline-item <?php echo $case['case_status'] == 'completed' ? 'active' : 'pending'; ?>">
                                <div class="timeline-time">
                                    <?php echo $case['case_status'] == 'completed' ? date('M j', strtotime($case['updated_at'])) : 'Pending'; ?>
                                </div>
                                <div class="timeline-content">
                                    <div class="font-weight-bold">Completion</div>
                                    <small class="text-muted">Case completion</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pre-op Checklist Status -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-clipboard-check mr-2"></i>Pre-op Checklist</h4>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 py-2 border-0">
                                <div class="d-flex align-items-center">
                                    <div class="mr-3">
                                        <i class="fas fa-<?php echo $case['consent_signed'] ? 'check text-success' : 'times text-muted'; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">Consent Signed</div>
                                    <div>
                                        <span class="badge badge-<?php echo $case['consent_signed'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $case['consent_signed'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2 border-0">
                                <div class="d-flex align-items-center">
                                    <div class="mr-3">
                                        <i class="fas fa-<?php echo $case['labs_completed'] ? 'check text-success' : 'times text-muted'; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">Labs Completed</div>
                                    <div>
                                        <span class="badge badge-<?php echo $case['labs_completed'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $case['labs_completed'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2 border-0">
                                <div class="d-flex align-items-center">
                                    <div class="mr-3">
                                        <i class="fas fa-<?php echo $case['imaging_completed'] ? 'check text-success' : 'times text-muted'; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">Imaging Available</div>
                                    <div>
                                        <span class="badge badge-<?php echo $case['imaging_completed'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $case['imaging_completed'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2 border-0">
                                <div class="d-flex align-items-center">
                                    <div class="mr-3">
                                        <i class="fas fa-<?php echo $case['anes_clearance'] ? 'check text-success' : 'times text-muted'; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">Anesthesia Clearance</div>
                                    <div>
                                        <span class="badge badge-<?php echo $case['anes_clearance'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $case['anes_clearance'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2 border-0">
                                <div class="d-flex align-items-center">
                                    <div class="mr-3">
                                        <i class="fas fa-<?php echo $case['npo_confirmed'] ? 'check text-success' : 'times text-muted'; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">NPO Confirmed</div>
                                    <div>
                                        <span class="badge badge-<?php echo $case['npo_confirmed'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $case['npo_confirmed'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 text-center">
                            <?php 
                            $completed_count = 0;
                            $total_checks = 5;
                            foreach(['consent_signed', 'labs_completed', 'imaging_completed', 'anes_clearance', 'npo_confirmed'] as $check) {
                                if ($case[$check]) $completed_count++;
                            }
                            $percentage = ($completed_count / $total_checks) * 100;
                            ?>
                            <div class="progress mb-2">
                                <div class="progress-bar bg-<?php echo $percentage == 100 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $percentage; ?>%">
                                    <?php echo round($percentage); ?>%
                                </div>
                            </div>
                            <small class="text-muted">Pre-op checklist completion</small>
                        </div>
                    </div>
                </div>

                <!-- Case Metadata -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-database mr-2"></i>Case Metadata</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Created By:</th>
                                    <td><?php echo htmlspecialchars($case['created_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Created Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($case['created_at'])); ?></td>
                                </tr>
                                <?php if($case['updated_by']): ?>
                                <tr>
                                    <th class="text-muted">Last Updated By:</th>
                                    <td><?php echo htmlspecialchars($case['updated_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Last Updated:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($case['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if($case['completed_at']): ?>
                                <tr>
                                    <th class="text-muted">Completed Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($case['completed_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if($case['case_status'] == 'referred'): ?>
                                <a href="?id=<?php echo $case_id; ?>&schedule" class="btn btn-success">
                                    <i class="fas fa-calendar-check mr-2"></i>Schedule Surgery
                                </a>
                            <?php endif; ?>
                            
                            <?php if($case['case_status'] == 'scheduled'): ?>
                                <a href="post.php?update_case_status=<?php echo $case_id; ?>&status=in_or" class="btn btn-warning confirm-link">
                                    <i class="fas fa-running mr-2"></i>Mark as In OR
                                </a>
                            <?php endif; ?>
                            
                            <?php if($case['case_status'] == 'in_or'): ?>
                                <a href="post.php?update_case_status=<?php echo $case_id; ?>&status=completed" class="btn btn-success confirm-link">
                                    <i class="fas fa-check-circle mr-2"></i>Mark as Completed
                                </a>
                            <?php endif; ?>
                            
                            <a href="surgical_team_management.php?case_id=<?php echo $case_id; ?>" class="btn btn-primary">
                                <i class="fas fa-user-md mr-2"></i>Manage Team
                            </a>
                            
                            <a href="surgical_documents.php?case_id=<?php echo $case_id; ?>" class="btn btn-info">
                                <i class="fas fa-file-medical-alt mr-2"></i>Manage Documents
                            </a>
                            
                            <?php if($anesthesia): ?>
                                <a href="anaesthesia_view.php?record_id=<?php echo $anesthesia['record_id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-syringe mr-2"></i>Anesthesia Record
                                </a>
                            <?php else: ?>
                                <a href="anaesthesia_new.php?case_id=<?php echo $case_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-syringe mr-2"></i>Add Anesthesia Record
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline.mini .timeline-item {
    position: relative;
    padding-left: 30px;
    margin-bottom: 15px;
    border-left: 2px solid #dee2e6;
}
.timeline.mini .timeline-item.active {
    border-left-color: #28a745;
}
.timeline.mini .timeline-item.pending {
    border-left-color: #ffc107;
}
.timeline.mini .timeline-item:last-child {
    margin-bottom: 0;
}
.timeline.mini .timeline-item::before {
    content: '';
    position: absolute;
    left: -6px;
    top: 0;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #dee2e6;
}
.timeline.mini .timeline-item.active::before {
    background: #28a745;
}
.timeline.mini .timeline-item.pending::before {
    background: #ffc107;
}
.timeline.mini .timeline-time {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 2px;
}
.info-box {
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
}
.info-box-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}
</style>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Confirm action links
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action?')) {
            e.preventDefault();
        }
    });

    // Tooltip initialization
    $('[title]').tooltip();
    
    // Auto-scroll to schedule section if hash present
    if (window.location.hash === '#schedule') {
        $('html, body').animate({
            scrollTop: $('#schedule').offset().top - 20
        }, 500);
    }
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E for edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'surgical_case_edit.php?id=<?php echo $case_id; ?>';
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('surgical_case_print.php?id=<?php echo $case_id; ?>', '_blank');
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'theatre_dashboard.php';
    }
});
</script>

<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "appointment_date";
$order = "ASC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get logged-in user's ID for permission checks
$current_user_id = intval($_SESSION['user_id']);

// Appointment Status Filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_query = "AND (a.status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    // Default - upcoming appointments
    $status_query = "AND a.status IN ('scheduled', 'completed')";
    $status_filter = '';
}

// Appointment Type Filter
if (isset($_GET['type']) && !empty($_GET['type'])) {
    $type_query = "AND (a.appointment_type = '" . sanitizeInput($_GET['type']) . "')";
    $type_filter = nullable_htmlentities($_GET['type']);
} else {
    // Default - any
    $type_query = '';
    $type_filter = '';
}

// Priority Filter
if (isset($_GET['priority']) && !empty($_GET['priority'])) {
    $priority_query = "AND (a.priority = '" . sanitizeInput($_GET['priority']) . "')";
    $priority_filter = nullable_htmlentities($_GET['priority']);
} else {
    $priority_query = '';
    $priority_filter = '';
}

// Doctor Filter - Filter by appointment creator
if (isset($_GET['doctor']) && !empty($_GET['doctor'])) {
    $doctor_id_filter = intval($_GET['doctor']);
    $doctor_query = "AND (a.created_by = $doctor_id_filter)";
} else {
    $doctor_id_filter = 0;
    $doctor_query = '';
}

// Date Range for Appointments
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-d'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d', strtotime('+30 days')));

// Handle appointment actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['reschedule_appointment'])) {
        $appointment_id = intval($_POST['appointment_id']);
        $new_date = sanitizeInput($_POST['new_date']);
        $new_time = sanitizeInput($_POST['new_time']);
        
        // Check if user has permission to modify this appointment
        $check_sql = "SELECT appointment_id, created_by FROM appointments WHERE appointment_id = ?";
        $check_stmt = mysqli_prepare($mysqli, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $appointment_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $appointment = mysqli_fetch_assoc($check_result);
            $created_by = $appointment['created_by'];
            
            // Allow if current user is the creator OR has admin role
            if ($created_by == $current_user_id || $_SESSION['user_role'] == 'Admin') {
                $update_sql = "UPDATE appointments SET appointment_date = ?, appointment_time = ?, updated_at = NOW() WHERE appointment_id = ?";
                $update_stmt = mysqli_prepare($mysqli, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "ssi", $new_date, $new_time, $appointment_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $_SESSION['alert_message'] = "Appointment rescheduled successfully!";
                } else {
                    $_SESSION['alert_message'] = "Error rescheduling appointment: " . mysqli_error($mysqli);
                }
                mysqli_stmt_close($update_stmt);
            } else {
                $_SESSION['alert_message'] = "You don't have permission to reschedule this appointment.";
            }
        } else {
            $_SESSION['alert_message'] = "Appointment not found.";
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if (isset($_POST['cancel_appointment'])) {
        $appointment_id = intval($_POST['appointment_id']);
        $cancellation_reason = sanitizeInput($_POST['cancellation_reason'] ?? '');
        
        // Check if user has permission to cancel this appointment
        $check_sql = "SELECT appointment_id, created_by FROM appointments WHERE appointment_id = ?";
        $check_stmt = mysqli_prepare($mysqli, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $appointment_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $appointment = mysqli_fetch_assoc($check_result);
            $created_by = $appointment['created_by'];
            
            // Allow if current user is the creator OR has admin role
            if ($created_by == $current_user_id || $_SESSION['user_role'] == 'Admin') {
                $update_sql = "UPDATE appointments SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW(), updated_at = NOW() WHERE appointment_id = ?";
                $update_stmt = mysqli_prepare($mysqli, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "si", $cancellation_reason, $appointment_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $_SESSION['alert_message'] = "Appointment cancelled successfully!";
                } else {
                    $_SESSION['alert_message'] = "Error cancelling appointment: " . mysqli_error($mysqli);
                }
                mysqli_stmt_close($update_stmt);
            } else {
                $_SESSION['alert_message'] = "You don't have permission to cancel this appointment.";
            }
        } else {
            $_SESSION['alert_message'] = "Appointment not found.";
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if (isset($_POST['update_appointment_status'])) {
        $appointment_id = intval($_POST['appointment_id']);
        $new_status = sanitizeInput($_POST['status']);
        
        // Check if user has permission to modify this appointment
        $check_sql = "SELECT appointment_id, created_by FROM appointments WHERE appointment_id = ?";
        $check_stmt = mysqli_prepare($mysqli, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $appointment_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $appointment = mysqli_fetch_assoc($check_result);
            $created_by = $appointment['created_by'];
            
            // Allow if current user is the creator OR has admin role
            if ($created_by == $current_user_id || $_SESSION['user_role'] == 'Admin') {
                $update_sql = "UPDATE appointments SET status = ?, updated_at = NOW() WHERE appointment_id = ?";
                $update_stmt = mysqli_prepare($mysqli, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "si", $new_status, $appointment_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $_SESSION['alert_message'] = "Appointment status updated successfully!";
                } else {
                    $_SESSION['alert_message'] = "Error updating appointment status: " . mysqli_error($mysqli);
                }
                mysqli_stmt_close($update_stmt);
            } else {
                $_SESSION['alert_message'] = "You don't have permission to update this appointment.";
            }
        } else {
            $_SESSION['alert_message'] = "Appointment not found.";
        }
        
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Show alert message if set
if (isset($_SESSION['alert_message'])) {
    echo '<div class="alert alert-info alert-dismissible fade show" role="alert">
            ' . $_SESSION['alert_message'] . '
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
          </div>';
    unset($_SESSION['alert_message']);
}

// Main query for appointments - using the new appointments table
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS a.*, 
           p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob,
           v.visit_date as original_visit_date,
           v.visit_type as original_visit_type,
           creator.user_name as created_by_name,
           creator.user_id as creator_id
    FROM appointments a 
    JOIN patients p ON a.patient_id = p.patient_id 
    JOIN visits v ON a.visit_id = v.visit_id
    LEFT JOIN users creator ON a.created_by = creator.user_id
    WHERE DATE(a.appointment_date) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $type_query
      $priority_query
      $doctor_query
    ORDER BY a.appointment_date $order, a.appointment_time $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-calendar-check mr-2"></i>Follow-up Appointments</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#scheduleAppointmentModal">
                <i class="fas fa-plus mr-2"></i>Schedule New Follow-up
            </button>
        </div>
    </div>
    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search patients, MRN, appointment reason..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <a href="?<?php echo $url_query_strings_sort ?>&show_completed=<?php if(isset($_GET['show_completed']) && $_GET['show_completed'] == 1){ echo 0; } else { echo 1; } ?>" 
                                class="btn btn-<?php if (isset($_GET['show_completed']) && $_GET['show_completed'] == 1) { echo "primary"; } else { echo "default"; } ?>">
                                <i class="fa fa-fw fa-check-circle mr-2"></i>Show Completed
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div 
                class="collapse 
                    <?php 
                    if (
                    isset($_GET['dtf'])
                    || $status_filter
                    || $type_filter
                    || $priority_filter
                    || $doctor_id_filter
                    || ($_GET['canned_date'] ?? '') !== "custom" ) 
                    { 
                        echo "show"; 
                    } 
                    ?>
                "
                id="advancedFilter"
            >
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "tomorrow") { echo "selected"; } ?> value="tomorrow">Tomorrow</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "nextweek") { echo "selected"; } ?> value="nextweek">Next Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "nextmonth") { echo "selected"; } ?> value="nextmonth">Next Month</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Appointment Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="scheduled" <?php if ($status_filter == "scheduled") { echo "selected"; } ?>>Scheduled</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
                                <option value="no-show" <?php if ($status_filter == "no-show") { echo "selected"; } ?>>No Show</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Appointment Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php
                                // Get all appointment types
                                $sql_types = mysqli_query($mysqli, "SELECT DISTINCT appointment_type FROM appointments ORDER BY appointment_type ASC");
                                while ($row = mysqli_fetch_array($sql_types)) {
                                    $appointment_type = nullable_htmlentities($row['appointment_type']);
                                ?>
                                    <option <?php if ($appointment_type == $type_filter) { echo "selected"; } ?>><?php echo $appointment_type; ?></option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Priority</label>
                            <select class="form-control select2" name="priority" onchange="this.form.submit()">
                                <option value="">- All Priorities -</option>
                                <option value="routine" <?php if ($priority_filter == "routine") { echo "selected"; } ?>>Routine</option>
                                <option value="urgent" <?php if ($priority_filter == "urgent") { echo "selected"; } ?>>Urgent</option>
                                <option value="emergency" <?php if ($priority_filter == "emergency") { echo "selected"; } ?>>Emergency</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Created By</label>
                            <select class="form-control select2" name="doctor" onchange="this.form.submit()">
                                <option value="">- All Users -</option>
                                <?php
                                // Get all users who created appointments
                                $sql_doctors = mysqli_query($mysqli, "SELECT DISTINCT u.user_id, u.user_name 
                                    FROM users u 
                                    JOIN appointments a ON u.user_id = a.created_by 
                                    WHERE u.user_archived_at IS NULL
                                    ORDER BY u.user_name ASC");
                                while ($row = mysqli_fetch_array($sql_doctors)) {
                                    $doctor_id = intval($row['user_id']);
                                    $doctor_name = nullable_htmlentities($row['user_name']);
                                ?>
                                    <option value="<?php echo $doctor_id; ?>" <?php if ($doctor_id == $doctor_id_filter) { echo "selected"; } ?>>
                                        <?php echo $doctor_name; ?>
                                    </option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0 text-nowrap">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=appointment_date&order=<?php echo $disp; ?>">
                        Date & Time <?php if ($sort == 'appointment_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.patient_first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.patient_first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>MRN</th>
                <th>Type</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Reason</th>
                <th>Created By</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php

            while ($row = mysqli_fetch_array($sql)) {
                $appointment_id = intval($row['appointment_id']);
                $appointment_date = nullable_htmlentities($row['appointment_date']);
                $appointment_time = nullable_htmlentities($row['appointment_time']);
                $appointment_type = nullable_htmlentities($row['appointment_type']);
                $reason = nullable_htmlentities($row['reason']);
                $notes = nullable_htmlentities($row['notes']);
                $priority = nullable_htmlentities($row['priority']);
                $status = nullable_htmlentities($row['status']);
                $reminder_sent = nullable_htmlentities($row['reminder_sent']);
                
                $patient_id = intval($row['patient_id']);
                $patient_first_name = nullable_htmlentities($row['patient_first_name']);
                $patient_last_name = nullable_htmlentities($row['patient_last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_gender = nullable_htmlentities($row['patient_gender']);
                $patient_dob = nullable_htmlentities($row['patient_dob']);
                
                $original_visit_date = nullable_htmlentities($row['original_visit_date']);
                $original_visit_type = nullable_htmlentities($row['original_visit_type']);
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $creator_id = intval($row['creator_id']);

                // Check if current user can modify this appointment
                $can_modify = ($creator_id == $current_user_id || $_SESSION['user_role'] == 'Admin');

                // Status badge styling
                $status_badge = "";
                switch($status) {
                    case 'scheduled':
                        $status_badge = "badge-info";
                        break;
                    case 'completed':
                        $status_badge = "badge-success";
                        break;
                    case 'cancelled':
                        $status_badge = "badge-danger";
                        break;
                    case 'no-show':
                        $status_badge = "badge-secondary";
                        break;
                    default:
                        $status_badge = "badge-light";
                }

                // Priority badge styling
                $priority_badge = "";
                switch($priority) {
                    case 'routine':
                        $priority_badge = "badge-secondary";
                        break;
                    case 'urgent':
                        $priority_badge = "badge-warning";
                        break;
                    case 'emergency':
                        $priority_badge = "badge-danger";
                        break;
                    default:
                        $priority_badge = "badge-light";
                }

                // Calculate patient age from DOB
                $patient_age = "";
                if (!empty($patient_dob)) {
                    $birthDate = new DateTime($patient_dob);
                    $today = new DateTime();
                    $age = $today->diff($birthDate)->y;
                    $patient_age = " ($age yrs)";
                }

                // Format reason for display
                $reason_display = $reason;
                if (strlen($reason_display) > 50) {
                    $reason_display = substr($reason_display, 0, 50) . '...';
                }

                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($appointment_date)); ?></div>
                        <small class="text-muted"><?php echo date('g:i A', strtotime($appointment_time)); ?></small>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $patient_first_name . ' ' . $patient_last_name . $patient_age; ?></div>
                        <small class="text-muted">MRN: <?php echo $patient_mrn; ?></small>
                    </td>
                    <td>
                        <span class="font-weight-bold text-muted"><?php echo $patient_mrn; ?></span>
                    </td>
                    <td>
                        <span class="font-weight-bold"><?php echo $appointment_type; ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $priority_badge; ?>"><?php echo ucfirst($priority); ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($status); ?></span>
                    </td>
                    <td>
                        <div data-toggle="tooltip" title="<?php echo htmlspecialchars($reason); ?>">
                            <?php echo $reason_display; ?>
                        </div>
                    </td>
                    <td>
                        <span class="font-weight-bold <?php echo ($creator_id == $current_user_id) ? 'text-primary' : ''; ?>">
                            <?php echo $created_by_name; ?>
                            <?php if ($creator_id == $current_user_id): ?>
                                <small class="badge badge-primary ml-1">You</small>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#viewAppointmentModal<?php echo $appointment_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                
                                <?php if ($status == 'scheduled' && $can_modify): ?>
                                    <button type="button" class="dropdown-item text-warning" data-toggle="modal" data-target="#rescheduleModal<?php echo $appointment_id; ?>">
                                        <i class="fas fa-fw fa-calendar-alt mr-2"></i>Reschedule
                                    </button>
                                    <button type="button" class="dropdown-item text-danger" data-toggle="modal" data-target="#cancelModal<?php echo $appointment_id; ?>">
                                        <i class="fas fa-fw fa-times mr-2"></i>Cancel
                                    </button>
                                    <div class="dropdown-divider"></div>
                                    <form method="post" class="dropdown-item p-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" name="update_appointment_status" class="dropdown-item text-success">
                                            <i class="fas fa-fw fa-check mr-2"></i>Mark as Completed
                                        </button>
                                    </form>
                                    <form method="post" class="dropdown-item p-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                                        <input type="hidden" name="status" value="no-show">
                                        <button type="submit" name="update_appointment_status" class="dropdown-item text-warning">
                                            <i class="fas fa-fw fa-user-times mr-2"></i>Mark as No Show
                                        </button>
                                    </form>
                                <?php elseif ($status == 'completed' && $can_modify): ?>
                                    <form method="post" class="dropdown-item p-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                                        <input type="hidden" name="status" value="scheduled">
                                        <button type="submit" name="update_appointment_status" class="dropdown-item text-info">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Reopen Appointment
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>

                <!-- View Appointment Modal -->
                <div class="modal fade" id="viewAppointmentModal<?php echo $appointment_id; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Appointment Details</h5>
                                <button type="button" class="close" data-dismiss="modal">
                                    <span>&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Patient Information</h6>
                                        <p><strong>Name:</strong> <?php echo $patient_first_name . ' ' . $patient_last_name; ?></p>
                                        <p><strong>MRN:</strong> <?php echo $patient_mrn; ?></p>
                                        <p><strong>Date of Birth:</strong> <?php echo date('M j, Y', strtotime($patient_dob)); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Appointment Details</h6>
                                        <p><strong>Date & Time:</strong> <?php echo date('M j, Y g:i A', strtotime($appointment_date . ' ' . $appointment_time)); ?></p>
                                        <p><strong>Type:</strong> <?php echo $appointment_type; ?></p>
                                        <p><strong>Priority:</strong> <span class="badge <?php echo $priority_badge; ?>"><?php echo ucfirst($priority); ?></span></p>
                                        <p><strong>Status:</strong> <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($status); ?></span></p>
                                        <p><strong>Reminder Sent:</strong> <?php echo $reminder_sent; ?></p>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <h6>Reason for Follow-up</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <?php echo nl2br(htmlspecialchars($reason)); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($notes)): ?>
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <h6>Additional Notes</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <?php echo nl2br(htmlspecialchars($notes)); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($status == 'cancelled' && !empty($row['cancellation_reason'])): ?>
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <h6>Cancellation Reason</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <?php echo nl2br(htmlspecialchars($row['cancellation_reason'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <p><strong>Original Visit:</strong> <?php echo date('M j, Y', strtotime($original_visit_date)); ?> (<?php echo $original_visit_type; ?>)</p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Created By:</strong> <?php echo $created_by_name; ?> on <?php echo date('M j, Y g:i A', strtotime($row['created_at'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reschedule Modal -->
                <?php if ($status == 'scheduled' && $can_modify): ?>
                <div class="modal fade" id="rescheduleModal<?php echo $appointment_id; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Reschedule Appointment</h5>
                                <button type="button" class="close" data-dismiss="modal">
                                    <span>&times;</span>
                                </button>
                            </div>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label>Patient</label>
                                        <input type="text" class="form-control" value="<?php echo $patient_first_name . ' ' . $patient_last_name; ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>Current Date & Time</label>
                                        <input type="text" class="form-control" value="<?php echo date('M j, Y g:i A', strtotime($appointment_date . ' ' . $appointment_time)); ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="new_date<?php echo $appointment_id; ?>">New Date *</label>
                                        <input type="date" class="form-control" id="new_date<?php echo $appointment_id; ?>" name="new_date" 
                                               min="<?php echo date('Y-m-d'); ?>" value="<?php echo $appointment_date; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="new_time<?php echo $appointment_id; ?>">New Time *</label>
                                        <select class="form-control" id="new_time<?php echo $appointment_id; ?>" name="new_time" required>
                                            <option value="08:00:00" <?php if ($appointment_time == '08:00:00') echo 'selected'; ?>>08:00 AM</option>
                                            <option value="09:00:00" <?php if ($appointment_time == '09:00:00') echo 'selected'; ?>>09:00 AM</option>
                                            <option value="10:00:00" <?php if ($appointment_time == '10:00:00') echo 'selected'; ?>>10:00 AM</option>
                                            <option value="11:00:00" <?php if ($appointment_time == '11:00:00') echo 'selected'; ?>>11:00 AM</option>
                                            <option value="12:00:00" <?php if ($appointment_time == '12:00:00') echo 'selected'; ?>>12:00 PM</option>
                                            <option value="13:00:00" <?php if ($appointment_time == '13:00:00') echo 'selected'; ?>>01:00 PM</option>
                                            <option value="14:00:00" <?php if ($appointment_time == '14:00:00') echo 'selected'; ?>>02:00 PM</option>
                                            <option value="15:00:00" <?php if ($appointment_time == '15:00:00') echo 'selected'; ?>>03:00 PM</option>
                                            <option value="16:00:00" <?php if ($appointment_time == '16:00:00') echo 'selected'; ?>>04:00 PM</option>
                                            <option value="17:00:00" <?php if ($appointment_time == '17:00:00') echo 'selected'; ?>>05:00 PM</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" name="reschedule_appointment" class="btn btn-warning">
                                        <i class="fas fa-calendar-alt mr-2"></i>Reschedule
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cancel Modal -->
                <?php if ($status == 'scheduled' && $can_modify): ?>
                <div class="modal fade" id="cancelModal<?php echo $appointment_id; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Cancel Appointment</h5>
                                <button type="button" class="close" data-dismiss="modal">
                                    <span>&times;</span>
                                </button>
                            </div>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                                <div class="modal-body">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        Are you sure you want to cancel this appointment?
                                    </div>
                                    <div class="form-group">
                                        <label for="cancellation_reason<?php echo $appointment_id; ?>">Cancellation Reason</label>
                                        <textarea class="form-control" id="cancellation_reason<?php echo $appointment_id; ?>" name="cancellation_reason" rows="3" 
                                                  placeholder="Reason for cancellation..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Keep Appointment</button>
                                    <button type="submit" name="cancel_appointment" class="btn btn-danger">
                                        <i class="fas fa-times mr-2"></i>Cancel Appointment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php
            } ?>

            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
    
</div> <!-- End Card -->

<!-- Schedule New Appointment Modal -->
<div class="modal fade" id="scheduleAppointmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Schedule New Follow-up Appointment</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="post/appointment.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="create_appointment" value="1">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Select Patient *</label>
                                <select class="form-control select2" name="patient_id" required>
                                    <option value="">- Select Patient -</option>
                                    <?php
                                    $patients_sql = mysqli_query($mysqli, "SELECT patient_id, patient_first_name, patient_last_name, patient_mrn FROM patients WHERE patient_archived_at IS NULL ORDER BY patient_first_name, patient_last_name");
                                    while ($patient = mysqli_fetch_array($patients_sql)) {
                                        echo "<option value='{$patient['patient_id']}'>{$patient['patient_first_name']} {$patient['patient_last_name']} (MRN: {$patient['patient_mrn']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Related Visit *</label>
                                <select class="form-control select2" name="visit_id" required>
                                    <option value="">- Select Visit -</option>
                                    <?php
                                    $visits_sql = mysqli_query($mysqli, "SELECT visit_id, visit_date, visit_type, patient_id FROM visits WHERE visit_archived_at IS NULL ORDER BY visit_date DESC");
                                    while ($visit = mysqli_fetch_array($visits_sql)) {
                                        $patient_sql = mysqli_query($mysqli, "SELECT patient_first_name, patient_last_name FROM patients WHERE patient_id = {$visit['patient_id']}");
                                        $patient = mysqli_fetch_array($patient_sql);
                                        echo "<option value='{$visit['visit_id']}'>" . date('M j, Y', strtotime($visit['visit_date'])) . " - {$visit['visit_type']} - {$patient['patient_first_name']} {$patient['patient_last_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Appointment Date *</label>
                                <input type="date" class="form-control" name="appointment_date" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Appointment Time *</label>
                                <select class="form-control" name="appointment_time" required>
                                    <option value="08:00:00">08:00 AM</option>
                                    <option value="09:00:00">09:00 AM</option>
                                    <option value="10:00:00">10:00 AM</option>
                                    <option value="11:00:00">11:00 AM</option>
                                    <option value="12:00:00">12:00 PM</option>
                                    <option value="13:00:00">01:00 PM</option>
                                    <option value="14:00:00">02:00 PM</option>
                                    <option value="15:00:00">03:00 PM</option>
                                    <option value="16:00:00">04:00 PM</option>
                                    <option value="17:00:00">05:00 PM</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Appointment Type *</label>
                                <select class="form-control" name="appointment_type" required>
                                    <option value="">- Select Type -</option>
                                    <option value="Follow-up">Follow-up Visit</option>
                                    <option value="Review">Review Appointment</option>
                                    <option value="Procedure">Procedure</option>
                                    <option value="Consultation">Consultation</option>
                                    <option value="Test Results">Test Results Review</option>
                                    <option value="Vaccination">Vaccination</option>
                                    <option value="Therapy">Therapy Session</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Priority</label>
                                <select class="form-control" name="priority">
                                    <option value="routine">Routine</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="emergency">Emergency</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Reason for Follow-up *</label>
                        <textarea class="form-control" name="reason" rows="3" placeholder="Specific reason for follow-up, what to monitor, etc..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Additional Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Special instructions, preparations needed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calendar-plus mr-2"></i>Schedule Follow-up
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Set minimum date for scheduling to today
    $('input[name="appointment_date"]').attr('min', '<?php echo date('Y-m-d'); ?>');
    $('input[name="new_date"]').attr('min', '<?php echo date('Y-m-d'); ?>');

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);

    // Update patient when visit is selected
    $('select[name="visit_id"]').change(function() {
        const visitId = $(this).val();
        if (visitId) {
            // You would need to implement an AJAX call here to get the patient ID from the visit
            // For now, this is a placeholder
            console.log('Visit selected:', visitId);
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// visits.php - General Visits Management
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "visit_datetime";
$order = "DESC";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Status Filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_query = "AND (v.visit_status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    $status_query = "";
    $status_filter = '';
}

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-d', strtotime('-30 days')));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d', strtotime('+30 days')));

// Visit Type Filter
$type_filter = sanitizeInput($_GET['type'] ?? '');
$type_query = $type_filter ? "AND v.visit_type = '$type_filter'" : '';

// Department Filter
$dept_filter = intval($_GET['dept'] ?? 0);
$dept_query = $dept_filter ? "AND v.department_id = $dept_filter" : '';

// Doctor Filter
$doctor_filter = intval($_GET['doctor'] ?? 0);
$doctor_query = $doctor_filter ? "AND v.attending_provider_id = $doctor_filter" : '';

// Search Query
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    $search_query = "AND (p.first_name LIKE '%$q%' OR p.last_name LIKE '%$q%' OR 
                         p.patient_mrn LIKE '%$q%' OR v.visit_number LIKE '%$q%')";
} else {
    $q = '';
    $search_query = '';
}

// Get departments for filter (if you still have a departments table)
// If not, remove this section
$departments_sql = "SELECT department_id, department_name FROM departments 
                    WHERE department_archived_at IS NULL 
                    ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);

// Get doctors for filter (users table - if available)
// If not, remove this section or adjust based on your actual user structure
$doctors_sql = "SELECT user_id, user_name FROM users";
$doctors_result = $mysqli->query($doctors_sql);

// Main query for general visits - UPDATED to only use visits and patients tables
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS v.*,
           p.first_name, p.last_name, p.patient_mrn,
           p.sex, p.date_of_birth, p.phone_primary,
           p.county, p.sub_county, p.ward, p.village,
           p.blood_group
    FROM visits v 
    JOIN patients p ON v.patient_id = p.patient_id
    WHERE DATE(v.visit_datetime) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $type_query
      $dept_query
      $doctor_query
      $search_query
      AND p.patient_status = 'ACTIVE'
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for all visits
$stats_sql = "SELECT 
    COUNT(*) as total_visits,
    SUM(CASE WHEN visit_type = 'OPD' THEN 1 ELSE 0 END) as opd_visits,
    SUM(CASE WHEN visit_type = 'EMERGENCY' THEN 1 ELSE 0 END) as emergency_visits,
    SUM(CASE WHEN visit_type = 'IPD' THEN 1 ELSE 0 END) as ipd_visits,
    SUM(CASE WHEN visit_status = 'ACTIVE' THEN 1 ELSE 0 END) as active_visits,
    SUM(CASE WHEN visit_status = 'CLOSED' THEN 1 ELSE 0 END) as closed_visits,
    COUNT(DISTINCT patient_id) as unique_patients,
    COUNT(DISTINCT attending_provider_id) as unique_doctors
    FROM visits 
    WHERE DATE(visit_datetime) BETWEEN '$dtf' AND '$dtt'";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// If you have departments table, keep this, otherwise remove
$dept_stats_sql = "SELECT 
    d.department_name,
    COUNT(v.visit_id) as visit_count,
    SUM(CASE WHEN v.visit_type = 'OPD' THEN 1 ELSE 0 END) as opd_count,
    SUM(CASE WHEN v.visit_type = 'EMERGENCY' THEN 1 ELSE 0 END) as emergency_count,
    SUM(CASE WHEN v.visit_type = 'IPD' THEN 1 ELSE 0 END) as ipd_count
    FROM visits v
    LEFT JOIN departments d ON v.department_id = d.department_id
    WHERE DATE(v.visit_datetime) BETWEEN '$dtf' AND '$dtt'
    AND d.department_name IS NOT NULL
    GROUP BY d.department_id, d.department_name
    ORDER BY visit_count DESC
    LIMIT 5";
$dept_stats_result = $mysqli->query($dept_stats_sql);
$dept_stats = [];
while ($row = $dept_stats_result->fetch_assoc()) {
    $dept_stats[] = $row;
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-hospital mr-2"></i>All Visits Management</h3>
        <div class="card-tools">
            <?php if (SimplePermission::any("visit_create")) { ?>
            <div class="btn-group">
                <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown">
                    <i class="fas fa-plus mr-2"></i>New 
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="visit_add.php">
                        <i class="fas fa-stethoscope mr-2"></i>Visit
                    </a>
                    <a class="dropdown-item" href="opd_add.php">
                        <i class="fas fa-stethoscope mr-2"></i>OPD Visit
                    </a>
                    <a class="dropdown-item" href="emergency_add.php">
                        <i class="fas fa-ambulance mr-2"></i>Emergency Visit
                    </a>
                    <a class="dropdown-item" href="ipd_add.php">
                        <i class="fas fa-procedures mr-2"></i>IPD Admission
                    </a>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
    
    <!-- Statistics Row for All Visits -->
    <div class="card-header py-2 bg-light">
        <div class="row">
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-dark">
                    <div class="inner">
                        <h3><?php echo $stats['total_visits'] ?? 0; ?></h3>
                        <p>Total Visits</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-hospital"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-primary">
                    <div class="inner">
                        <h3><?php echo $stats['opd_visits'] ?? 0; ?></h3>
                        <p>OPD Visits</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-stethoscope"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $stats['emergency_visits'] ?? 0; ?></h3>
                        <p>Emergency</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-ambulance"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $stats['ipd_visits'] ?? 0; ?></h3>
                        <p>IPD Admissions</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-procedures"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $stats['unique_patients'] ?? 0; ?></h3>
                        <p>Unique Patients</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $stats['unique_doctors'] ?? 0; ?></h3>
                        <p>Doctors Involved</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-user-md"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search all visits, patients, MRN..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter || $type_filter || $dept_filter || $doctor_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
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
                            <label>Visit Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="OPD" <?php if ($type_filter == "OPD") { echo "selected"; } ?>>OPD</option>
                                <option value="EMERGENCY" <?php if ($type_filter == "EMERGENCY") { echo "selected"; } ?>>Emergency</option>
                                <option value="IPD" <?php if ($type_filter == "IPD") { echo "selected"; } ?>>IPD</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="ACTIVE" <?php if ($status_filter == "ACTIVE") { echo "selected"; } ?>>Active</option>
                                <option value="CLOSED" <?php if ($status_filter == "CLOSED") { echo "selected"; } ?>>Closed</option>
                                <option value="CANCELLED" <?php if ($status_filter == "CANCELLED") { echo "selected"; } ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <?php if ($departments_result && $departments_result->num_rows > 0): ?>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Department</label>
                            <select class="form-control select2" name="dept" onchange="this.form.submit()">
                                <option value="">- All Departments -</option>
                                <?php while ($dept_row = $departments_result->fetch_assoc()): ?>
                                    <option value="<?php echo $dept_row['department_id']; ?>" <?php if ($dept_filter == $dept_row['department_id']) { echo "selected"; } ?>>
                                        <?php echo $dept_row['department_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($doctors_result && $doctors_result->num_rows > 0): ?>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Doctor</label>
                            <select class="form-control select2" name="doctor" onchange="this.form.submit()">
                                <option value="">- All Doctors -</option>
                                <?php while ($doctor_row = $doctors_result->fetch_assoc()): ?>
                                    <option value="<?php echo $doctor_row['user_id']; ?>" <?php if ($doctor_filter == $doctor_row['user_id']) { echo "selected"; } ?>>
                                        <?php echo $doctor_row['user_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=v.visit_datetime&order=<?php echo $disp; ?>">
                        Visit Date <?php if ($sort == 'v.visit_datetime') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Visit Details</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Patient Information</th>
                <th>Visit Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if (mysqli_num_rows($sql) > 0) {
                while ($row = mysqli_fetch_array($sql)) {
                    $visit_id = intval($row['visit_id']);
                    $visit_number = nullable_htmlentities($row['visit_number']);
                    $visit_type = nullable_htmlentities($row['visit_type']);
                    $visit_datetime = nullable_htmlentities($row['visit_datetime']);
                    $visit_status = nullable_htmlentities($row['visit_status']);
                    $closed_at = nullable_htmlentities($row['closed_at']);
                    $facility_code = nullable_htmlentities($row['facility_code']);
                    $admission_datetime = nullable_htmlentities($row['admission_datetime']);
                    $discharge_datetime = nullable_htmlentities($row['discharge_datetime']);
                    
                    $patient_first_name = nullable_htmlentities($row['first_name']);
                    $patient_last_name = nullable_htmlentities($row['last_name']);
                    $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                    $patient_gender = nullable_htmlentities($row['sex']);
                    $patient_dob = nullable_htmlentities($row['date_of_birth']);
                    $patient_phone = nullable_htmlentities($row['phone_primary']);
                    $patient_blood_group = nullable_htmlentities($row['blood_group']);
                    $patient_county = nullable_htmlentities($row['county']);

                    // Visit type styling
                    $type_color = '';
                    switch($visit_type) {
                        case 'OPD':
                            $type_color = 'primary';
                            break;
                        case 'EMERGENCY':
                            $type_color = 'danger';
                            break;
                        case 'IPD':
                            $type_color = 'success';
                            break;
                        default:
                            $type_color = 'secondary';
                    }

                    // Visit status styling
                    $status_color = '';
                    switch($visit_status) {
                        case 'ACTIVE':
                            $status_color = 'success';
                            break;
                        case 'CLOSED':
                            $status_color = 'secondary';
                            break;
                        case 'CANCELLED':
                            $status_color = 'danger';
                            break;
                        default:
                            $status_color = 'secondary';
                    }

                    // Calculate patient age
                    $patient_age = "";
                    if (!empty($patient_dob)) {
                        $birthDate = new DateTime($patient_dob);
                        $today = new DateTime();
                        $age = $today->diff($birthDate)->y;
                        $patient_age = " ($age yrs)";
                    }

                    // Build patient name
                    $patient_name = $patient_first_name . ' ' . $patient_last_name;

                    // Format visit datetime
                    $visit_date_formatted = date('M j, Y', strtotime($visit_datetime));
                    $visit_time_formatted = date('g:i A', strtotime($visit_datetime));

                    // Row styling based on visit type
                    $row_class = '';
                    switch($visit_type) {
                        case 'EMERGENCY':
                            $row_class = 'table-danger';
                            break;
                        case 'IPD':
                            $row_class = 'table-success';
                            break;
                    }

                    // Specific detail links
                    $specific_link = '';
                    $specific_text = '';
                    
                    if ($visit_type == 'OPD') {
                        $specific_link = 'opd_details.php?visit_id=' . $visit_id;
                        $specific_text = 'OPD Details';
                    } elseif ($visit_type == 'EMERGENCY') {
                        $specific_link = 'emergency_details.php?visit_id=' . $visit_id;
                        $specific_text = 'Emergency Details';
                    } elseif ($visit_type == 'IPD') {
                        $specific_link = 'ipd_details.php?visit_id=' . $visit_id;
                        $specific_text = 'IPD Details';
                    }
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo $visit_date_formatted; ?></div>
                            <small class="text-muted"><?php echo $visit_time_formatted; ?></small>
                            <?php if ($visit_type == 'IPD' && $admission_datetime): ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-sign-in-alt fa-fw"></i> Admitted: <?php echo date('M j, Y', strtotime($admission_datetime)); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="badge badge-<?php echo $type_color; ?> mb-1"><?php echo $visit_type; ?></span>
                                <small class="text-muted">#<?php echo $visit_number; ?></small>
                                <?php if ($facility_code): ?>
                                    <small class="text-muted">Facility: <?php echo $facility_code; ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $patient_name . $patient_age; ?></div>
                            <small class="text-muted d-block">MRN: <?php echo $patient_mrn; ?></small>
                            <small class="text-muted"><?php echo $patient_gender; ?> • <?php echo $patient_phone; ?></small>
                        </td>
                        <td>
                            <?php if ($patient_blood_group): ?>
                                <div><i class="fas fa-tint fa-fw text-danger mr-1"></i><?php echo $patient_blood_group; ?></div>
                            <?php endif; ?>
                            <?php if ($patient_county): ?>
                                <div><i class="fas fa-map-marker-alt fa-fw text-muted mr-1"></i><?php echo $patient_county; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $status_color; ?>"><?php echo $visit_status; ?></span>
                            <?php if ($closed_at): ?>
                                <br><small class="text-muted">Closed: <?php echo date('M j, Y', strtotime($closed_at)); ?></small>
                            <?php endif; ?>
                            <?php if ($visit_type == 'IPD' && $discharge_datetime): ?>
                                <br><small class="text-muted">Discharged: <?php echo date('M j, Y', strtotime($discharge_datetime)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <?php if (SimplePermission::any("visit_view")): ?>
                                    <a class="dropdown-item" href="visit_details.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Visit
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (SimplePermission::any("visit_edit")): ?>
                                    <a class="dropdown-item" href="visit_edit.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Visit
                                    </a>
                                    <?php endif; ?>
                                    
                               
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <?php if (SimplePermission::any("visit_invoice")): ?>
                                    <a class="dropdown-item text-success" href="invoice_visit.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-print  mr-2"></i>Invoice Visit
                                    </a>
                                    <?php endif; ?>
                                                                       
                                    <?php if (SimplePermission::any("visit_delete")): ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger" href="#" onclick="confirmDeleteVisit(<?php echo $visit_id; ?>)">
                                            <i class="fas fa-fw fa-trash-alt mr-2"></i>Delete Visit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            } else {
                ?>
                <tr>
                    <td colspan="6" class="text-center py-4">
                        <i class="fas fa-hospital fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No visits found</h5>
                        <p class="text-muted">Try adjusting your filters or search criteria</p>
                        <?php if (SimplePermission::any("visit_create")) { ?>
                        <div class="btn-group">
                            <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown">
                                <i class="fas fa-plus mr-2"></i>New Visit
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="opd_add.php">
                                    <i class="fas fa-stethoscope mr-2"></i>OPD Visit
                                </a>
                                <a class="dropdown-item" href="emergency_add.php">
                                    <i class="fas fa-ambulance mr-2"></i>Emergency Visit
                                </a>
                                <a class="dropdown-item" href="ipd_add.php">
                                    <i class="fas fa-procedures mr-2"></i>IPD Admission
                                </a>
                            </div>
                        </div>
                        <?php } ?>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination Footer -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
    
</div> <!-- End Card -->

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
});

function confirmDeleteVisit(visitId) {
    if (confirm('Are you sure you want to delete this visit? This action cannot be undone.')) {
        window.location.href = 'post/visit_actions.php?action=delete&visit_id=' + visitId;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + V for new visit dropdown
    if (e.ctrlKey && e.keyCode === 86) {
        e.preventDefault();
        $('.btn-group .dropdown-toggle').dropdown('toggle');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
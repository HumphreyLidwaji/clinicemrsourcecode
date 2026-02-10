<?php
// ipd.php - IPD Admissions Management
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "admission_datetime";
$order = "DESC";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Status Filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_query = "AND (i.admission_status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    $status_query = "AND i.admission_status = 'ACTIVE'";
    $status_filter = 'ACTIVE';
}

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-d', strtotime('-90 days')));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d', strtotime('+30 days')));

// Admission Type Filter
$type_filter = sanitizeInput($_GET['type'] ?? '');
$type_query = $type_filter ? "AND i.admission_type = '$type_filter'" : '';

// Ward Filter
$ward_filter = intval($_GET['ward'] ?? 0);
$ward_query = $ward_filter ? "AND i.ward_id = $ward_filter" : '';

// Department Filter
$dept_filter = intval($_GET['dept'] ?? 0);
$dept_query = $dept_filter ? "AND v.department_id = $dept_filter" : '';

// Doctor Filter
$doctor_filter = intval($_GET['doctor'] ?? 0);
$doctor_query = $doctor_filter ? "AND i.attending_provider_id = $doctor_filter" : '';

// Search Query
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    $search_query = "AND (p.first_name LIKE '%$q%' OR p.last_name LIKE '%$q%' OR 
                         p.patient_mrn LIKE '%$q%' OR i.admission_number LIKE '%$q%' OR 
                         v.visit_number LIKE '%$q%' OR w.ward_name LIKE '%$q%')";
} else {
    $q = '';
    $search_query = '';
}

// Get departments for filter
$departments_sql = "SELECT department_id, department_name FROM departments 
                    WHERE department_archived_at IS NULL 
                    ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);

// Get doctors for filter
$doctors_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$doctors_result = $mysqli->query($doctors_sql);

// Get wards for filter
$wards_sql = "SELECT ward_id, ward_name FROM wards WHERE ward_archived_at IS NULL ORDER BY ward_name";
$wards_result = $mysqli->query($wards_sql);

// Get unique admission types
$admission_types_sql = "SELECT DISTINCT admission_type FROM ipd_admissions ORDER BY admission_type";
$admission_types_result = $mysqli->query($admission_types_sql);

// Get unique discharge statuses
$discharge_statuses_sql = "SELECT DISTINCT discharge_status FROM ipd_admissions WHERE discharge_status IS NOT NULL ORDER BY discharge_status";
$discharge_statuses_result = $mysqli->query($discharge_statuses_sql);

// Main query for IPD admissions
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS i.*,
           v.visit_number, v.visit_datetime, v.department_id,
           p.first_name, p.last_name, p.patient_mrn,
           p.sex, p.date_of_birth, p.phone_primary,
           d.department_name,
           w.ward_name, b.bed_number,
           u_admit.user_name as admitting_doctor_name,
           u_attend.user_name as attending_doctor_name,
           u_nurse.user_name as nurse_name,
           DATEDIFF(COALESCE(i.discharge_datetime, NOW()), i.admission_datetime) as days_stay
    FROM ipd_admissions i 
    JOIN visits v ON i.visit_id = v.visit_id
    JOIN patients p ON v.patient_id = p.patient_id
    LEFT JOIN departments d ON v.department_id = d.department_id
    LEFT JOIN wards w ON i.ward_id = w.ward_id
    LEFT JOIN beds b ON i.bed_id = b.bed_id
    LEFT JOIN users u_admit ON i.admitting_provider_id = u_admit.user_id
    LEFT JOIN users u_attend ON i.attending_provider_id = u_attend.user_id
    LEFT JOIN users u_nurse ON i.nurse_incharge_id = u_nurse.user_id
    WHERE DATE(i.admission_datetime) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $type_query
      $ward_query
      $dept_query
      $doctor_query
      $search_query
      AND p.patient_status = 'ACTIVE'
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for IPD admissions
$stats_sql = "SELECT 
    COUNT(*) as total_admissions,
    SUM(CASE WHEN admission_status = 'ACTIVE' THEN 1 ELSE 0 END) as active_admissions,
    SUM(CASE WHEN admission_status = 'DISCHARGED' THEN 1 ELSE 0 END) as discharged_admissions,
    SUM(CASE WHEN admission_type = 'EMERGENCY' THEN 1 ELSE 0 END) as emergency_admissions,
    SUM(CASE WHEN admission_type = 'ELECTIVE' THEN 1 ELSE 0 END) as elective_admissions,
    SUM(CASE WHEN admission_type = 'REFERRAL' THEN 1 ELSE 0 END) as referral_admissions,
    SUM(CASE WHEN discharge_status = 'DISCHARGED' THEN 1 ELSE 0 END) as regular_discharges,
    SUM(CASE WHEN discharge_status = 'LAMA' THEN 1 ELSE 0 END) as lama_discharges,
    SUM(CASE WHEN discharge_status = 'DIED' THEN 1 ELSE 0 END) as died_admissions,
    SUM(total_days_admitted) as total_bed_days,
    AVG(total_days_admitted) as avg_length_stay
    FROM ipd_admissions 
    WHERE DATE(admission_datetime) BETWEEN '$dtf' AND '$dtt'";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-procedures mr-2"></i>IPD Admissions Management</h3>
        <div class="card-tools">
            <?php if (SimplePermission::any("visit_create")) { ?>
            <a href="ipd_add.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New IPD Admission
            </a>
            <?php } ?>
        </div>
    </div>
    
    <!-- Statistics Row for IPD -->
    <div class="card-header py-2 bg-light">
        <div class="row">
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $stats['total_admissions'] ?? 0; ?></h3>
                        <p>Total Admissions</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-hospital-user"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $stats['active_admissions'] ?? 0; ?></h3>
                        <p>Active Admissions</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-bed"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $stats['emergency_admissions'] ?? 0; ?></h3>
                        <p>Emergency</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-ambulance"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-primary">
                    <div class="inner">
                        <h3><?php echo $stats['elective_admissions'] ?? 0; ?></h3>
                        <p>Elective</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-secondary">
                    <div class="inner">
                        <h3><?php echo round($stats['avg_length_stay'] ?? 0, 1); ?></h3>
                        <p>Avg. Stay (Days)</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $stats['died_admissions'] ?? 0; ?></h3>
                        <p>Deaths</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-cross"></i>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search admissions, patients, MRN..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter || $type_filter || $ward_filter || $dept_filter || $doctor_filter) { echo "show"; } ?>" id="advancedFilter">
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
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="ACTIVE" <?php if ($status_filter == "ACTIVE") { echo "selected"; } ?>>Active</option>
                                <option value="DISCHARGED" <?php if ($status_filter == "DISCHARGED") { echo "selected"; } ?>>Discharged</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Admission Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php while ($type_row = $admission_types_result->fetch_assoc()): ?>
                                    <option value="<?php echo $type_row['admission_type']; ?>" <?php if ($type_filter == $type_row['admission_type']) { echo "selected"; } ?>>
                                        <?php echo $type_row['admission_type']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Discharge Status</label>
                            <select class="form-control select2" name="discharge" onchange="this.form.submit()">
                                <option value="">- All Discharges -</option>
                                <?php while ($discharge_row = $discharge_statuses_result->fetch_assoc()): ?>
                                    <option value="<?php echo $discharge_row['discharge_status']; ?>" <?php if (isset($_GET['discharge']) && $_GET['discharge'] == $discharge_row['discharge_status']) { echo "selected"; } ?>>
                                        <?php echo $discharge_row['discharge_status']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Ward</label>
                            <select class="form-control select2" name="ward" onchange="this.form.submit()">
                                <option value="">- All Wards -</option>
                                <?php while ($ward_row = $wards_result->fetch_assoc()): ?>
                                    <option value="<?php echo $ward_row['ward_id']; ?>" <?php if ($ward_filter == $ward_row['ward_id']) { echo "selected"; } ?>>
                                        <?php echo $ward_row['ward_name']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Attending Doctor</label>
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
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.admission_datetime&order=<?php echo $disp; ?>">
                        Admission <?php if ($sort == 'i.admission_datetime') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Patient Details</th>
                <th>Ward & Bed</th>
                <th>Admission Details</th>
                <th>Stay Duration</th>
                <th>Status & Outcome</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if (mysqli_num_rows($sql) > 0) {
                while ($row = mysqli_fetch_array($sql)) {
                    $ipd_admission_id = intval($row['ipd_admission_id']);
                    $visit_id = intval($row['visit_id']);
                    $admission_number = nullable_htmlentities($row['admission_number']);
                    $visit_number = nullable_htmlentities($row['visit_number']);
                    $admission_datetime = nullable_htmlentities($row['admission_datetime']);
                    $admission_type = nullable_htmlentities($row['admission_type']);
                    $discharge_datetime = nullable_htmlentities($row['discharge_datetime']);
                    $discharge_status = nullable_htmlentities($row['discharge_status']);
                    $admission_status = nullable_htmlentities($row['admission_status']);
                    $referred_from = nullable_htmlentities($row['referred_from']);
                    $total_days_admitted = intval($row['total_days_admitted']);
                    $days_stay = intval($row['days_stay']);
                    
                    $patient_first_name = nullable_htmlentities($row['first_name']);
                    $patient_last_name = nullable_htmlentities($row['last_name']);
                    $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                    $patient_gender = nullable_htmlentities($row['sex']);
                    $patient_dob = nullable_htmlentities($row['date_of_birth']);
                    $patient_phone = nullable_htmlentities($row['phone_primary']);
                    
                    $department_name = nullable_htmlentities($row['department_name']);
                    $ward_name = nullable_htmlentities($row['ward_name']);
                    $bed_number = nullable_htmlentities($row['bed_number']);
                    $admitting_doctor_name = nullable_htmlentities($row['admitting_doctor_name']);
                    $attending_doctor_name = nullable_htmlentities($row['attending_doctor_name']);
                    $nurse_name = nullable_htmlentities($row['nurse_name']);

                    // Admission status styling
                    $status_color = $admission_status == 'ACTIVE' ? 'success' : 'secondary';
                    
                    // Admission type styling
                    $type_color = '';
                    switch($admission_type) {
                        case 'EMERGENCY':
                            $type_color = 'danger';
                            break;
                        case 'ELECTIVE':
                            $type_color = 'primary';
                            break;
                        case 'REFERRAL':
                            $type_color = 'info';
                            break;
                        default:
                            $type_color = 'secondary';
                    }

                    // Discharge status styling
                    $discharge_color = '';
                    if ($discharge_status) {
                        switch($discharge_status) {
                            case 'DISCHARGED':
                                $discharge_color = 'success';
                                break;
                            case 'REFERRED':
                                $discharge_color = 'warning';
                                break;
                            case 'DIED':
                                $discharge_color = 'danger';
                                break;
                            case 'LAMA':
                                $discharge_color = 'secondary';
                                break;
                            default:
                                $discharge_color = 'info';
                        }
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

                    // Format admission datetime
                    $admission_date_formatted = date('M j, Y', strtotime($admission_datetime));
                    $admission_time_formatted = date('g:i A', strtotime($admission_datetime));

                    // Format discharge datetime if exists
                    $discharge_formatted = '';
                    if ($discharge_datetime) {
                        $discharge_formatted = date('M j, Y', strtotime($discharge_datetime));
                    }

                    // Bed occupancy styling
                    $bed_class = $bed_number ? 'text-primary' : 'text-muted';
                    
                    // Row styling for active vs discharged
                    $row_class = $admission_status == 'ACTIVE' ? 'table-success' : '';
                    if ($discharge_status == 'DIED') {
                        $row_class = 'table-danger';
                    } elseif ($discharge_status == 'LAMA') {
                        $row_class = 'table-secondary';
                    }
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo $admission_date_formatted; ?></div>
                            <small class="text-muted"><?php echo $admission_time_formatted; ?></small>
                            <br>
                            <small class="text-muted">#<?php echo $admission_number; ?></small>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $patient_name . $patient_age; ?></div>
                            <small class="text-muted d-block"><?php echo $patient_mrn; ?></small>
                            <small class="text-muted"><?php echo $patient_gender; ?> • <?php echo $patient_phone; ?></small>
                            <?php if ($department_name): ?>
                                <div><i class="fas fa-hospital fa-fw text-muted mr-1"></i><?php echo $department_name; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ward_name): ?>
                                <div class="font-weight-bold text-primary"><?php echo $ward_name; ?></div>
                                <div class="<?php echo $bed_class; ?>">
                                    <i class="fas fa-bed fa-fw mr-1"></i>
                                    <?php echo $bed_number ? 'Bed ' . $bed_number : 'Bed not assigned'; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">Ward not assigned</span>
                            <?php endif; ?>
                            <?php if ($nurse_name): ?>
                                <div><i class="fas fa-user-nurse fa-fw text-muted mr-1"></i><?php echo $nurse_name; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="badge badge-<?php echo $type_color; ?> mb-1"><?php echo $admission_type; ?></span>
                                <?php if ($referred_from): ?>
                                    <small class="text-muted"><i class="fas fa-exchange-alt fa-fw mr-1"></i><?php echo $referred_from; ?></small>
                                <?php endif; ?>
                                <small class="text-muted">Admitted by: <?php echo $admitting_doctor_name; ?></small>
                                <?php if ($attending_doctor_name): ?>
                                    <small class="text-muted">Attending: <?php echo $attending_doctor_name; ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $days_stay; ?> days</div>
                            <small class="text-muted">Total: <?php echo $total_days_admitted; ?> days</small>
                            <?php if ($discharge_formatted): ?>
                                <div class="text-muted">Discharged: <?php echo $discharge_formatted; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $status_color; ?>"><?php echo $admission_status; ?></span>
                            <?php if ($discharge_status): ?>
                                <br>
                                <span class="badge badge-<?php echo $discharge_color; ?> mt-1"><?php echo $discharge_status; ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <?php if (SimplePermission::any("visit_view")): ?>
                                    <a class="dropdown-item" href="ipd_details.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>IPD Details
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (SimplePermission::any("visit_view")): ?>
                                    <a class="dropdown-item" href="visit_details.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-file-medical mr-2"></i>General Visit
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (SimplePermission::any("visit_edit") && $admission_status == 'ACTIVE'): ?>
                                    <a class="dropdown-item" href="ipd_edit.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Admission
                                    </a>
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <?php if (SimplePermission::any("visit_print")): ?>
                                    <a class="dropdown-item" href="ipd_print.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>" target="_blank">
                                        <i class="fas fa-fw fa-print mr-2"></i>Print IPD Card
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($admission_status == 'ACTIVE'): ?>
                                        <?php if (SimplePermission::any("visit_edit")): ?>
                                        <a class="dropdown-item text-success" href="post/ipd_actions.php?action=transfer&ipd_admission_id=<?php echo $ipd_admission_id; ?>">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Transfer Ward/Bed
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if (SimplePermission::any("visit_edit")): ?>
                                        <a class="dropdown-item text-warning" href="post/ipd_actions.php?action=discharge&ipd_admission_id=<?php echo $ipd_admission_id; ?>">
                                            <i class="fas fa-fw fa-sign-out-alt mr-2"></i>Discharge Patient
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <?php if (SimplePermission::any("visit_delete")): ?>
                                        <a class="dropdown-item text-danger" href="#" onclick="confirmDeleteIpd(<?php echo $ipd_admission_id; ?>)">
                                            <i class="fas fa-fw fa-trash-alt mr-2"></i>Delete Admission
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
                    <td colspan="7" class="text-center py-4">
                        <i class="fas fa-procedures fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No IPD admissions found</h5>
                        <p class="text-muted">Try adjusting your filters or search criteria</p>
                        <?php if (SimplePermission::any("visit_create")) { ?>
                        <a href="ipd_add.php" class="btn btn-success">
                            <i class="fas fa-plus mr-2"></i>New IPD Admission
                        </a>
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

function confirmDeleteIpd(ipdAdmissionId) {
    if (confirm('Are you sure you want to delete this IPD admission? This action cannot be undone.')) {
        window.location.href = 'post/ipd_actions.php?action=delete&ipd_admission_id=' + ipdAdmissionId;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + I for new IPD admission
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        window.location.href = 'ipd_add.php';
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
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "sc.surgery_date";
$order = "DESC";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';
$theatre_filter = $_GET['theatre'] ?? '';
$complication_filter = $_GET['complication'] ?? '';
$surgeon_filter = $_GET['surgeon'] ?? '';
$urgency_filter = $_GET['urgency'] ?? '';
$specialty_filter = $_GET['specialty'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND sc.surgery_date BETWEEN '$dtf' AND '$dtt'";
} elseif (!empty($date_filter)) {
    $date_query = "AND sc.surgery_date = '$date_filter'";
} else {
    $date_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND sc.case_status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Theatre Filter
if ($theatre_filter) {
    $theatre_query = "AND sc.theater_id = " . intval($theatre_filter);
} else {
    $theatre_query = '';
}

// Complication Filter
if ($complication_filter == 'has_complications') {
    $complication_query = "AND EXISTS (SELECT 1 FROM surgical_complications scc WHERE scc.case_id = sc.case_id AND scc.complication_status != 'resolved')";
} elseif ($complication_filter == 'no_complications') {
    $complication_query = "AND NOT EXISTS (SELECT 1 FROM surgical_complications scc WHERE scc.case_id = sc.case_id)";
} else {
    $complication_query = '';
}

// Surgeon Filter
if ($surgeon_filter) {
    $surgeon_query = "AND sc.primary_surgeon_id = " . intval($surgeon_filter);
} else {
    $surgeon_query = '';
}

// Urgency Filter
if ($urgency_filter) {
    $urgency_query = "AND sc.surgical_urgency = '" . sanitizeInput($urgency_filter) . "'";
} else {
    $urgency_query = '';
}

// Specialty Filter
if ($specialty_filter) {
    $specialty_query = "AND sc.surgical_specialty = '" . sanitizeInput($specialty_filter) . "'";
} else {
    $specialty_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        p.first_name LIKE '%$q%' 
        OR p.last_name LIKE '%$q%'
        OR p.patient_mrn LIKE '%$q%'
        OR sc.case_number LIKE '%$q%'
        OR sc.planned_procedure LIKE '%$q%'
        OR sc.pre_op_diagnosis LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR t.theatre_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Get complication statistics
$complication_stats_sql = "
    SELECT 
        COUNT(DISTINCT sc.case_id) as total_cases,
        SUM(CASE WHEN scc.complication_id IS NOT NULL THEN 1 ELSE 0 END) as cases_with_complications,
        SUM(CASE WHEN scc.complication_id IS NOT NULL AND scc.complication_status = 'active' THEN 1 ELSE 0 END) as active_complications
    FROM surgical_cases sc
    LEFT JOIN surgical_complications scc ON sc.case_id = scc.case_id AND scc.complication_status != 'resolved'
    WHERE 1=1
    $date_query
    $theatre_query
    $status_query
    $urgency_query
    $specialty_query
";
$complication_stats_result = $mysqli->query($complication_stats_sql);
$complication_stats = $complication_stats_result->fetch_assoc();

// Get equipment usage statistics (if your new schema has equipment)
$equipment_stats_sql = "
    SELECT 
        COUNT(DISTINCT seu.asset_id) as equipment_used,
        COUNT(seu.usage_id) as equipment_usage_instances
    FROM surgical_cases sc
    LEFT JOIN surgical_equipment_usage seu ON sc.case_id = seu.case_id
    WHERE 1=1
    $date_query
    $theatre_query
    $status_query
    $urgency_query
    $specialty_query
";
$equipment_stats_result = $mysqli->query($equipment_stats_sql);
$equipment_stats = $equipment_stats_result->fetch_assoc();

// Main query for surgical cases - FIXED field names
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS sc.*, 
           p.first_name, p.last_name, p.patient_mrn, p.sex as patient_gender, p.date_of_birth,
           ps.user_name as surgeon_name,
           a.user_name as anesthetist_name,
           rd.user_name as referring_doctor_name,
           t.theatre_name, t.theatre_number,
           creator.user_name as created_by_name,
           TIMESTAMPDIFF(MINUTE, sc.surgery_start_time, sc.surgery_end_time) as actual_duration,
           DATEDIFF(CURDATE(), sc.surgery_date) as days_ago,
           (SELECT COUNT(*) FROM surgical_complications scc WHERE scc.case_id = sc.case_id AND scc.complication_status != 'resolved') as complication_count,
           (SELECT COUNT(*) FROM patient_files pf WHERE pf.file_related_type = 'surgical_case' AND pf.file_related_id = sc.case_id AND pf.file_archived_at IS NULL) as document_count,
           (SELECT COUNT(*) FROM surgical_team st WHERE st.case_id = sc.case_id) as team_member_count,
           (SELECT COUNT(*) FROM anesthesia_records ar WHERE ar.case_id = sc.case_id) as has_anesthesia_record
    FROM surgical_cases sc
    LEFT JOIN patients p ON sc.patient_id = p.patient_id
    LEFT JOIN users ps ON sc.primary_surgeon_id = ps.user_id
    LEFT JOIN users a ON sc.anesthetist_id = a.user_id
    LEFT JOIN users rd ON sc.referring_doctor_id = rd.user_id
    LEFT JOIN theatres t ON sc.theater_id = t.theatre_id
    LEFT JOIN users creator ON sc.created_by = creator.user_id
    LEFT JOIN users u ON sc.primary_surgeon_id = u.user_id
    WHERE 1=1
      $date_query
      $theatre_query
      $status_query
      $surgeon_query
      $urgency_query
      $specialty_query
      $complication_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get theatres for filter
$theatres_sql = "SELECT * FROM theatres WHERE is_active = 1 AND archived_at IS NULL ORDER BY theatre_number";
$theatres_result = $mysqli->query($theatres_sql);

// Get surgeons for filter
$surgeons_sql = "SELECT user_id, user_name FROM users";
$surgeons_result = $mysqli->query($surgeons_sql);

// Get unique specialties for filter
$specialties_sql = "SELECT DISTINCT surgical_specialty FROM surgical_cases WHERE surgical_specialty IS NOT NULL AND surgical_specialty != '' ORDER BY surgical_specialty";
$specialties_result = $mysqli->query($specialties_sql);

// Get statistics
$total_cases = $num_rows[0];
$completed_count = 0;
$cancelled_count = 0;
$scheduled_count = 0;
$referred_count = 0;
$in_or_count = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($case = mysqli_fetch_assoc($sql)) {
    switch($case['case_status']) {
        case 'completed':
            $completed_count++;
            break;
        case 'cancelled':
            $cancelled_count++;
            break;
        case 'scheduled':
            $scheduled_count++;
            break;
        case 'referred':
            $referred_count++;
            break;
        case 'in_or':
            $in_or_count++;
            break;
    }
}
mysqli_data_seek($sql, $record_from);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="row">
            <div class="col-md-8">
                <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-procedures mr-2"></i>Surgical Cases Dashboard</h3>
            </div>
            <div class="col-md-4 text-right">
                <span class="badge badge-light mr-2">
                    <i class="fas fa-clipboard-list mr-1"></i> Total: <?php echo $total_cases; ?>
                </span>
                <span class="badge badge-danger mr-2">
                    <i class="fas fa-exclamation-triangle mr-1"></i> Complications: <?php echo $complication_stats['cases_with_complications'] ?? 0; ?>
                </span>
                <span class="badge badge-info">
                    <i class="fas fa-tools mr-1"></i> Equipment Used: <?php echo $equipment_stats['equipment_used'] ?? 0; ?>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats Bar -->
    <div class="card-body py-2 border-bottom">
        <div class="row">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-receipt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Referred</span>
                        <span class="info-box-number"><?php echo $referred_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Scheduled</span>
                        <span class="info-box-number"><?php echo $scheduled_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-running"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">In OR</span>
                        <span class="info-box-number"><?php echo $in_or_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed</span>
                        <span class="info-box-number"><?php echo $completed_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">With Complications</span>
                        <span class="info-box-number"><?php echo $complication_stats['cases_with_complications'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-file-medical-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Documents</span>
                        <?php 
                        $total_docs_sql = "SELECT COUNT(*) as total_docs FROM patient_files WHERE file_related_type = 'surgical_case' AND file_archived_at IS NULL";
                        $total_docs_result = $mysqli->query($total_docs_sql);
                        $total_docs = $total_docs_result->fetch_assoc();
                        ?>
                        <span class="info-box-number"><?php echo $total_docs['total_docs'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Filter Buttons -->
    <div class="card-body py-3 border-bottom">
        <div class="row">
            <div class="col-md-12">
                <div class="btn-toolbar form-group">
                    <div class="btn-group btn-group-toggle" data-toggle="buttons">
                        <a href="surgical_cases_dashboard.php" class="btn btn-outline-primary <?php if(!$status_filter) echo 'active'; ?>">
                            <i class="fas fa-list mr-2"></i>All <span class="badge badge-primary"><?php echo $total_cases; ?></span>
                        </a>
                        <a href="?status=referred" class="btn btn-outline-info <?php if($status_filter == 'referred') echo 'active'; ?>">
                            <i class="fas fa-receipt mr-2"></i>Referred <span class="badge badge-info"><?php echo $referred_count; ?></span>
                        </a>
                        <a href="?status=scheduled" class="btn btn-outline-primary <?php if($status_filter == 'scheduled') echo 'active'; ?>">
                            <i class="fas fa-clock mr-2"></i>Scheduled <span class="badge badge-primary"><?php echo $scheduled_count; ?></span>
                        </a>
                        <a href="?status=in_or" class="btn btn-outline-warning <?php if($status_filter == 'in_or') echo 'active'; ?>">
                            <i class="fas fa-running mr-2"></i>In OR <span class="badge badge-warning"><?php echo $in_or_count; ?></span>
                        </a>
                        <a href="?status=completed" class="btn btn-outline-success <?php if($status_filter == 'completed') echo 'active'; ?>">
                            <i class="fas fa-check mr-2"></i>Completed <span class="badge badge-success"><?php echo $completed_count; ?></span>
                        </a>
                        <a href="?status=cancelled" class="btn btn-outline-danger <?php if($status_filter == 'cancelled') echo 'active'; ?>">
                            <i class="fas fa-times mr-2"></i>Cancelled <span class="badge badge-danger"><?php echo $cancelled_count; ?></span>
                        </a>
                        <a href="?complication=has_complications" class="btn btn-outline-danger <?php if($complication_filter == 'has_complications') echo 'active'; ?>">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Complications <span class="badge badge-danger"><?php echo $complication_stats['active_complications'] ?? 0; ?></span>
                        </a>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search patients, surgeons, procedures..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                        
                            <div class="btn-group">
                                <button type="button" class="btn btn-info dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-cog mr-2"></i>Quick Actions
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                   <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="ot_management.php"><i class="fas fa-hospital mr-2"></i>Manage OTs</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="reports_complications.php"><i class="fas fa-chart-line mr-2"></i>Complication Reports</a>
                                    <a class="dropdown-item" href="reports_equipment_usage.php"><i class="fas fa-chart-bar mr-2"></i>Equipment Reports</a>
                                    <a class="dropdown-item" href="reports_anesthesia.php"><i class="fas fa-chart-pie mr-2"></i>Anesthesia Reports</a>
                                </div>
                            </div>
                            <a href="surgical_case_new.php" class="btn btn-success">
                                <i class="fas fa-fw fa-plus mr-2"></i>New Surgical Case
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter || $theatre_filter || $complication_filter || $surgeon_filter || $urgency_filter || $specialty_filter) { echo "show"; } ?>" id="advancedFilter">
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
                                <option value="">- All Statuses -</option>
                                <option value="referred" <?php if ($status_filter == "referred") { echo "selected"; } ?>>Referred</option>
                                <option value="scheduled" <?php if ($status_filter == "scheduled") { echo "selected"; } ?>>Scheduled</option>
                                <option value="in_or" <?php if ($status_filter == "in_or") { echo "selected"; } ?>>In OR</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Urgency</label>
                            <select class="form-control select2" name="urgency" onchange="this.form.submit()">
                                <option value="">- All Urgencies -</option>
                                <option value="emergency" <?php if ($urgency_filter == "emergency") { echo "selected"; } ?>>Emergency</option>
                                <option value="urgent" <?php if ($urgency_filter == "urgent") { echo "selected"; } ?>>Urgent</option>
                                <option value="elective" <?php if ($urgency_filter == "elective") { echo "selected"; } ?>>Elective</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Theatre</label>
                            <select class="form-control select2" name="theatre" onchange="this.form.submit()">
                                <option value="">- All Theatres -</option>
                                <?php while($theatre = $theatres_result->fetch_assoc()): ?>
                                    <option value="<?php echo $theatre['theatre_id']; ?>" <?php if ($theatre_filter == $theatre['theatre_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($theatre['theatre_number'] . ' - ' . $theatre['theatre_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Specialty</label>
                            <select class="form-control select2" name="specialty" onchange="this.form.submit()">
                                <option value="">- All Specialties -</option>
                                <?php while($specialty = $specialties_result->fetch_assoc()): ?>
                                    <option value="<?php echo $specialty['surgical_specialty']; ?>" <?php if ($specialty_filter == $specialty['surgical_specialty']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($specialty['surgical_specialty']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Surgeon</label>
                            <select class="form-control select2" name="surgeon" onchange="this.form.submit()">
                                <option value="">- All Surgeons -</option>
                                <?php 
                                // Reset pointer for surgeons
                                mysqli_data_seek($surgeons_result, 0);
                                while($surgeon = $surgeons_result->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $surgeon['user_id']; ?>" <?php if ($surgeon_filter == $surgeon['user_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($surgeon['user_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Complications</label>
                            <select class="form-control select2" name="complication" onchange="this.form.submit()">
                                <option value="">- All -</option>
                                <option value="has_complications" <?php if ($complication_filter == "has_complications") { echo "selected"; } ?>>With Complications</option>
                                <option value="no_complications" <?php if ($complication_filter == "no_complications") { echo "selected"; } ?>>No Complications</option>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=sc.surgery_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'sc.surgery_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=sc.case_number&order=<?php echo $disp; ?>">
                        Case # <?php if ($sort == 'sc.case_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=sc.planned_procedure&order=<?php echo $disp; ?>">
                        Procedure <?php if ($sort == 'sc.planned_procedure') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Team & Info</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=sc.case_status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'sc.case_status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Documentation</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_array($sql)) {
                $case_id = intval($row['case_id']);
                $case_number = nullable_htmlentities($row['case_number']);
                $patient_first_name = nullable_htmlentities($row['first_name']); 
                $patient_last_name = nullable_htmlentities($row['last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_gender = nullable_htmlentities($row['patient_gender']);
                $surgeon_name = nullable_htmlentities($row['surgeon_name']);
                $anesthetist_name = nullable_htmlentities($row['anesthetist_name']);
                $referring_doctor_name = nullable_htmlentities($row['referring_doctor_name']);
                $theatre_name = nullable_htmlentities($row['theatre_name']);
                $theatre_number = nullable_htmlentities($row['theatre_number']);
                $planned_procedure = nullable_htmlentities($row['planned_procedure']);
                $pre_op_diagnosis = nullable_htmlentities($row['pre_op_diagnosis']);
                $surgery_date = nullable_htmlentities($row['surgery_date']);
                $surgery_start_time = nullable_htmlentities($row['surgery_start_time']);
                $surgery_end_time = nullable_htmlentities($row['surgery_end_time']);
                $case_status = nullable_htmlentities($row['case_status']);
                $surgical_urgency = nullable_htmlentities($row['surgical_urgency']);
                $asa_score = intval($row['asa_score']);
                $surgical_specialty = nullable_htmlentities($row['surgical_specialty']);
                $estimated_duration_minutes = intval($row['estimated_duration_minutes']);
                $actual_duration = intval($row['actual_duration']);
                $days_ago = intval($row['days_ago']);
                $complication_count = intval($row['complication_count']);
                $document_count = intval($row['document_count']);
                $team_member_count = intval($row['team_member_count']);
                $has_anesthesia_record = intval($row['has_anesthesia_record']);

                // Status badge styling
                $status_badge = '';
                switch($case_status) {
                    case 'referred':
                        $status_badge = 'badge-info';
                        break;
                    case 'scheduled':
                        $status_badge = 'badge-primary';
                        break;
                    case 'in_or':
                        $status_badge = 'badge-warning';
                        break;
                    case 'completed':
                        $status_badge = 'badge-success';
                        break;
                    case 'cancelled':
                        $status_badge = 'badge-danger';
                        break;
                    default:
                        $status_badge = 'badge-secondary';
                }

                // Urgency badge styling
                $urgency_badge = '';
                switch($surgical_urgency) {
                    case 'emergency':
                        $urgency_badge = 'badge-danger';
                        break;
                    case 'urgent':
                        $urgency_badge = 'badge-warning';
                        break;
                    case 'elective':
                        $urgency_badge = 'badge-info';
                        break;
                    default:
                        $urgency_badge = 'badge-secondary';
                }

                // Row styling based on status and recency
                $row_class = '';
                if ($case_status == 'completed' && $days_ago <= 7) {
                    $row_class = 'table-success';
                } elseif ($case_status == 'cancelled') {
                    $row_class = 'table-danger';
                } elseif ($case_status == 'in_or') {
                    $row_class = 'table-warning';
                } elseif ($surgical_urgency == 'emergency') {
                    $row_class = 'table-danger';
                } elseif ($complication_count > 0) {
                    $row_class = 'table-danger';
                } elseif ($case_status == 'referred') {
                    $row_class = 'table-info';
                }
                ?>
                <tr class="<?php echo $row_class; ?>">
                    <td>
                        <?php if($surgery_date): ?>
                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($surgery_date)); ?></div>
                            <?php if($surgery_start_time): ?>
                                <small class="text-muted"><?php echo date('H:i', strtotime($surgery_start_time)); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="font-weight-bold text-muted">Not scheduled</div>
                        <?php endif; ?>
                    </td>
                    <td class="font-weight-bold text-primary"><?php echo $case_number; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo $patient_first_name . ' ' . $patient_last_name; ?></div>
                        <small class="text-muted"><?php echo $patient_mrn; ?> • <?php echo $patient_gender; ?></small>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $planned_procedure; ?></div>
                        <?php if($pre_op_diagnosis): ?>
                            <small class="text-muted">Dx: <?php echo substr($pre_op_diagnosis, 0, 50); ?><?php if(strlen($pre_op_diagnosis) > 50) echo '...'; ?></small>
                        <?php endif; ?>
                        <?php if($estimated_duration_minutes): ?>
                            <small class="text-muted d-block">Est: <?php echo $estimated_duration_minutes; ?> min</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?php echo $surgeon_name; ?></div>
                        <div class="small">
                            <span class="badge <?php echo $urgency_badge; ?> mr-1"><?php echo ucfirst($surgical_urgency); ?></span>
                            <?php if($asa_score): ?>
                                <span class="badge badge-secondary mr-1">ASA <?php echo $asa_score; ?></span>
                            <?php endif; ?>
                            <?php if($team_member_count > 0): ?>
                                <span class="badge badge-info mr-1"><i class="fas fa-users mr-1"></i><?php echo $team_member_count; ?></span>
                            <?php endif; ?>
                            <?php if($has_anesthesia_record): ?>
                                <span class="badge badge-success"><i class="fas fa-syringe mr-1"></i>Anesthesia</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo strtoupper(str_replace('_', ' ', $case_status)); ?></span>
                        <?php if($complication_count > 0): ?>
                            <div class="mt-1">
                                <span class="badge badge-danger">
                                    <i class="fas fa-exclamation-triangle mr-1"></i><?php echo $complication_count; ?> Complication<?php echo $complication_count > 1 ? 's' : ''; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if($document_count > 0): ?>
                                <span class="badge badge-info">
                                    <i class="fas fa-file-alt mr-1"></i><?php echo $document_count; ?>
                                </span>
                            <?php endif; ?>
                            <?php if($has_anesthesia_record): ?>
                                <span class="badge badge-success">
                                    <i class="fas fa-file-medical mr-1"></i>Anesthesia
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-lg">
                                <!-- View Section -->
                                <a class="dropdown-item" href="surgical_case_view.php?id=<?php echo $case_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Case Details
                                </a>
                                
                                <?php if($case_status == 'completed'): ?>
                                    <!-- Documentation Section -->
                                    <div class="dropdown-divider"></div>
                                    <h6 class="dropdown-header">Documentation</h6>
                                    <a class="dropdown-item" href="surgical_documents.php?case_id=<?php echo $case_id; ?>">
                                        <i class="fas fa-fw fa-file-medical-alt mr-2"></i>Surgical Documents (<?php echo $document_count; ?>)
                                    </a>
                                    <a class="dropdown-item" href="surgical_team_management.php?case_id=<?php echo $case_id; ?>">
                                        <i class="fas fa-fw fa-user-md mr-2"></i>Surgical Team (<?php echo $team_member_count; ?>)
                                    </a>
                                    
                                    <?php if($has_anesthesia_record): ?>
                                        <a class="dropdown-item" href="anesthesia_records_view.php?case_id=<?php echo $case_id; ?>">
                                            <i class="fas fa-fw fa-syringe mr-2"></i>View Anesthesia Record
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item" href="anesthesia_records_new.php?case_id=<?php echo $case_id; ?>">
                                            <i class="fas fa-fw fa-syringe mr-2"></i>Add Anesthesia Record
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a class="dropdown-item" href="surgical_equipment_usage.php?case_id=<?php echo $case_id; ?>">
                                        <i class="fas fa-fw fa-tools mr-2"></i>Equipment Usage
                                    </a>
                                    <a class="dropdown-item" href="surgical_inventory_usage.php?case_id=<?php echo $case_id; ?>">
                                        <i class="fas fa-fw fa-boxes mr-2"></i>Inventory Usage
                                    </a>
                                    
                                    <!-- Complications Section -->
                                    <div class="dropdown-divider"></div>
                                    <h6 class="dropdown-header">Complications</h6>
                                    <?php if($complication_count > 0): ?>
                                        <a class="dropdown-item text-warning" href="surgical_complications.php?case_id=<?php echo $case_id; ?>">
                                            <i class="fas fa-fw fa-exclamation-triangle mr-2"></i>View Complications (<?php echo $complication_count; ?>)
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item" href="surgical_complications_new.php?case_id=<?php echo $case_id; ?>">
                                            <i class="fas fa-fw fa-exclamation-triangle mr-2"></i>Add Complication
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Edit/Management Section -->
                                <div class="dropdown-divider"></div>
                                <?php if($case_status == 'referred' || $case_status == 'scheduled'): ?>
                                    <a class="dropdown-item" href="surgical_case_edit.php?id=<?php echo $case_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Case
                                    </a>
                                <?php endif; ?>
                                <?php if($case_status == 'referred'): ?>
                                    <a class="dropdown-item text-success" href="surgical_case_view.php?id=<?php echo $case_id; ?>#schedule">
                                        <i class="fas fa-fw fa-calendar-check mr-2"></i>Schedule Case
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Reports Section -->
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="surgical_case_print.php?id=<?php echo $case_id; ?>">
                                    <i class="fas fa-fw fa-print mr-2"></i>Print Details
                                </a>
                                <a class="dropdown-item" href="reports_case_detail.php?id=<?php echo $case_id; ?>">
                                    <i class="fas fa-fw fa-chart-bar mr-2"></i>Case Report
                                </a>
                                
                                <!-- Duplicate/Cancel Section -->
                                <div class="dropdown-divider"></div>
                                <?php if($case_status == 'completed'): ?>
                                    <a class="dropdown-item" href="post.php?duplicate_case=<?php echo $case_id; ?>">
                                        <i class="fas fa-fw fa-copy mr-2"></i>Duplicate Case
                                    </a>
                                <?php endif; ?>
                                <?php if($case_status == 'scheduled'): ?>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?cancel_case=<?php echo $case_id; ?>">
                                        <i class="fas fa-fw fa-times mr-2"></i>Cancel Case
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
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

<!-- Quick Stats Modal -->
<div class="modal fade" id="quickStatsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white"><i class="fas fa-chart-bar mr-2"></i>Surgical Case Statistics</h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning">
                                <h6 class="mb-0 text-white"><i class="fas fa-exclamation-triangle mr-2"></i>Complications Overview</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $complication_stats_sql = "
                                    SELECT 
                                        scc.severity,
                                        COUNT(*) as count
                                    FROM surgical_complications scc
                                    JOIN surgical_cases sc ON scc.case_id = sc.case_id
                                    WHERE 1=1
                                    GROUP BY scc.severity
                                    ORDER BY FIELD(scc.severity, 'critical', 'severe', 'moderate', 'minor')
                                ";
                                $complication_stats_result = $mysqli->query($complication_stats_sql);
                                ?>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Severity</th>
                                            <th>Count</th>
                                            <th>%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($stat = $complication_stats_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="badge badge-<?php 
                                                switch($stat['severity']) {
                                                    case 'critical': echo 'danger'; break;
                                                    case 'severe': echo 'danger'; break;
                                                    case 'moderate': echo 'warning'; break;
                                                    case 'minor': echo 'info'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>"><?php echo ucfirst($stat['severity']); ?></span></td>
                                            <td><?php echo $stat['count']; ?></td>
                                            <td><?php echo $total_cases > 0 ? number_format(($stat['count'] / $total_cases) * 100, 1) : '0'; ?>%</td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info">
                                <h6 class="mb-0 text-white"><i class="fas fa-tools mr-2"></i>Equipment Usage</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $equipment_sql = "
                                    SELECT 
                                        COUNT(DISTINCT seu.equipment_id) as equipment_count,
                                        COUNT(seu.usage_id) as usage_count
                                    FROM surgical_equipment_usage seu
                                    JOIN surgical_cases sc ON seu.case_id = sc.case_id
                                    WHERE 1=1
                                ";
                                $equipment_result = $mysqli->query($equipment_sql);
                                $equipment = $equipment_result->fetch_assoc();
                                ?>
                                <div class="text-center">
                                    <h3><?php echo $equipment['equipment_count']; ?></h3>
                                    <p class="text-muted">Unique Equipment Used</p>
                                    <hr>
                                    <h3><?php echo $equipment['usage_count']; ?></h3>
                                    <p class="text-muted">Total Usage Instances</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });
    
    // Confirm action links
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action? This cannot be undone.')) {
            e.preventDefault();
        }
    });

    // View complication details on click
    $('.complication-badge').click(function(e) {
        e.preventDefault();
        var caseId = $(this).data('case-id');
        $('#complicationDetailsModal').modal('show');
        // Load complication details via AJAX
        $.get('ajax/get_complications.php?case_id=' + caseId, function(data) {
            $('#complicationDetailsContent').html(data);
        });
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new case
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'surgical_case_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + D for dashboard
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        window.location.href = 'surgical_cases_dashboard.php';
    }
    // Ctrl + R to refresh
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        window.location.reload();
    }
    // Ctrl + S for stats
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#quickStatsModal').modal('show');
    }
});
</script>

<style>
.info-box {
    transition: transform 0.2s ease-in-out;
    border: 1px solid #e3e6f0;
    cursor: pointer;
    min-height: 70px;
}
.info-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.info-box-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    font-size: 1.5rem;
}
.info-box-content {
    padding: 5px 10px;
}
.info-box-text {
    font-size: 0.8rem;
    text-transform: uppercase;
    font-weight: bold;
    color: #6c757d;
}
.info-box-number {
    font-size: 1.5rem;
    font-weight: bold;
    color: #343a40;
}
.table-success {
    background-color: #d4edda !important;
}
.table-danger {
    background-color: #f8d7da !important;
}
.table-warning {
    background-color: #fff3cd !important;
}
.table-info {
    background-color: #d1ecf1 !important;
}
.complication-badge {
    cursor: pointer;
}
.dropdown-menu-lg {
    min-width: 350px;
}
.gap-1 {
    gap: 4px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
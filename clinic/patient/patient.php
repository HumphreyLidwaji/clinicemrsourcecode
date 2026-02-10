<?php
// patients.php - Patient Management Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "created_at";
$order = "DESC";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Status Filter
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_query = "AND (p.patient_status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    // Default - active patients
    $status_query = "AND p.patient_status = 'ACTIVE'";
    $status_filter = 'ACTIVE';
}

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-d', strtotime('-30 days')));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d', strtotime('+30 days')));

// Gender Filter
$gender_filter = sanitizeInput($_GET['gender'] ?? '');
$gender_query = $gender_filter ? "AND p.sex = '$gender_filter'" : '';

// County Filter
$county_filter = sanitizeInput($_GET['county'] ?? '');
$county_query = $county_filter ? "AND p.county = '$county_filter'" : '';

// Search Query
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    $search_query = "AND (p.first_name LIKE '%$q%' OR p.last_name LIKE '%$q%' OR p.patient_mrn LIKE '%$q%' OR p.phone_primary LIKE '%$q%' OR p.email LIKE '%$q%' OR p.id_number LIKE '%$q%')";
} else {
    $q = '';
    $search_query = '';
}

// Get unique counties for filter
$counties_sql = "SELECT DISTINCT county FROM patients WHERE county IS NOT NULL AND county != '' ORDER BY county";
$counties_result = $mysqli->query($counties_sql);

// Main query for patients
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS p.*,
           k.full_name as kin_name,
           k.relationship as kin_relationship,
           k.phone as kin_phone,
           COUNT(v.visit_id) as total_visits,
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as patient_age
    FROM patients p 
    LEFT JOIN patient_next_of_kin k ON p.patient_id = k.patient_id
    LEFT JOIN visits v ON p.patient_id = v.patient_id
    WHERE DATE(p.created_at) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $gender_query
      $county_query
      $search_query
      AND p.patient_status != 'ARCHIVED'
    GROUP BY p.patient_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for patients
$stats_sql = "SELECT 
    COUNT(*) as total_patients,
    SUM(CASE WHEN patient_status = 'ACTIVE' THEN 1 ELSE 0 END) as active_patients,
    SUM(CASE WHEN sex = 'M' THEN 1 ELSE 0 END) as male_patients,
    SUM(CASE WHEN sex = 'F' THEN 1 ELSE 0 END) as female_patients,
    SUM(CASE WHEN is_deceased = 1 THEN 1 ELSE 0 END) as deceased_patients,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_registrations,
    AVG(TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())) as avg_age
    FROM patients 
    WHERE DATE(created_at) BETWEEN '$dtf' AND '$dtt'";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get age distribution
$age_stats_sql = "SELECT 
    CASE 
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 1 THEN '0-1'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 1 AND 5 THEN '1-5'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 6 AND 12 THEN '6-12'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 18 THEN '13-18'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 19 AND 35 THEN '19-35'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 60 THEN '36-60'
        ELSE '60+'
    END as age_group,
    COUNT(*) as count
    FROM patients 
    WHERE date_of_birth IS NOT NULL
    AND patient_status != 'ARCHIVED'
    GROUP BY age_group
    ORDER BY 
        CASE 
            WHEN age_group = '0-1' THEN 1
            WHEN age_group = '1-5' THEN 2
            WHEN age_group = '6-12' THEN 3
            WHEN age_group = '13-18' THEN 4
            WHEN age_group = '19-35' THEN 5
            WHEN age_group = '36-60' THEN 6
            WHEN age_group = '60+' THEN 7
        END";
$age_stats_result = $mysqli->query($age_stats_sql);
$age_stats = [];
while ($row = $age_stats_result->fetch_assoc()) {
    $age_stats[] = $row;
}

?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-user-injured mr-2"></i>Patient Management</h3>
        <div class="card-tools">
            <?php if (SimplePermission::any("patient_create")) { ?>
            <a href="patient_add.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Patient
            </a>
            <?php } ?>
        </div>
    </div>
    
    <!-- Statistics Row for Patients -->
    <div class="card-header py-2 bg-light">
        <div class="row">
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-primary">
                    <div class="inner">
                        <h3><?php echo $stats['total_patients'] ?? 0; ?></h3>
                        <p>Total Patients</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $stats['active_patients'] ?? 0; ?></h3>
                        <p>Active Patients</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $stats['male_patients'] ?? 0; ?></h3>
                        <p>Male</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-male"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $stats['female_patients'] ?? 0; ?></h3>
                        <p>Female</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-female"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo round($stats['avg_age'] ?? 0, 1); ?></h3>
                        <p>Avg Age</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-child"></i>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="small-box bg-secondary">
                    <div class="inner">
                        <h3><?php echo $stats['today_registrations'] ?? 0; ?></h3>
                        <p>Today</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-calendar-day"></i>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search patients, MRN, phone, email..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter || $gender_filter || $county_filter) { echo "show"; } ?>" id="advancedFilter">
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
                                <option value="ACTIVE" <?php if ($status_filter == "ACTIVE") { echo "selected"; } ?>>Active</option>
                                <option value="ARCHIVED" <?php if ($status_filter == "ARCHIVED") { echo "selected"; } ?>>Archived</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Gender</label>
                            <select class="form-control select2" name="gender" onchange="this.form.submit()">
                                <option value="">- All Genders -</option>
                                <option value="M" <?php if ($gender_filter == "M") { echo "selected"; } ?>>Male</option>
                                <option value="F" <?php if ($gender_filter == "F") { echo "selected"; } ?>>Female</option>
                                <option value="I" <?php if ($gender_filter == "I") { echo "selected"; } ?>>Intersex</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>County</label>
                            <select class="form-control select2" name="county" onchange="this.form.submit()">
                                <option value="">- All Counties -</option>
                                <?php while ($county_row = $counties_result->fetch_assoc()): ?>
                                    <option value="<?php echo $county_row['county']; ?>" <?php if ($county_filter == $county_row['county']) { echo "selected"; } ?>>
                                        <?php echo $county_row['county']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Age Range</label>
                            <select class="form-control select2" name="age" onchange="this.form.submit()">
                                <option value="">- All Ages -</option>
                                <option value="0-1" <?php if (isset($_GET['age']) && $_GET['age'] == '0-1') echo "selected"; ?>>0-1 years</option>
                                <option value="1-5" <?php if (isset($_GET['age']) && $_GET['age'] == '1-5') echo "selected"; ?>>1-5 years</option>
                                <option value="6-12" <?php if (isset($_GET['age']) && $_GET['age'] == '6-12') echo "selected"; ?>>6-12 years</option>
                                <option value="13-18" <?php if (isset($_GET['age']) && $_GET['age'] == '13-18') echo "selected"; ?>>13-18 years</option>
                                <option value="19-35" <?php if (isset($_GET['age']) && $_GET['age'] == '19-35') echo "selected"; ?>>19-35 years</option>
                                <option value="36-60" <?php if (isset($_GET['age']) && $_GET['age'] == '36-60') echo "selected"; ?>>36-60 years</option>
                                <option value="60+" <?php if (isset($_GET['age']) && $_GET['age'] == '60+') echo "selected"; ?>>60+ years</option>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.created_at&order=<?php echo $disp; ?>">
                        Registered <?php if ($sort == 'p.created_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.last_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.last_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Contact Info</th>
                <th>Location & ID</th>
                <th>Next of Kin</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=total_visits&order=<?php echo $disp; ?>">
                        Visits <?php if ($sort == 'total_visits') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Status & Details</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if (mysqli_num_rows($sql) > 0) {
                while ($row = mysqli_fetch_array($sql)) {
                    $patient_id = intval($row['patient_id']);
                    $first_name = nullable_htmlentities($row['first_name']);
                    $middle_name = nullable_htmlentities($row['middle_name']);
                    $last_name = nullable_htmlentities($row['last_name']);
                    $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                    $date_of_birth = nullable_htmlentities($row['date_of_birth']);
                    $sex = nullable_htmlentities($row['sex']);
                    $id_type = nullable_htmlentities($row['id_type']);
                    $id_number = nullable_htmlentities($row['id_number']);
                    $phone_primary = nullable_htmlentities($row['phone_primary']);
                    $phone_secondary = nullable_htmlentities($row['phone_secondary']);
                    $email = nullable_htmlentities($row['email']);
                    $patient_status = nullable_htmlentities($row['patient_status']);
                    $created_at = nullable_htmlentities($row['created_at']);
                    $blood_group = nullable_htmlentities($row['blood_group']);
                    $is_deceased = intval($row['is_deceased']);
                    $date_of_death = nullable_htmlentities($row['date_of_death']);
                    
                    // Address fields
                    $county = nullable_htmlentities($row['county']);
                    $sub_county = nullable_htmlentities($row['sub_county']);
                    $ward = nullable_htmlentities($row['ward']);
                    $village = nullable_htmlentities($row['village']);
                    $postal_address = nullable_htmlentities($row['postal_address']);
                    $postal_code = nullable_htmlentities($row['postal_code']);
                    
                    // Next of kin
                    $kin_name = nullable_htmlentities($row['kin_name']);
                    $kin_relationship = nullable_htmlentities($row['kin_relationship']);
                    $kin_phone = nullable_htmlentities($row['kin_phone']);
                    
                    $total_visits = intval($row['total_visits']);
                    $patient_age = intval($row['patient_age']);

                    // Status badge styling
                    $status_color = '';
                    $status_text = '';
                    if ($is_deceased) {
                        $status_color = 'dark';
                        $status_text = 'DECEASED';
                    } else {
                        switch($patient_status) {
                            case 'ACTIVE':
                                $status_color = 'success';
                                $status_text = 'ACTIVE';
                                break;
                            case 'ARCHIVED':
                                $status_color = 'secondary';
                                $status_text = 'ARCHIVED';
                                break;
                            default:
                                $status_color = 'secondary';
                                $status_text = $patient_status ?: 'ACTIVE';
                        }
                    }

                    // Gender styling
                    $gender_color = '';
                    $gender_text = '';
                    switch($sex) {
                        case 'M':
                            $gender_color = 'primary';
                            $gender_text = 'Male';
                            break;
                        case 'F':
                            $gender_color = 'danger';
                            $gender_text = 'Female';
                            break;
                        case 'I':
                            $gender_color = 'info';
                            $gender_text = 'Intersex';
                            break;
                        default:
                            $gender_color = 'secondary';
                            $gender_text = 'Unknown';
                    }

                    // Blood group styling
                    $blood_color = '';
                    if ($blood_group) {
                        $blood_color = strpos($blood_group, '+') !== false ? 'danger' : 'warning';
                    }

                    // Build full name
                    $full_name = $first_name;
                    if (!empty($middle_name)) {
                        $full_name .= ' ' . $middle_name;
                    }
                    $full_name .= ' ' . $last_name;
                    $full_name_with_age = $full_name . " ($patient_age yrs)";

                    // Build location string
                    $location_parts = [];
                    if (!empty($village)) $location_parts[] = $village;
                    if (!empty($ward)) $location_parts[] = $ward;
                    if (!empty($sub_county)) $location_parts[] = $sub_county;
                    if (!empty($county)) $location_parts[] = $county;
                    $location = implode(', ', $location_parts);
                    if (empty($location)) $location = 'Not specified';

                    // Build ID info
                    $id_info = '';
                    if ($id_type && $id_number) {
                        $id_info = $id_type . ': ' . $id_number;
                    } elseif ($id_number) {
                        $id_info = 'ID: ' . $id_number;
                    }

                    // Format dates
                    $created_date_formatted = date('M j, Y', strtotime($created_at));
                    $created_time_formatted = date('g:i A', strtotime($created_at));
                    $dob_formatted = $date_of_birth ? date('M j, Y', strtotime($date_of_birth)) : 'Not specified';

                    // Row styling based on status
                    $row_class = '';
                    if ($is_deceased) {
                        $row_class = 'table-dark';
                    } elseif ($patient_status == 'ARCHIVED') {
                        $row_class = 'table-secondary';
                    } elseif ($patient_status == 'ACTIVE') {
                        $row_class = 'table-success';
                    }
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo $created_date_formatted; ?></div>
                            <small class="text-muted"><?php echo $created_time_formatted; ?></small>
                            <br>
                            <small class="text-muted">#<?php echo $patient_mrn; ?></small>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $full_name_with_age; ?></div>
                            <div class="d-flex align-items-center mt-1">
                                <span class="badge badge-<?php echo $gender_color; ?> mr-2"><?php echo $gender_text; ?></span>
                                <?php if ($blood_group): ?>
                                    <span class="badge badge-<?php echo $blood_color; ?>"><?php echo $blood_group; ?></span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted d-block mt-1">DOB: <?php echo $dob_formatted; ?></small>
                        </td>
                        <td>
                            <?php if ($phone_primary): ?>
                                <div><i class="fas fa-phone fa-fw text-muted mr-1"></i><?php echo $phone_primary; ?></div>
                            <?php endif; ?>
                            <?php if ($phone_secondary): ?>
                                <div><i class="fas fa-mobile-alt fa-fw text-muted mr-1"></i><?php echo $phone_secondary; ?></div>
                            <?php endif; ?>
                            <?php if ($email): ?>
                                <div><i class="fas fa-envelope fa-fw text-muted mr-1"></i><?php echo $email; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div data-toggle="tooltip" title="<?php echo htmlspecialchars($location); ?>">
                                <?php echo strlen($location) > 30 ? substr($location, 0, 30) . '...' : $location; ?>
                            </div>
                            <?php if ($id_info): ?>
                                <small class="text-muted"><?php echo $id_info; ?></small>
                            <?php endif; ?>
                            <?php if ($postal_address): ?>
                                <div><i class="fas fa-envelope fa-fw text-muted mr-1"></i><?php echo $postal_address; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($kin_name): ?>
                                <div class="font-weight-bold"><?php echo $kin_name; ?></div>
                                <small class="text-muted"><?php echo $kin_relationship ?: 'Relative'; ?></small>
                                <?php if ($kin_phone): ?>
                                    <div><i class="fas fa-phone fa-fw text-muted mr-1"></i><?php echo $kin_phone; ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Not specified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($total_visits > 0): ?>
                                <span class="badge badge-primary"><?php echo $total_visits; ?> visit(s)</span>
                            <?php else: ?>
                                <span class="text-muted">No visits</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                            <?php if ($is_deceased && $date_of_death): ?>
                                <div class="mt-1">
                                    <small class="text-muted">Died: <?php echo date('M j, Y', strtotime($date_of_death)); ?></small>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <?php if (SimplePermission::any("patient_view")): ?>
                                    <a class="dropdown-item" href="patient_details.php?patient_id=<?php echo $patient_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (SimplePermission::any("patient_edit") && !$is_deceased && $patient_status != 'ARCHIVED'): ?>
                                    <a class="dropdown-item" href="patient_edit.php?patient_id=<?php echo $patient_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Patient
                                    </a>
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <?php if (SimplePermission::any("visit_create") && !$is_deceased): ?>
                                    <a class="dropdown-item" href="/clinic/visit/visit_add.php?patient_id=<?php echo $patient_id; ?>">
                                        <i class="fas fa-fw fa-plus-circle mr-2"></i>New Visit
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (SimplePermission::any("patient_print_card")): ?>
                                    <a class="dropdown-item" href="patient_card_print.php?patient_id=<?php echo $patient_id; ?>" target="_blank">
                                        <i class="fas fa-fw fa-id-card mr-2"></i>Patient Card
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (SimplePermission::any("patient_print_summary")): ?>
                                    <a class="dropdown-item" href="patient_summary_print.php?patient_id=<?php echo $patient_id; ?>" target="_blank">
                                        <i class="fas fa-fw fa-file-medical mr-2"></i>Medical Summary
                                    </a>
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <?php if (SimplePermission::any("patient_edit") && !$is_deceased): ?>
                                        <?php if ($patient_status == 'ACTIVE'): ?>
                                            <a class="dropdown-item text-warning" href="post/patient_actions.php?action=archive&patient_id=<?php echo $patient_id; ?>" onclick="return confirm('Archive this patient? They will no longer appear in active lists.')">
                                                <i class="fas fa-fw fa-archive mr-2"></i>Archive Patient
                                            </a>
                                        <?php else: ?>
                                            <a class="dropdown-item text-success" href="post/patient_actions.php?action=activate&patient_id=<?php echo $patient_id; ?>" onclick="return confirm('Activate this patient?')">
                                                <i class="fas fa-fw fa-user-check mr-2"></i>Activate Patient
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if (!$is_deceased): ?>
                                        <a class="dropdown-item text-danger" href="post/patient_actions.php?action=mark_deceased&patient_id=<?php echo $patient_id; ?>" onclick="return confirm('Mark this patient as deceased? This action cannot be undone.')">
                                            <i class="fas fa-fw fa-cross mr-2"></i>Mark as Deceased
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php if (SimplePermission::any("patient_delete") && $patient_status == 'ARCHIVED'): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger" href="post/patient_actions.php?action=delete&patient_id=<?php echo $patient_id; ?>" onclick="return confirm('Permanently delete this archived patient? This action cannot be undone.')">
                                        <i class="fas fa-fw fa-trash-alt mr-2"></i>Delete Permanently
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
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-user-injured fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No patients found</h5>
                        <p class="text-muted">Try adjusting your filters or search criteria</p>
                        <?php if (SimplePermission::any("patient_create")) { ?>
                        <a href="patient_add.php" class="btn btn-success">
                            <i class="fas fa-plus mr-2"></i>New Patient
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

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + N for new patient
        if (e.ctrlKey && e.keyCode === 78) {
            e.preventDefault();
            window.location.href = 'patient_add.php';
        }
        // Ctrl + F for focus search
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('input[name="q"]').focus();
        }
        // Ctrl + A for advanced filter toggle
        if (e.ctrlKey && e.keyCode === 65) {
            e.preventDefault();
            $('#advancedFilter').collapse('toggle');
        }
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
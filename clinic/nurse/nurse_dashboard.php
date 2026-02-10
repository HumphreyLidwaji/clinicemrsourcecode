<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$date_filter = $_GET['date'] ?? date('Y-m-d');
$visit_type_filter = $_GET['visit_type'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? 'today';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Build WHERE conditions
$where_conditions = ["v.visit_status = 'ACTIVE'"];
$join_conditions = [];

// Date filtering
if ($status_filter === 'today') {
    $where_conditions[] = "DATE(v.visit_datetime) = CURDATE()";
} elseif (!empty($date_filter) && $date_filter !== 'all') {
    $date_filter = sanitizeInput($date_filter);
    $where_conditions[] = "DATE(v.visit_datetime) = '$date_filter'";
} elseif (!empty($dtf) && !empty($dtt)) {
    $where_conditions[] = "DATE(v.visit_datetime) BETWEEN '$dtf' AND '$dtt'";
}

if ($visit_type_filter) {
    $visit_type_filter = sanitizeInput($visit_type_filter);
    $where_conditions[] = "v.visit_type = '$visit_type_filter'";
} else {
    // Default to all visit types for nurse dashboard
    $where_conditions[] = "v.visit_type IN ('OPD', 'EMERGENCY', 'IPD')";
}

if ($department_filter) {
    $department_filter = intval($department_filter);
    $where_conditions[] = "v.department_id = $department_filter";
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_conditions = [
        "CONCAT(p.first_name, ' ', p.last_name) LIKE '%$q%'",
        "p.patient_mrn LIKE '%$q%'",
        "v.visit_number LIKE '%$q%'",
        "d.department_name LIKE '%$q%'"
    ];
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get statistics
$stats_sql = mysqli_query($mysqli, "
    SELECT 
        COUNT(*) as total_visits,
        SUM(CASE WHEN v.visit_type = 'OPD' THEN 1 ELSE 0 END) as opd_count,
        SUM(CASE WHEN v.visit_type = 'IPD' THEN 1 ELSE 0 END) as ipd_count,
        SUM(CASE WHEN v.visit_type = 'EMERGENCY' THEN 1 ELSE 0 END) as er_count,
        SUM(CASE WHEN (
            SELECT COUNT(*) FROM vitals vt 
            WHERE vt.visit_id = v.visit_id 
            AND DATE(vt.recorded_at) = CURDATE()
        ) = 0 AND v.visit_type IN ('OPD', 'EMERGENCY') THEN 1 ELSE 0 END) as waiting_triage,
        SUM(CASE WHEN (
            SELECT COUNT(*) FROM drug_administration da 
            WHERE da.visit_id = v.visit_id 
            AND da.status = 'scheduled'
            AND DATE(da.time_scheduled) <= CURDATE()
        ) > 0 THEN 1 ELSE 0 END) as pending_medications,
        SUM(CASE WHEN (
            SELECT COUNT(*) FROM lab_orders lo 
            WHERE lo.visit_id = v.visit_id 
            AND lo.lab_order_status = 'pending_collection'
        ) > 0 THEN 1 ELSE 0 END) as pending_specimens
    FROM visits v
    JOIN patients p ON v.patient_id = p.patient_id
    LEFT JOIN departments d ON v.department_id = d.department_id
    $where_clause
");

$stats = mysqli_fetch_assoc($stats_sql);

$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        v.*,
        p.patient_id,
        p.first_name,
        p.last_name,
        p.patient_mrn,
        p.date_of_birth,
        p.sex,
        p.phone_primary,
        d.department_name,
        CONCAT(u.user_name) as attending_provider,
        
        -- For IPD visits, get ward/bed info
        ia.admission_number,
        ia.ward_id,
        ia.bed_id,
        w.ward_name,
        b.bed_number,
        
        -- Check if vitals recorded today
        (SELECT COUNT(*) FROM vitals vt 
         WHERE vt.visit_id = v.visit_id 
         AND DATE(vt.recorded_at) = CURDATE()) as vitals_recorded,
        
        -- Latest vitals
        (SELECT temperature FROM vitals vt 
         WHERE vt.visit_id = v.visit_id 
         ORDER BY vt.recorded_at DESC LIMIT 1) as last_temp,
        
        (SELECT pulse FROM vitals vt 
         WHERE vt.visit_id = v.visit_id 
         ORDER BY vt.recorded_at DESC LIMIT 1) as last_pulse,
        
        (SELECT blood_pressure_systolic FROM vitals vt 
         WHERE vt.visit_id = v.visit_id 
         ORDER BY vt.recorded_at DESC LIMIT 1) as last_bp_sys,
        
        (SELECT blood_pressure_diastolic FROM vitals vt 
         WHERE vt.visit_id = v.visit_id 
         ORDER BY vt.recorded_at DESC LIMIT 1) as last_bp_dia,
        
        (SELECT recorded_at FROM vitals vt 
         WHERE vt.visit_id = v.visit_id 
         ORDER BY vt.recorded_at DESC LIMIT 1) as last_vitals_time,
        
        -- Pending medications count
        (SELECT COUNT(*) FROM drug_administration da 
         WHERE da.visit_id = v.visit_id 
         AND da.status = 'scheduled'
         AND DATE(da.time_scheduled) <= CURDATE()) as pending_meds,
        
        -- Pending specimens count
        (SELECT COUNT(*) FROM lab_orders lo 
         WHERE lo.visit_id = v.visit_id 
         AND lo.lab_order_status = 'pending_collection') as pending_specimens
        
    FROM visits v
    JOIN patients p ON v.patient_id = p.patient_id
    LEFT JOIN departments d ON v.department_id = d.department_id
    LEFT JOIN users u ON v.attending_provider_id = u.user_id
    LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id AND v.visit_type = 'IPD'
    LEFT JOIN wards w ON ia.ward_id = w.ward_id
    LEFT JOIN beds b ON ia.bed_id = b.bed_id
    $where_clause
    ORDER BY 
        CASE 
            WHEN v.visit_type = 'EMERGENCY' THEN 0
            WHEN (
                SELECT COUNT(*) FROM vitals vt 
                WHERE vt.visit_id = v.visit_id 
                AND DATE(vt.recorded_at) = CURDATE()
            ) = 0 AND v.visit_type IN ('OPD', 'EMERGENCY') THEN 1
            ELSE 2
        END,
        v.visit_datetime ASC
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get departments for filter
$depts_sql = mysqli_query($mysqli, "
    SELECT department_id, department_name 
    FROM departments 
    WHERE department_is_active = 1 
    ORDER BY department_name
");

// Get distinct dates for filter
$dates_sql = mysqli_query($mysqli, "
    SELECT DISTINCT DATE(visit_datetime) as visit_date
    FROM visits
    WHERE visit_datetime IS NOT NULL
    ORDER BY visit_datetime DESC
    LIMIT 20
");
?>

<div class="card">
    <div class="card-header bg-info py-2 text-white">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-stethoscope mr-2"></i>Nurse Dashboard</h3>
   
    </div>
    
    <!-- Stats Bar -->
    <div class="card-body py-2 bg-light">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex flex-wrap">
                    <span class="badge badge-info mr-2">Total: <?php echo $stats['total_visits'] ?? 0; ?></span>
                    <span class="badge badge-primary mr-2">OPD: <?php echo $stats['opd_count'] ?? 0; ?></span>
                    <?php if (($stats['ipd_count'] ?? 0) > 0): ?>
                        <span class="badge badge-warning mr-2">IPD: <?php echo $stats['ipd_count']; ?></span>
                    <?php endif; ?>
                    <?php if (($stats['er_count'] ?? 0) > 0): ?>
                        <span class="badge badge-danger mr-2">ER: <?php echo $stats['er_count']; ?></span>
                    <?php endif; ?>
                    
                    <?php if (($stats['waiting_triage'] ?? 0) > 0): ?>
                        <span class="badge badge-danger mr-2">
                            <i class="fas fa-clock"></i> Awaiting Triage: <?php echo $stats['waiting_triage']; ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (($stats['pending_medications'] ?? 0) > 0): ?>
                        <span class="badge badge-warning mr-2">
                            <i class="fas fa-pills"></i> Meds Due: <?php echo $stats['pending_medications']; ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (($stats['pending_specimens'] ?? 0) > 0): ?>
                        <span class="badge badge-danger mr-2">
                            <i class="fas fa-vial"></i> Specimens: <?php echo $stats['pending_specimens']; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" 
                                   placeholder="Search patients, MRN, visit number..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter">
                                    <i class="fas fa-filter"></i>
                                </button>
                                <button class="btn btn-primary">
                                    <i class="fa fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="collapse <?php echo (isset($_GET['date']) || $visit_type_filter || $department_filter || isset($_GET['dtf'])) ? 'show' : ''; ?>" id="advancedFilter">
                <div class="row mt-2">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Selection</label>
                            <select class="form-control select2" name="status" onchange="toggleDateInput(this.value)">
                                <option value="today" <?php echo $status_filter == "today" ? "selected" : ""; ?>>Today</option>
                                <option value="date" <?php echo !empty($date_filter) ? "selected" : ""; ?>>Specific Date</option>
                                <option value="range" <?php echo (!empty($dtf) && !empty($dtt)) ? "selected" : ""; ?>>Date Range</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-2" id="specificDateGroup" style="<?php echo empty($date_filter) ? 'display: none;' : ''; ?>">
                        <div class="form-group">
                            <label>Select Date</label>
                            <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2" id="dateRangeGroup" style="<?php echo (empty($dtf) || empty($dtt)) ? 'display: none;' : ''; ?>">
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" class="form-control" name="dtf" value="<?php echo htmlspecialchars($dtf); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2" id="dateRangeToGroup" style="<?php echo (empty($dtf) || empty($dtt)) ? 'display: none;' : ''; ?>">
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" class="form-control" name="dtt" value="<?php echo htmlspecialchars($dtt); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2" id="quickDatesGroup" style="<?php echo (!empty($date_filter) || (!empty($dtf) && !empty($dtt))) ? 'display: none;' : ''; ?>">
                        <div class="form-group">
                            <label>Quick Dates</label>
                            <select class="form-control select2" onchange="location.href='?date='+this.value+'&status=date'">
                                <option value="">Select a date...</option>
                                <?php while($date_row = mysqli_fetch_assoc($dates_sql)): ?>
                                    <?php $display_date = date('Y-m-d', strtotime($date_row['visit_date'])); ?>
                                    <option value="<?php echo $display_date; ?>" <?php echo $date_filter == $display_date ? 'selected' : ''; ?>>
                                        <?php echo date('M j, Y', strtotime($date_row['visit_date'])); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Visit Type</label>
                            <select class="form-control select2" name="visit_type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="OPD" <?php echo $visit_type_filter == "OPD" ? "selected" : ""; ?>>OPD</option>
                                <option value="IPD" <?php echo $visit_type_filter == "IPD" ? "selected" : ""; ?>>IPD (Ward)</option>
                                <option value="EMERGENCY" <?php echo $visit_type_filter == "EMERGENCY" ? "selected" : ""; ?>>Emergency</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Department</label>
                            <select class="form-control select2" name="department" onchange="this.form.submit()">
                                <option value="">- All Departments -</option>
                                <?php while($dept = mysqli_fetch_assoc($depts_sql)): ?>
                                    <?php $selected = $department_filter == $dept['department_id'] ? 'selected' : ''; ?>
                                    <option value="<?php echo $dept['department_id']; ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12 text-right">
                        <button type="submit" class="btn btn-primary mr-2">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear Filters
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Patients Table -->
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php echo $num_rows[0] == 0 ? "d-none" : ""; ?> bg-light">
            <tr>
                <th>Patient</th>
                <th>Visit Details</th>
                <th>Location</th>
                <th>Vitals Status</th>
                <th>Time</th>
                <th class="text-center">Tasks</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($num_rows[0] > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($sql)): ?>
                    <?php
                    $visit_id = intval($row['visit_id']);
                    $patient_name = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
                    $patient_mrn = htmlspecialchars($row['patient_mrn']);
                    $patient_dob = htmlspecialchars($row['date_of_birth']);
                    $patient_gender = htmlspecialchars($row['sex']);
                    $department_name = htmlspecialchars($row['department_name'] ?? 'Not specified');
                    $visit_number = htmlspecialchars($row['visit_number']);
                    $visit_datetime = htmlspecialchars($row['visit_datetime']);
                    $visit_type = $row['visit_type'];
                    $vitals_recorded = intval($row['vitals_recorded']);
                    $pending_meds = intval($row['pending_meds']);
                    $pending_specimens = intval($row['pending_specimens']);
                    $is_today = (date('Y-m-d', strtotime($row['visit_datetime'])) == date('Y-m-d'));
                    
                    // Calculate age
                    $age = '';
                    if (!empty($patient_dob)) {
                        $birthDate = new DateTime($patient_dob);
                        $today = new DateTime();
                        $age = $today->diff($birthDate)->y;
                    }
                    
                    // Check vitals status
                    $is_vitals_overdue = false;
                    if ($is_today && $vitals_recorded == 0 && in_array($visit_type, ['OPD', 'EMERGENCY'])) {
                        $is_vitals_overdue = true;
                    }
                    
                    // Visit type badge
                    $visit_type_badge = [
                        'OPD' => 'badge-primary',
                        'IPD' => 'badge-warning',
                        'EMERGENCY' => 'badge-danger'
                    ][$visit_type] ?? 'badge-info';
                    
                    // Date badge
                    $date_badge_class = $is_today ? 'badge-success' : 'badge-secondary';
                    
                    // Location display
                    $location_display = $department_name;
                    if ($visit_type === 'IPD' && !empty($row['ward_name'])) {
                        $location_display = $row['ward_name'];
                        if (!empty($row['bed_number'])) {
                            $location_display .= ' (Bed: ' . $row['bed_number'] . ')';
                        }
                    }
                    ?>
                    <tr class="<?php echo $visit_type === 'EMERGENCY' ? 'table-danger' : ($is_vitals_overdue ? 'table-warning' : ''); ?>">
                        <td>
                            <div class="font-weight-bold">
                                <?php if ($visit_type === 'EMERGENCY'): ?>
                                    <i class="fas fa-ambulance text-danger mr-1"></i>
                                <?php elseif ($visit_type === 'IPD'): ?>
                                    <i class="fas fa-bed text-warning mr-1"></i>
                                <?php endif; ?>
                                <?php echo $patient_name; ?>
                            </div>
                            <small class="text-muted">
                                <?php echo $patient_gender . ', ' . $age . 'y'; ?><br>
                                MRN: <?php echo $patient_mrn; ?>
                            </small>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $visit_number; ?></div>
                            <small class="text-muted">
                                <span class="badge <?php echo $date_badge_class; ?> badge-sm mr-1">
                                    <?php echo date('M j', strtotime($row['visit_datetime'])); ?>
                                </span>
                                <span class="badge <?php echo $visit_type_badge; ?> badge-sm">
                                    <?php echo $visit_type; ?>
                                </span>
                                <?php if($visit_type === 'IPD' && !empty($row['admission_number'])): ?>
                                    <br><small>Adm: <?php echo htmlspecialchars($row['admission_number']); ?></small>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $location_display; ?></div>
                            <?php if($row['attending_provider']): ?>
                                <small class="text-muted">
                                    Dr. <?php echo htmlspecialchars($row['attending_provider']); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($vitals_recorded > 0): ?>
                                <div class="text-success">
                                    <i class="fas fa-check-circle"></i> Recorded
                                </div>
                                <?php if($row['last_temp'] || $row['last_pulse'] || $row['last_bp_sys']): ?>
                                    <div class="small">
                                        <?php if($row['last_temp']): ?>
                                            <span class="badge badge-light mr-1">T: <?php echo $row['last_temp']; ?>°C</span>
                                        <?php endif; ?>
                                        <?php if($row['last_pulse']): ?>
                                            <span class="badge badge-light mr-1">P: <?php echo $row['last_pulse']; ?></span>
                                        <?php endif; ?>
                                        <?php if($row['last_bp_sys'] && $row['last_bp_dia']): ?>
                                            <span class="badge badge-light">BP: <?php echo $row['last_bp_sys']; ?>/<?php echo $row['last_bp_dia']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if($row['last_vitals_time']): ?>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($row['last_vitals_time'])); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if($is_vitals_overdue): ?>
                                    <div class="text-danger font-weight-bold">
                                        <i class="fas fa-exclamation-triangle"></i> Pending
                                    </div>
                                    <small class="text-danger">No vitals recorded</small>
                                <?php elseif($visit_type === 'IPD'): ?>
                                    <div class="text-muted">
                                        <i class="fas fa-bed"></i> Inpatient
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted">
                                        <i class="fas fa-minus-circle"></i> Not required
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo date('H:i', strtotime($visit_datetime)); ?></div>
                            <small class="text-muted">
                                <?php echo date('M j, Y', strtotime($row['visit_datetime'])); ?>
                            </small>
                        </td>
                        <td class="text-center">
                            <?php if($pending_meds > 0): ?>
                                <span class="badge badge-danger badge-pill mr-1" title="Pending Medications"><?php echo $pending_meds; ?> <i class="fas fa-pills fa-xs"></i></span>
                            <?php endif; ?>
                            <?php if($pending_specimens > 0): ?>
                                <span class="badge badge-warning badge-pill" title="Pending Specimens"><?php echo $pending_specimens; ?> <i class="fas fa-vial fa-xs"></i></span>
                            <?php endif; ?>
                            <?php if($pending_meds == 0 && $pending_specimens == 0): ?>
                                <span class="badge badge-success badge-pill">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <!-- VISIT OVERVIEW -->
                                    <a class="dropdown-item" href="/clinic/nurse/patient_overview.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>Patient Overview
                                    </a>
                                    
                                    <!-- TRIAGE/VITALS -->
                                    <a class="dropdown-item" href="/clinic/nurse/vitals.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-procedures mr-2"></i>
                                        <?php echo $visit_type === 'IPD' ? 'IPD Vitals' : 'OPD Triage'; ?>
                                    </a>
                                    
                                    <!-- NURSING NOTES -->
                                    <a class="dropdown-item" href="/clinic/nurse/nurse_notes.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-notes-medical mr-2"></i>Nursing Notes
                                    </a>
                                    
                                    <!-- MEDICATION ADMINISTRATION -->
                                    <a class="dropdown-item" href="/clinic/nurse/administer_meds.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-pills mr-2"></i>Administer Medication
                                    </a>
                                    
                                    <!-- LAB SPECIMEN COLLECTION -->
                                    <a class="dropdown-item" href="/clinic/nurse/collect_specimen.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-vial mr-2"></i>Collect Specimen
                                    </a>
                                       <!-- TASK -->
                                        <a class="dropdown-item" href="/clinic/nurse/tasks.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Tasks
                                        </a>
                                    <?php if ($visit_type === 'IPD'): ?>
                                        <!-- IPD SPECIFIC ACTIONS -->
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="/clinic/nurse/ward_round.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-hospital-user mr-2"></i>Ward Round
                                        </a>
                                        <a class="dropdown-item" href="/clinic/nurse/bed_transfer.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Bed Transfer
                                        </a>
                                         <a class="dropdown-item" href="/clinic/nurse/tasks.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Tasks
                                        </a>
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <!-- DISCHARGE -->
                                 
                                    <?php if ($visit_type === 'IPD'): ?>
                                        <a class="dropdown-item" href="/clinic/nurse/discharge_preparetions.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-sign-out-alt mr-2"></i>IPD Discharge
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <div class="text-muted">
                            <i class="fas fa-stethoscope fa-2x mb-2"></i>
                            <p>No patient visits found</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Initialize date input visibility
    toggleDateInput('<?php echo $status_filter; ?>');
    
    // Auto-refresh every 2 minutes
    setTimeout(function() {
        location.reload();
    }, 120000);
});

function toggleDateInput(status) {
    if (status === 'date') {
        $('#specificDateGroup').show();
        $('#dateRangeGroup').hide();
        $('#dateRangeToGroup').hide();
        $('#quickDatesGroup').hide();
    } else if (status === 'range') {
        $('#specificDateGroup').hide();
        $('#dateRangeGroup').show();
        $('#dateRangeToGroup').show();
        $('#quickDatesGroup').hide();
    } else {
        $('#specificDateGroup').hide();
        $('#dateRangeGroup').hide();
        $('#dateRangeToGroup').hide();
        $('#quickDatesGroup').show();
    }
}

// Submit form when date selection changes
$('select[name="status"]').change(function() {
    if ($(this).val() === 'today') {
        $(this).closest('form').submit();
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
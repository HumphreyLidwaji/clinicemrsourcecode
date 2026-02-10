<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check doctor permissions
if (!SimplePermission::any(['doctor_opd_access', 'doctor_ipd_access', 'doctor_er_access', 'consultation_access'])) {
    showPermissionDenied();
    exit();
}

// Filter parameters
$date_filter = $_GET['date'] ?? date('Y-m-d');
$visit_type_filter = $_GET['visit_type'] ?? ''; // OPD, IPD, EMERGENCY
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? 'today';
$consultation_status = $_GET['consultation_status'] ?? '';
$triage_status = $_GET['triage_status'] ?? '';
$ipd_status_filter = $_GET['ipd_status'] ?? 'active'; // For IPD visits: active, all

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Doctor ID for filtering
$doctor_id = $_SESSION['user_id'];

// Build WHERE conditions for visits table
$where_conditions = ["v.visit_status = 'ACTIVE'"];
$ipd_where_conditions = [];

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
    // Default to ALL visit types for doctor dashboard including IPD
    $where_conditions[] = "v.visit_type IN ('OPD', 'EMERGENCY', 'IPD')";
}

if ($department_filter) {
    $department_filter = intval($department_filter);
    $where_conditions[] = "v.department_id = $department_filter";
}

// Filter by consultation status
if ($consultation_status === 'pending') {
    $where_conditions[] = "(
        (SELECT COUNT(*) FROM doctor_consultations c 
         WHERE c.visit_id = v.visit_id AND c.consultation_status = 'completed') = 0
        OR c.consultation_status IS NULL
    )";
} elseif ($consultation_status === 'completed') {
    $where_conditions[] = "(
        SELECT COUNT(*) FROM doctor_consultations c 
        WHERE c.visit_id = v.visit_id AND c.consultation_status = 'completed'
    ) > 0";
}

// Filter by triage status (waiting for doctor) - only for OPD/EMERGENCY
if ($triage_status === 'triage_done') {
    $where_conditions[] = "(
        v.visit_type = 'IPD' OR (
            SELECT COUNT(*) FROM vitals vt 
            WHERE vt.visit_id = v.visit_id 
            AND DATE(vt.recorded_at) = DATE(v.visit_datetime)
        ) > 0
    )";
} elseif ($triage_status === 'triage_pending') {
    $where_conditions[] = "(
        v.visit_type IN ('OPD', 'EMERGENCY') AND (
            SELECT COUNT(*) FROM vitals vt 
            WHERE vt.visit_id = v.visit_id 
            AND DATE(vt.recorded_at) = DATE(v.visit_datetime)
        ) = 0
    )";
}

// Filter by IPD admission status
if ($visit_type_filter === 'IPD' || $visit_type_filter === '') {
    if ($ipd_status_filter === 'active') {
        $ipd_where_conditions[] = "(v.visit_type != 'IPD' OR ia.admission_status = 'ACTIVE')";
    }
}

// Filter by assigned doctor (if needed - uncomment if you want to filter by doctor)
// $where_conditions[] = "(v.attending_provider_id = $doctor_id OR ia.attending_provider_id = $doctor_id)";

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_conditions = [
        "CONCAT(p.first_name, ' ', p.last_name) LIKE '%$q%'",
        "p.patient_mrn LIKE '%$q%'",
        "v.visit_number LIKE '%$q%'",
        "d.department_name LIKE '%$q%'",
        "ia.admission_number LIKE '%$q%'",
        "w.ward_name LIKE '%$q%'",
        "b.bed_number LIKE '%$q%'"
    ];
    $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
}

// Combine all WHERE conditions
$all_where_conditions = array_merge($where_conditions, $ipd_where_conditions);
$where_clause = !empty($all_where_conditions) ? "WHERE " . implode(" AND ", $all_where_conditions) : "";

// Get statistics for doctor
$stats_sql = mysqli_query($mysqli, "
    SELECT 
        COUNT(*) as total_visits,
        SUM(CASE WHEN v.visit_type = 'OPD' THEN 1 ELSE 0 END) as opd_visits,
        SUM(CASE WHEN v.visit_type = 'IPD' THEN 1 ELSE 0 END) as ipd_visits,
        SUM(CASE WHEN v.visit_type = 'EMERGENCY' THEN 1 ELSE 0 END) as er_visits,
        SUM(CASE WHEN (
            SELECT COUNT(*) FROM doctor_consultations c 
            WHERE c.visit_id = v.visit_id 
            AND c.consultation_status = 'completed'
        ) > 0 THEN 1 ELSE 0 END) as consultations_completed,
        SUM(CASE WHEN (
            SELECT COUNT(*) FROM vitals vt 
            WHERE vt.visit_id = v.visit_id 
            AND DATE(vt.recorded_at) = DATE(v.visit_datetime)
        ) = 0 AND v.visit_type IN ('OPD', 'EMERGENCY') THEN 1 ELSE 0 END) as waiting_triage,
        SUM(CASE WHEN (
            SELECT COUNT(*) FROM lab_orders lo 
            WHERE lo.visit_id = v.visit_id 
            AND lo.lab_order_status = 'pending_result'
        ) > 0 THEN 1 ELSE 0 END) as pending_lab_results,
        SUM(CASE WHEN (
            SELECT COUNT(*) FROM prescriptions pr 
            WHERE pr.prescription_visit_id = v.visit_id 
            AND pr.prescription_status = 'active'
        ) > 0 THEN 1 ELSE 0 END) as pending_prescriptions,
        SUM(CASE WHEN v.visit_type = 'IPD' AND ia.admission_status = 'ACTIVE' THEN 1 ELSE 0 END) as active_ipd
    FROM visits v
    JOIN patients p ON v.patient_id = p.patient_id
    LEFT JOIN departments d ON v.department_id = d.department_id
    LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id AND v.visit_type = 'IPD'
    $where_clause
");

$stats = mysqli_fetch_assoc($stats_sql);

// Main query for visits
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
        ia.admission_status,
        ia.admission_datetime,
        ia.discharge_datetime,
        w.ward_name,
        b.bed_number,
        
        -- Days admitted (for IPD)
        CASE 
            WHEN v.visit_type = 'IPD' AND ia.discharge_datetime IS NULL 
            THEN DATEDIFF(CURDATE(), DATE(ia.admission_datetime))
            WHEN v.visit_type = 'IPD' AND ia.discharge_datetime IS NOT NULL
            THEN DATEDIFF(DATE(ia.discharge_datetime), DATE(ia.admission_datetime))
            ELSE 0
        END as days_admitted,
        
        -- Triage status (for OPD/ER)
        (SELECT COUNT(*) FROM vitals vt 
         WHERE vt.visit_id = v.visit_id 
         AND DATE(vt.recorded_at) = DATE(v.visit_datetime)) as vitals_recorded,
        
        -- Latest vitals (for all visit types)
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
        
        -- Consultation status
        (SELECT consultation_status FROM doctor_consultations c 
         WHERE c.visit_id = v.visit_id 
         ORDER BY c.created_at DESC LIMIT 1) as consultation_status,
        
        (SELECT created_at FROM doctor_consultations c 
         WHERE c.visit_id = v.visit_id 
         ORDER BY c.created_at DESC LIMIT 1) as last_consultation_time,
        
        -- Pending investigations
        (SELECT COUNT(*) FROM lab_orders lo 
         WHERE lo.visit_id = v.visit_id 
         AND lo.lab_order_status IN ('pending_collection', 'pending_result')) as pending_lab_orders,
        
        -- Pending radiology orders
        (SELECT COUNT(*) FROM radiology_orders ro 
         WHERE ro.visit_id = v.visit_id 
         AND ro.order_status IN ('pending', 'in_progress')) as pending_radiology_orders,
        
        -- Prescription status
        (SELECT COUNT(*) FROM prescriptions pr 
         WHERE pr.prescription_visit_id = v.visit_id 
         AND pr.prescription_status = 'active') as active_prescriptions,
        
        -- Queue time (minutes) - for OPD/ER
        CASE 
            WHEN v.visit_type IN ('OPD', 'EMERGENCY') 
            THEN TIMESTAMPDIFF(MINUTE, v.visit_datetime, NOW())
            ELSE 0
        END as queue_time_minutes,
        
        -- IPD specific: last doctor round
        (SELECT MAX(created_at) FROM doctor_rounds dr 
         WHERE dr.ipd_admission_id = ia.ipd_admission_id) as last_doctor_round
        
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
            WHEN v.visit_type = 'IPD' AND ia.admission_status = 'ACTIVE' THEN 1
            WHEN (
                SELECT COUNT(*) FROM vitals vt 
                WHERE vt.visit_id = v.visit_id 
                AND DATE(vt.recorded_at) = DATE(v.visit_datetime)
            ) = 0 AND v.visit_type IN ('OPD', 'EMERGENCY') THEN 2
            WHEN (
                SELECT consultation_status FROM doctor_consultations c 
                WHERE c.visit_id = v.visit_id 
                ORDER BY c.created_at DESC LIMIT 1
            ) = 'completed' THEN 4
            ELSE 3
        END,
        CASE 
            WHEN v.visit_type = 'IPD' THEN ia.admission_datetime
            ELSE v.visit_datetime
        END ASC
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
    <div class="card-header bg-primary py-2 text-white">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-user-md mr-2"></i>Doctor Consultation Dashboard</h3>
        <div class="card-tools">
            <?php if (SimplePermission::any("doctor_quick_consult")): ?>
                <a href="/consultation/quick_consult.php" class="btn btn-light">
                    <i class="fas fa-comment-medical mr-1"></i> Quick Consultation
                </a>
            <?php endif; ?>
            <?php if (SimplePermission::any("doctor_refer_patient")): ?>
                <a href="/referrals/refer_patient.php" class="btn btn-light ml-2">
                    <i class="fas fa-share-square mr-1"></i> Refer Patient
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Stats Bar -->
    <div class="card-body py-2 bg-light">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex flex-wrap">
                    <span class="badge badge-primary mr-2">Total: <?php echo $stats['total_visits'] ?? 0; ?></span>
                    <span class="badge badge-info mr-2">OPD: <?php echo $stats['opd_visits'] ?? 0; ?></span>
                    <?php if (($stats['ipd_visits'] ?? 0) > 0): ?>
                        <span class="badge badge-warning mr-2">IPD: <?php echo $stats['ipd_visits']; ?></span>
                        <span class="badge badge-success mr-2">Active IPD: <?php echo $stats['active_ipd'] ?? 0; ?></span>
                    <?php endif; ?>
                    <?php if (($stats['er_visits'] ?? 0) > 0): ?>
                        <span class="badge badge-danger mr-2">ER: <?php echo $stats['er_visits']; ?></span>
                    <?php endif; ?>
                    <span class="badge badge-success mr-2">Consulted: <?php echo $stats['consultations_completed'] ?? 0; ?></span>
                    
                    <?php if (($stats['waiting_triage'] ?? 0) > 0): ?>
                        <span class="badge badge-warning mr-2">
                            <i class="fas fa-user-clock"></i> Awaiting Triage: <?php echo $stats['waiting_triage']; ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (($stats['pending_lab_results'] ?? 0) > 0): ?>
                        <span class="badge badge-info mr-2">
                            <i class="fas fa-flask"></i> Pending Results: <?php echo $stats['pending_lab_results']; ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if (($stats['pending_prescriptions'] ?? 0) > 0): ?>
                        <span class="badge badge-danger mr-2">
                            <i class="fas fa-prescription"></i> Rx Pending: <?php echo $stats['pending_prescriptions']; ?>
                        </span>
                    <?php endif; ?>
                    
                    <!-- Quick Stats -->
                    <div class="ml-auto">
                        <small class="text-muted">
                            <i class="fas fa-sync-alt mr-1"></i> Auto-refresh in <span id="refreshTimer">120</span>s
                        </small>
                    </div>
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
                                   placeholder="Search patients, MRN, visit number, admission, bed..." autofocus>
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
                    
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Consultation Status</label>
                            <select class="form-control select2" name="consultation_status" onchange="this.form.submit()">
                                <option value="">- All -</option>
                                <option value="pending" <?php echo $consultation_status == "pending" ? "selected" : ""; ?>>Pending</option>
                                <option value="completed" <?php echo $consultation_status == "completed" ? "selected" : ""; ?>>Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <?php if ($visit_type_filter === 'IPD' || $visit_type_filter === ''): ?>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>IPD Status</label>
                            <select class="form-control select2" name="ipd_status" onchange="this.form.submit()">
                                <option value="active" <?php echo $ipd_status_filter == "active" ? "selected" : ""; ?>>Active Only</option>
                                <option value="all" <?php echo $ipd_status_filter == "all" ? "selected" : ""; ?>>All IPD</option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Triage Status</label>
                            <select class="form-control select2" name="triage_status" onchange="this.form.submit()">
                                <option value="">- All -</option>
                                <option value="triage_done" <?php echo $triage_status == "triage_done" ? "selected" : ""; ?>>Triage Done</option>
                                <option value="triage_pending" <?php echo $triage_status == "triage_pending" ? "selected" : ""; ?>>Awaiting Triage</option>
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
                        <button type="button" class="btn btn-success ml-2" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
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
                <th width="15%">Patient</th>
                <th width="12%">Visit Details</th>
                <th width="15%">Vitals & Status</th>
                <th width="18%">Consultation Status</th>
                <th width="15%">Investigations</th>
                <th width="15%">Time & Location</th>
                <th width="10%" class="text-center">Actions</th>
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
                    $consultation_status = $row['consultation_status'];
                    $pending_lab_orders = intval($row['pending_lab_orders']);
                    $pending_radiology_orders = intval($row['pending_radiology_orders']);
                    $active_prescriptions = intval($row['active_prescriptions']);
                    $is_today = (date('Y-m-d', strtotime($row['visit_datetime'])) == date('Y-m-d'));
                    $queue_time = intval($row['queue_time_minutes']);
                    $days_admitted = intval($row['days_admitted']);
                    
                    // Calculate age
                    $age = '';
                    if (!empty($patient_dob)) {
                        $birthDate = new DateTime($patient_dob);
                        $today = new DateTime();
                        $age = $today->diff($birthDate)->y;
                    }
                    
                    // Status indicators
                    $needs_triage = ($is_today && $vitals_recorded == 0 && in_array($visit_type, ['OPD', 'EMERGENCY']));
                    $needs_consultation = ($consultation_status !== 'completed');
                    $is_ipd_active = ($visit_type === 'IPD' && $row['admission_status'] === 'ACTIVE');
                    
                    // Visit type badge
                    $visit_type_badge = [
                        'OPD' => 'badge-primary',
                        'IPD' => $is_ipd_active ? 'badge-warning' : 'badge-secondary',
                        'EMERGENCY' => 'badge-danger'
                    ][$visit_type] ?? 'badge-info';
                    
                    // Priority based on queue time and status
                    $priority_class = '';
                    if ($visit_type === 'EMERGENCY') {
                        $priority_class = 'table-danger';
                    } elseif ($visit_type === 'IPD' && $is_ipd_active) {
                        $priority_class = 'table-warning';
                    } elseif ($queue_time > 120 && $needs_consultation) {
                        $priority_class = 'table-warning';
                    } elseif ($queue_time > 60 && $needs_consultation) {
                        $priority_class = 'table-info';
                    } elseif ($needs_triage) {
                        $priority_class = 'table-light';
                    }
                    
                    // Location display
                    $location_display = $department_name;
                    if ($visit_type === 'IPD' && !empty($row['ward_name'])) {
                        $location_display = $row['ward_name'];
                        if (!empty($row['bed_number'])) {
                            $location_display .= ' (Bed: ' . $row['bed_number'] . ')';
                        }
                    }
                    ?>
                    <tr class="<?php echo $priority_class; ?>">
                        <td>
                            <div class="font-weight-bold">
                                <?php if ($visit_type === 'EMERGENCY'): ?>
                                    <i class="fas fa-ambulance text-danger mr-1" title="Emergency"></i>
                                <?php elseif ($visit_type === 'IPD'): ?>
                                    <i class="fas fa-bed text-warning mr-1" title="Inpatient"></i>
                                <?php elseif ($needs_triage): ?>
                                    <i class="fas fa-clock text-warning mr-1" title="Awaiting Triage"></i>
                                <?php elseif ($needs_consultation): ?>
                                    <i class="fas fa-user-md text-primary mr-1" title="Ready for Consultation"></i>
                                <?php endif; ?>
                                <?php echo $patient_name; ?>
                            </div>
                            <small class="text-muted">
                                <?php echo $patient_gender . ', ' . $age . 'y'; ?><br>
                                MRN: <?php echo $patient_mrn; ?><br>
                                <?php if($row['phone_primary']): ?>
                                    <i class="fas fa-phone-alt fa-xs"></i> <?php echo htmlspecialchars($row['phone_primary']); ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $visit_number; ?></div>
                            <small class="text-muted">
                                <span class="badge <?php echo $visit_type_badge; ?> badge-sm mr-1">
                                    <?php echo $visit_type; ?>
                                </span><br>
                                <?php if($visit_type === 'IPD' && !empty($row['admission_number'])): ?>
                                    <span class="badge badge-light badge-sm">Adm: <?php echo htmlspecialchars($row['admission_number']); ?></span><br>
                                    <?php if($days_admitted > 0): ?>
                                        <small>Day <?php echo $days_admitted + 1; ?></small><br>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php echo $department_name; ?>
                            </small>
                        </td>
                        <td>
                            <?php if($vitals_recorded > 0 || $visit_type === 'IPD'): ?>
                                <div class="small mb-1">
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
                                <small class="<?php echo $visit_type === 'IPD' ? 'text-warning' : 'text-success'; ?>">
                                    <i class="fas fa-check-circle fa-xs"></i> 
                                    <?php echo $visit_type === 'IPD' ? 'Inpatient Vitals' : 'Triage Complete'; ?>
                                    <?php if($row['last_vitals_time']): ?>
                                        <br><small><?php echo date('H:i', strtotime($row['last_vitals_time'])); ?></small>
                                    <?php endif; ?>
                                </small>
                            <?php elseif(in_array($visit_type, ['OPD', 'EMERGENCY'])): ?>
                                <div class="text-warning font-weight-bold">
                                    <i class="fas fa-exclamation-triangle"></i> Awaiting Triage
                                </div>
                                <small class="text-muted">Vitals not recorded</small>
                            <?php else: ?>
                                <div class="text-muted">
                                    <i class="fas fa-bed"></i> Inpatient
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($consultation_status === 'completed'): ?>
                                <div class="text-success">
                                    <i class="fas fa-check-circle"></i> Consultation Complete
                                </div>
                                <?php if($row['last_consultation_time']): ?>
                                    <small class="text-muted">
                                        <?php echo date('H:i', strtotime($row['last_consultation_time'])); ?>
                                    </small>
                                <?php endif; ?>
                                <?php if($active_prescriptions > 0): ?>
                                    <div class="mt-1">
                                        <span class="badge badge-info">Rx: <?php echo $active_prescriptions; ?> active</span>
                                    </div>
                                <?php endif; ?>
                                <?php if($visit_type === 'IPD' && $row['last_doctor_round']): ?>
                                    <div class="mt-1">
                                        <small class="text-muted">
                                            <i class="fas fa-hospital-user"></i> Last round: <?php echo date('M j', strtotime($row['last_doctor_round'])); ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-primary font-weight-bold">
                                    <i class="fas fa-stethoscope"></i> Ready for Consultation
                                </div>
                                <small class="text-muted">
                                    <?php echo $needs_triage ? 'Pending triage' : 'Ready to see doctor'; ?>
                                </small>
                                <?php if($queue_time > 0 && $visit_type !== 'IPD'): ?>
                                    <div class="mt-1">
                                        <small class="text-<?php echo $visit_type === 'EMERGENCY' ? 'danger' : ($queue_time > 120 ? 'danger' : ($queue_time > 60 ? 'warning' : 'info')); ?>">
                                            <i class="fas fa-clock"></i> Waiting: <?php echo $queue_time; ?> min
                                        </small>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($pending_lab_orders > 0 || $pending_radiology_orders > 0): ?>
                                <div class="text-warning">
                                    <?php if($pending_lab_orders > 0): ?>
                                        <i class="fas fa-flask"></i> Labs: <?php echo $pending_lab_orders; ?><br>
                                    <?php endif; ?>
                                    <?php if($pending_radiology_orders > 0): ?>
                                        <i class="fas fa-x-ray"></i> Imaging: <?php echo $pending_radiology_orders; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if($consultation_status === 'completed'): ?>
                                    <small class="text-muted">Awaiting results</small>
                                <?php else: ?>
                                    <small class="text-muted">To be ordered</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-muted">
                                    <i class="fas fa-check-circle"></i> No pending investigations
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold">
                                <?php if($visit_type === 'IPD' && !empty($row['admission_datetime'])): ?>
                                    <?php echo date('H:i', strtotime($row['admission_datetime'])); ?>
                                <?php else: ?>
                                    <?php echo date('H:i', strtotime($visit_datetime)); ?>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?php echo date('M j, Y', strtotime($row['visit_datetime'])); ?><br>
                                <?php echo $location_display; ?>
                            </small>
                            
                            <?php if($queue_time > 30 && $needs_consultation && $visit_type !== 'IPD'): ?>
                                <div class="mt-1">
                                    <small class="badge badge-<?php echo $queue_time > 120 ? 'danger' : ($queue_time > 60 ? 'warning' : 'info'); ?>">
                                        Queue: <?php echo $queue_time; ?> min
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if($row['attending_provider']): ?>
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <i class="fas fa-user-md fa-xs"></i> Dr. <?php echo htmlspecialchars($row['attending_provider']); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="dropdown dropleft">
                                <button class="btn btn-primary btn-sm" type="button" data-toggle="dropdown" title="Doctor Actions">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <!-- PRIMARY CONSULTATION ACTION -->
                                    <?php if(!$needs_triage || $visit_type === 'IPD'): ?>
                                        <a class="dropdown-item <?php echo $consultation_status === 'completed' ? 'text-muted' : 'font-weight-bold text-primary'; ?>" 
                                           href="/clinic/doctor/doctor_consultation.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-stethoscope mr-2"></i>
                                            <?php echo $consultation_status === 'completed' ? 'Review Consultation' : 'Start Consultation'; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="dropdown-item text-muted">
                                            <i class="fas fa-fw fa-clock mr-2"></i>
                                            Awaiting Triage
                                        </span>
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <!-- QUICK ACTIONS -->
                                    <a class="dropdown-item" href="/clinic/doctor/doctor_notes.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-notes-medical mr-2"></i>Doctor Notes
                                    </a>
                                    <a class="dropdown-item" href="/clinic/doctor/doctor_orders.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-x-ray mr-2"></i>Orders
                                    </a>
                                    
                                    <?php if($visit_type === 'IPD'): ?>
                                        <a class="dropdown-item" href="/clinic/doctor/doctor_round.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-hospital-user mr-2"></i>Doctor Round
                                        </a>
                                       
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                              
                                    <a class="dropdown-item" href="/clinic/doctor/patient_overview.php?visit_id=<?php echo $visit_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>Visit Overview
                                    </a>
                                    
                                    <!-- REFERRAL -->
                                    <?php if (SimplePermission::any("doctor_refer_patient")): ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-warning" href="/referrals/refer_patient.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-share-square mr-2"></i>Refer Patient
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- DISCHARGE/COMPLETE -->
                                   
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-success" href="/clinic/doctor/discharge.php?visit_id=<?php echo $visit_id; ?>">
                                            <i class="fas fa-fw fa-sign-out-alt mr-2"></i> Discharge
                                        </a>
                                   
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="text-muted">
                            <i class="fas fa-user-md fa-3x mb-3"></i>
                            <h4>No patients found for consultation</h4>
                            <p>All patients have been consulted or no visits match your filters.</p>
                            <a href="?" class="btn btn-primary mt-2">
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
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
    
    // Auto-refresh timer
    let refreshTime = 120;
    const timerElement = document.getElementById('refreshTimer');
    
    const timerInterval = setInterval(function() {
        refreshTime--;
        if (timerElement) {
            timerElement.textContent = refreshTime;
        }
        
        if (refreshTime <= 0) {
            clearInterval(timerInterval);
            location.reload();
        }
    }, 1000);
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

// Quick status update
function markConsultationComplete(visitId) {
    if (confirm('Mark this consultation as complete?')) {
        $.post('/ajax/update_consultation.php', {
            visit_id: visitId,
            status: 'completed'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        });
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
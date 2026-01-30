<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Report parameters
$report_type = $_GET['report_type'] ?? 'hmis_summary';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$county = $_GET['county'] ?? '';
$indicator_category = $_GET['indicator_category'] ?? '';

// Validate dates
if (!validateDate($date_from)) $date_from = date('Y-m-01');
if (!validateDate($date_to)) $date_to = date('Y-m-t');
if ($date_from > $date_to) {
    $temp = $date_from;
    $date_from = $date_to;
    $date_to = $temp;
}

// Get facility information
$facility_sql = "SELECT * FROM facility_master ";
$facility_result = $mysqli->query($facility_sql);
$facility = $facility_result->fetch_assoc() ?? [
    'facility_code' => 'FAC001',
    'facility_name' => 'Kavuludi Sugical Centre',
    'facility_type' => 'Surgical Center',
    'county' => 'Vihiga',
    'sub_county' => 'Hamisi'
];

// Safe division function to prevent DivisionByZeroError
function safeDivision($numerator, $denominator, $decimal_places = 1) {
    if ($denominator == 0 || $denominator === null) {
        return 0;
    }
    return round(($numerator / $denominator) * 100, $decimal_places);
}

// Safe average function
function safeAverage($total, $count, $decimal_places = 1) {
    if ($count == 0 || $count === null) {
        return 0;
    }
    return round($total / $count, $decimal_places);
}

// Validate date function
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Generate reports based on type
switch ($report_type) {
    case 'hmis_summary':
        $report_data = generateHMISSummary($mysqli, $date_from, $date_to, $facility);
        break;
    case 'maternity_report':
        $report_data = generateMaternityReport($mysqli, $date_from, $date_to, $facility);
        break;
    case 'hiv_tb_report':
        $report_data = generateHIVTBReport($mysqli, $date_from, $date_to, $facility);
        break;
    case 'child_health_report':
        $report_data = generateChildHealthReport($mysqli, $date_from, $date_to, $facility);
        break;
    case 'surgical_report':
        $report_data = generateSurgicalReport($mysqli, $date_from, $date_to, $facility);
        break;
    default:
        $report_data = generateHMISSummary($mysqli, $date_from, $date_to, $facility);
}

// Report generation functions with safe division
function generateHMISSummary($mysqli, $date_from, $date_to, $facility) {
    $report = [
        'title' => 'HMIS SUMMARY REPORT - KENYA MOH',
        'headers' => ['Indicator Code', 'Indicator Name', 'Value', 'Remarks'],
        'rows' => []
    ];
    
    // OPD Statistics
    $opd_total = getOPDStatistics($mysqli, $date_from, $date_to);
    $report['rows'][] = ['HMIS_001', 'Total OPD Attendance', $opd_total['total'], ''];
    $report['rows'][] = ['HMIS_002', 'New OPD Attendance', $opd_total['new'], ''];
    $report['rows'][] = ['HMIS_003', 'Revisit OPD Attendance', $opd_total['revisit'], ''];
    $report['rows'][] = ['HMIS_004', 'OPD Attendance Under 5 Years', $opd_total['under5'], ''];
    
    // IPD Statistics
    $ipd_stats = getIPDStatistics($mysqli, $date_from, $date_to);
    $report['rows'][] = ['HMIS_005', 'Total IPD Admissions', $ipd_stats['admissions'], ''];
    $report['rows'][] = ['HMIS_006', 'IPD Discharges', $ipd_stats['discharges'], ''];
    $report['rows'][] = ['HMIS_007', 'IPD Deaths', $ipd_stats['deaths'], ''];
    $report['rows'][] = ['HMIS_008', 'Average Length of Stay', $ipd_stats['avg_stay'] . ' days', ''];
    
    // Maternity Statistics
    $maternity_stats = getMaternityStatistics($mysqli, $date_from, $date_to);
    $report['rows'][] = ['HMIS_009', 'Total Deliveries', $maternity_stats['deliveries'], ''];
    $report['rows'][] = ['HMIS_010', 'Normal Deliveries', $maternity_stats['normal_deliveries'], ''];
    $report['rows'][] = ['HMIS_011', 'Caesarean Sections', $maternity_stats['c_sections'], ''];
    $report['rows'][] = ['HMIS_012', 'ANC First Visits', $maternity_stats['anc_first'], ''];
    $report['rows'][] = ['HMIS_013', 'ANC Fourth Visits', $maternity_stats['anc_fourth'], ''];
    
    // Child Health
    $child_stats = getChildHealthStatistics($mysqli, $date_from, $date_to);
    $report['rows'][] = ['HMIS_015', 'Fully Immunized Children', $child_stats['immunized'], ''];
    $report['rows'][] = ['HMIS_016', 'Vitamin A Supplementation', $child_stats['vitamin_a'], ''];
    $report['rows'][] = ['HMIS_017', 'Deworming', $child_stats['deworming'], ''];
    
    // HIV Statistics
    $hiv_stats = getHIVStatistics($mysqli, $date_from, $date_to);
    $report['rows'][] = ['HMIS_018', 'HIV Tests Done', $hiv_stats['tests'], ''];
    $report['rows'][] = ['HMIS_019', 'HIV Positive', $hiv_stats['positive'], ''];
    $report['rows'][] = ['HMIS_020', 'ART Enrollment', $hiv_stats['art_enrollment'], ''];
    
    // TB Statistics
    $tb_stats = getTBStatistics($mysqli, $date_from, $date_to);
    $report['rows'][] = ['HMIS_022', 'TB Cases Diagnosed', $tb_stats['diagnosed'], ''];
    
    return $report;
}

function generateMaternityReport($mysqli, $date_from, $date_to, $facility) {
    $report = [
        'title' => 'MATERNITY SERVICES REPORT - KENYA MOH',
        'headers' => ['Service', 'Count', 'Percentage', 'Remarks'],
        'rows' => []
    ];
    
    $stats = getMaternityStatistics($mysqli, $date_from, $date_to);
    
    // Use safeDivision to prevent DivisionByZeroError
    $anc_first_percentage = safeDivision($stats['anc_first'], $stats['anc_total']);
    $anc_fourth_percentage = safeDivision($stats['anc_fourth'], $stats['anc_total']);
    $normal_delivery_percentage = safeDivision($stats['normal_deliveries'], $stats['deliveries']);
    $c_section_percentage = safeDivision($stats['c_sections'], $stats['deliveries']);
    $pnc_percentage = safeDivision($stats['pnc_48hrs'], $stats['deliveries']);
    $maternal_death_percentage = safeDivision($stats['maternal_deaths'], $stats['deliveries'], 2);
    
    $report['rows'][] = ['Total ANC Clients', $stats['anc_total'], '100%', 'All ANC registrations'];
    $report['rows'][] = ['First ANC Visits', $stats['anc_first'], $anc_first_percentage . '%', 'New ANC registrations'];
    $report['rows'][] = ['Fourth ANC Visits', $stats['anc_fourth'], $anc_fourth_percentage . '%', 'Completed 4 ANC visits'];
    $report['rows'][] = ['Total Deliveries', $stats['deliveries'], '100%', 'All deliveries'];
    $report['rows'][] = ['Normal Vaginal Deliveries', $stats['normal_deliveries'], $normal_delivery_percentage . '%', ''];
    $report['rows'][] = ['Caesarean Sections', $stats['c_sections'], $c_section_percentage . '%', ''];
    $report['rows'][] = ['PNC Within 48 Hours', $stats['pnc_48hrs'], $pnc_percentage . '%', 'Postnatal care within 48 hours'];
    $report['rows'][] = ['Maternal Deaths', $stats['maternal_deaths'], $maternal_death_percentage . '%', ''];
    
    return $report;
}

function generateHIVTBReport($mysqli, $date_from, $date_to, $facility) {
    $report = [
        'title' => 'HIV & TB SERVICES REPORT - KENYA MOH',
        'headers' => ['Indicator', 'Value', 'Remarks'],
        'rows' => []
    ];
    
    $hiv_stats = getHIVStatistics($mysqli, $date_from, $date_to);
    $tb_stats = getTBStatistics($mysqli, $date_from, $date_to);
    
    // Use safeDivision for HIV positivity rate
    $hiv_positivity_rate = safeDivision($hiv_stats['positive'], $hiv_stats['tests']);
    
    $report['rows'][] = ['HIV Tests Conducted', $hiv_stats['tests'], 'All HIV tests done'];
    $report['rows'][] = ['HIV Positive Results', $hiv_stats['positive'], $hiv_positivity_rate . '% positivity rate'];
    $report['rows'][] = ['New ART Enrollments', $hiv_stats['art_enrollment'], 'New patients on ART'];
    $report['rows'][] = ['Current on ART', $hiv_stats['current_art'], 'Active ART patients'];
    $report['rows'][] = ['TB Cases Diagnosed', $tb_stats['diagnosed'], 'New TB cases'];
    $report['rows'][] = ['TB-HIV Co-infection', $tb_stats['hiv_coinfection'], 'TB patients with HIV'];
    $report['rows'][] = ['TB Treatment Success', $tb_stats['treatment_success'], 'Completed treatment successfully'];
    
    return $report;
}

function generateChildHealthReport($mysqli, $date_from, $date_to, $facility) {
    $report = [
        'title' => 'CHILD HEALTH SERVICES REPORT - KENYA MOH',
        'headers' => ['Service', 'Count', 'Coverage', 'Remarks'],
        'rows' => []
    ];
    
    $stats = getChildHealthStatistics($mysqli, $date_from, $date_to);
    $cwc_stats = getCWCStatistics($mysqli, $date_from, $date_to);
    
    $report['rows'][] = ['CWC Total Visits', $cwc_stats['total_visits'], '100%', 'All Child Welfare Clinic visits'];
    $report['rows'][] = ['Fully Immunized Children', $stats['immunized'], safeDivision($stats['immunized'], $cwc_stats['total_children']) . '%', 'Completed immunization schedule'];
    $report['rows'][] = ['Vitamin A Supplementation', $stats['vitamin_a'], safeDivision($stats['vitamin_a'], $cwc_stats['total_children']) . '%', 'Children 6-59 months'];
    $report['rows'][] = ['Deworming', $stats['deworming'], safeDivision($stats['deworming'], $cwc_stats['total_children']) . '%', 'Children 1-14 years'];
    $report['rows'][] = ['Malaria Cases (Under 5)', $cwc_stats['malaria_cases'], safeDivision($cwc_stats['malaria_cases'], $cwc_stats['total_visits']) . '%', 'Confirmed malaria cases'];
    $report['rows'][] = ['Diarrhea Cases (Under 5)', $cwc_stats['diarrhea_cases'], safeDivision($cwc_stats['diarrhea_cases'], $cwc_stats['total_visits']) . '%', 'Diarrhea cases treated'];
    
    return $report;
}

// Updated data retrieval functions with proper parameter binding
function getOPDStatistics($mysqli, $date_from, $date_to) {
    $sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN v.visit_type = 'OPD' THEN 1 ELSE 0 END) as opd_visits,
        SUM(CASE WHEN v.visit_type = 'CHECKUP' THEN 1 ELSE 0 END) as new_visits,
        SUM(CASE WHEN v.visit_type = 'FOLLOWUP' THEN 1 ELSE 0 END) as revisit_visits,
        SUM(CASE WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, v.visit_datetime) < 5 THEN 1 ELSE 0 END) as under5
    FROM visits v 
    LEFT JOIN patients p ON v.patient_id = p.patient_id
    WHERE v.visit_datetime BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    return [
        'total' => $result['total'] ?? 0,
        'new' => $result['new_visits'] ?? 0,
        'revisit' => $result['revisit_visits'] ?? 0,
        'under5' => $result['under5'] ?? 0
    ];
}

function getIPDStatistics($mysqli, $date_from, $date_to) {
    $admissions = 0;
    $discharges = 0;
    $avg_stay = 0;
    
    $sql = "SELECT 
        COUNT(*) as admissions,
        SUM(CASE WHEN v.discharge_datetime IS NOT NULL THEN 1 ELSE 0 END) as discharges
    FROM visits v 
    WHERE v.visit_type = 'IPD' 
    AND v.visit_datetime BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $admissions = $result['admissions'] ?? 0;
        $discharges = $result['discharges'] ?? 0;
        $stmt->close();
    }
    
    // Calculate average length of stay
    $avg_stay_sql = "SELECT AVG(DATEDIFF(COALESCE(v.discharge_datetime, v.visit_datetime), v.visit_datetime)) as avg_stay 
                    FROM visits v 
                    WHERE v.visit_type = 'IPD' 
                    AND v.discharge_datetime IS NOT NULL 
                    AND v.visit_datetime BETWEEN ? AND ?";
    
    $avg_stmt = $mysqli->prepare($avg_stay_sql);
    if ($avg_stmt) {
        $avg_stmt->bind_param('ss', $date_from, $date_to);
        $avg_stmt->execute();
        $avg_result = $avg_stmt->get_result()->fetch_assoc();
        $avg_stay = round($avg_result['avg_stay'] ?? 0, 1);
        $avg_stmt->close();
    }
    
    return [
        'admissions' => $admissions,
        'discharges' => $discharges,
        'deaths' => 0, // Would need mortality tracking
        'avg_stay' => $avg_stay
    ];
}

function getMaternityStatistics($mysqli, $date_from, $date_to) {
    $anc_total = 0;
    $anc_first = 0;
    
    // ANC visits
    $anc_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN a.gravida = 1 THEN 1 ELSE 0 END) as first_visits
    FROM anc_visits a
    WHERE a.created_at BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($anc_sql);
    if ($stmt) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $anc_result = $stmt->get_result()->fetch_assoc();
        $anc_total = $anc_result['total'] ?? 0;
        $anc_first = $anc_result['first_visits'] ?? 0;
        $stmt->close();
    }
    
    // Deliveries from partograph
    $deliveries = 0;
    $delivery_sql = "SELECT COUNT(*) as deliveries 
                    FROM maternity_pantograph m 
                    WHERE m.recorded_at BETWEEN ? AND ? 
                    AND m.cervical_dilation >= 10";
    
    $del_stmt = $mysqli->prepare($delivery_sql);
    if ($del_stmt) {
        $del_stmt->bind_param('ss', $date_from, $date_to);
        $del_stmt->execute();
        $delivery_result = $del_stmt->get_result()->fetch_assoc();
        $deliveries = $delivery_result['deliveries'] ?? 0;
        $del_stmt->close();
    }
    
    // PNC visits - FIXED: Correct parameter binding
    $pnc_48hrs = 0;
    $pnc_sql = "SELECT COUNT(*) as pnc_count 
               FROM pnc_visits p 
               WHERE p.created_at BETWEEN ? AND ?
               AND TIMESTAMPDIFF(HOUR, p.created_at, NOW()) <= 48";
    
    $pnc_stmt = $mysqli->prepare($pnc_sql);
    if ($pnc_stmt) {
        $pnc_stmt->bind_param('ss', $date_from, $date_to); // Only 2 parameters needed
        $pnc_stmt->execute();
        $pnc_result = $pnc_stmt->get_result()->fetch_assoc();
        $pnc_48hrs = $pnc_result['pnc_count'] ?? 0;
        $pnc_stmt->close();
    }
    
    return [
        'anc_total' => $anc_total,
        'anc_first' => $anc_first,
        'anc_fourth' => 0, // Would need to track visit numbers
        'deliveries' => $deliveries,
        'normal_deliveries' => $deliveries, // Simplified
        'c_sections' => 0, // Would need surgery data
        'pnc_48hrs' => $pnc_48hrs,
        'maternal_deaths' => 0
    ];
}

function getChildHealthStatistics($mysqli, $date_from, $date_to) {
    $total_visits = 0;
    $vitamin_a = 0;
    $deworming = 0;
    
    // CWC visits
    $cwc_sql = "SELECT 
        COUNT(*) as total_visits,
        SUM(CASE WHEN c.vitamin_a_given = 'Yes' THEN 1 ELSE 0 END) as vitamin_a,
        SUM(CASE WHEN c.deworming = 'Yes' THEN 1 ELSE 0 END) as deworming
    FROM cwc_visits c
    WHERE c.created_at BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($cwc_sql);
    if ($stmt) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $total_visits = $result['total_visits'] ?? 0;
        $vitamin_a = $result['vitamin_a'] ?? 0;
        $deworming = $result['deworming'] ?? 0;
        $stmt->close();
    }
    
    // Immunizations
    $immunized = 0;
    $immunization_sql = "SELECT COUNT(DISTINCT patient_id) as immunized 
                        FROM immunizations 
                        WHERE vaccination_date BETWEEN ? AND ?";
    
    $imm_stmt = $mysqli->prepare($immunization_sql);
    if ($imm_stmt) {
        $imm_stmt->bind_param('ss', $date_from, $date_to);
        $imm_stmt->execute();
        $imm_result = $imm_stmt->get_result()->fetch_assoc();
        $immunized = $imm_result['immunized'] ?? 0;
        $imm_stmt->close();
    }
    
    return [
        'immunized' => $immunized,
        'vitamin_a' => $vitamin_a,
        'deworming' => $deworming,
        'total_visits' => $total_visits
    ];
}

function getCWCStatistics($mysqli, $date_from, $date_to) {
    // Get total children visiting CWC
    $children_sql = "SELECT COUNT(DISTINCT patient_id) as total_children 
                    FROM cwc_visits 
                    WHERE created_at BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($children_sql);
    $total_children = 0;
    $total_visits = 0;
    
    if ($stmt) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $total_children = $result['total_children'] ?? 0;
        $stmt->close();
    }
    
    // Get total visits
    $visits_sql = "SELECT COUNT(*) as total_visits FROM cwc_visits WHERE created_at BETWEEN ? AND ?";
    $visits_stmt = $mysqli->prepare($visits_sql);
    if ($visits_stmt) {
        $visits_stmt->bind_param('ss', $date_from, $date_to);
        $visits_stmt->execute();
        $visits_result = $visits_stmt->get_result()->fetch_assoc();
        $total_visits = $visits_result['total_visits'] ?? 0;
        $visits_stmt->close();
    }
    
    return [
        'total_children' => $total_children,
        'total_visits' => $total_visits,
        'malaria_cases' => 0, // Would need diagnosis data
        'diarrhea_cases' => 0  // Would need diagnosis data
    ];
}

function getHIVStatistics($mysqli, $date_from, $date_to) {
    $tests = 0;
    $positive = 0;
    
    $sql = "SELECT 
        COUNT(*) as tests,
        SUM(CASE WHEN h.result = 'Positive' THEN 1 ELSE 0 END) as positive
    FROM hiv_tests h
    WHERE h.test_date BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $tests = $result['tests'] ?? 0;
        $positive = $result['positive'] ?? 0;
        $stmt->close();
    }
    
    // ART patients
    $current_art = 0;
    $art_sql = "SELECT COUNT(*) as current_art 
               FROM hiv_art_followup 
               WHERE created_at BETWEEN ? AND ?";
    
    $art_stmt = $mysqli->prepare($art_sql);
    if ($art_stmt) {
        $art_stmt->bind_param('ss', $date_from, $date_to);
        $art_stmt->execute();
        $art_result = $art_stmt->get_result()->fetch_assoc();
        $current_art = $art_result['current_art'] ?? 0;
        $art_stmt->close();
    }
    
    return [
        'tests' => $tests,
        'positive' => $positive,
        'art_enrollment' => $positive, // Simplified
        'current_art' => $current_art
    ];
}

function getTBStatistics($mysqli, $date_from, $date_to) {
    $diagnosed = 0;
    $hiv_positive = 0;
    
    $sql = "SELECT 
        COUNT(*) as diagnosed,
        SUM(CASE WHEN t.hiv_status = 'Positive' THEN 1 ELSE 0 END) as hiv_positive
    FROM tb_patients t
    WHERE t.start_date BETWEEN ? AND ?";
    
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $diagnosed = $result['diagnosed'] ?? 0;
        $hiv_positive = $result['hiv_positive'] ?? 0;
        $stmt->close();
    }
    
    return [
        'diagnosed' => $diagnosed,
        'hiv_coinfection' => $hiv_positive,
        'treatment_success' => 0 // Would need follow-up data
    ];
}

function generateSurgicalReport($mysqli, $date_from, $date_to, $facility) {
    $report = [
        'title' => 'SURGICAL SERVICES REPORT - KENYA MOH',
        'headers' => ['Procedure Type', 'Count', 'Average Duration', 'Remarks'],
        'rows' => []
    ];
    
    // Check if surgeries table exists
    $table_check = $mysqli->query("SHOW TABLES LIKE 'surgeries'");
    if ($table_check->num_rows > 0) {
        // This would integrate with your existing OT reports
        $surgery_sql = "SELECT 
            st.type_name,
            COUNT(*) as count,
            AVG(s.estimated_duration_minutes) as avg_duration
        FROM surgeries s
        LEFT JOIN surgery_types st ON s.surgery_type_id = st.type_id
        WHERE s.scheduled_date BETWEEN ? AND ?
        GROUP BY st.type_name";
        
        $stmt = $mysqli->prepare($surgery_sql);
        if ($stmt) {
            $stmt->bind_param('ss', $date_from, $date_to);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $avg_duration = round($row['avg_duration'] ?? 0);
                $report['rows'][] = [
                    $row['type_name'] ?? 'Unknown',
                    $row['count'] ?? 0,
                    $avg_duration . ' min',
                    'Surgical procedures'
                ];
            }
            $stmt->close();
        }
    } else {
        $report['rows'][] = ['No surgical data available', 0, '0 min', 'Surgery module not configured'];
    }
    
    if (empty($report['rows'])) {
        $report['rows'][] = ['No surgical procedures', 0, '0 min', 'No surgeries in selected period'];
    }
    
    return $report;
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-file-medical-alt mr-2"></i>
                    Kenya MOH Reports
                </h3>
                <small class="text-white-50">Ministry of Health Kenya Reporting System</small>
            </div>
            <div class="card-tools">
                <button type="button" class="btn btn-light" onclick="printMOHReport()">
                    <i class="fas fa-print mr-2"></i>Print MOH Report
                </button>
            </div>
        </div>
    </div>

    <!-- Report Filters -->
    <div class="card-header bg-light">
        <form method="get" autocomplete="off" id="mohReportForm">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select class="form-control select2" name="report_type" onchange="this.form.submit()">
                            <option value="hmis_summary" <?= $report_type === 'hmis_summary' ? 'selected' : '' ?>>HMIS Summary</option>
                            <option value="maternity_report" <?= $report_type === 'maternity_report' ? 'selected' : '' ?>>Maternity Services</option>
                            <option value="hiv_tb_report" <?= $report_type === 'hiv_tb_report' ? 'selected' : '' ?>>HIV & TB Services</option>
                            <option value="child_health_report" <?= $report_type === 'child_health_report' ? 'selected' : '' ?>>Child Health</option>
                            <option value="surgical_report" <?= $report_type === 'surgical_report' ? 'selected' : '' ?>>Surgical Services</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>County</label>
                        <select class="form-control select2" name="county">
                            <option value="">All Counties</option>
                            <option value="Nairobi" <?= $county === 'Nairobi' ? 'selected' : '' ?>>Nairobi</option>
                            <option value="Mombasa" <?= $county === 'Mombasa' ? 'selected' : '' ?>>Mombasa</option>
                            <option value="Kisumu" <?= $county === 'Kisumu' ? 'selected' : '' ?>>Kisumu</option>
                            <option value="Nakuru" <?= $county === 'Nakuru' ? 'selected' : '' ?>>Nakuru</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Actions</label>
                        <div class="btn-group w-100">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-filter mr-2"></i>Generate Report
                            </button>
                            <a href="reports_moh_kenya.php" class="btn btn-secondary">
                                <i class="fas fa-redo mr-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Date Range Buttons -->
            <div class="row mt-2">
                <div class="col-md-12">
                    <div class="form-group">
                        <label>Quick Date Ranges:</label>
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('today')">Today</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('yesterday')">Yesterday</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_week')">This Week</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_week')">Last Week</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_month')">This Month</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_month')">Last Month</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_quarter')">This Quarter</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_year')">This Year</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Facility Information -->
    <div class="card-body border-bottom bg-light">
        <div class="row">
            <div class="col-md-6">
                <h5 class="text-success">Facility Information</h5>
                <table class="table table-sm table-borderless">
                    <tr>
                        <td><strong>Facility Code:</strong></td>
                        <td><?= htmlspecialchars($facility['facility_code']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Facility Name:</strong></td>
                        <td><?= htmlspecialchars($facility['facility_name']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>County:</strong></td>
                        <td><?= htmlspecialchars($facility['county']) ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h5 class="text-success">Report Details</h5>
                <table class="table table-sm table-borderless">
                    <tr>
                        <td><strong>Report Period:</strong></td>
                        <td><?= date('F j, Y', strtotime($date_from)) ?> to <?= date('F j, Y', strtotime($date_to)) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Generated On:</strong></td>
                        <td><?= date('F j, Y \a\t g:i A') ?></td>
                    </tr>
                    <tr>
                        <td><strong>Generated By:</strong></td>
                        <td><?= htmlspecialchars($_SESSION['name'] ?? 'System') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Main Report Content -->
    <div class="card-body">
        <!-- Report Header -->
        <div class="row mb-4">
            <div class="col-md-12 text-center">
                <h4 class="text-success mb-1">REPUBLIC OF KENYA</h4>
                <h5 class="text-success mb-1">MINISTRY OF HEALTH</h5>
                <h4 class="text-primary"><?= $report_data['title'] ?></h4>
                <p class="text-muted mb-0">
                    Period: <?= date('F j, Y', strtotime($date_from)) ?> to <?= date('F j, Y', strtotime($date_to)) ?>
                </p>
            </div>
        </div>

        <!-- Report Table -->
        <?php if (!empty($report_data['rows'])): ?>
            <div class="table-responsive" id="mohReportTable">
                <table class="table table-bordered table-striped">
                    <thead class="bg-success text-white">
                        <tr>
                            <?php foreach ($report_data['headers'] as $header): ?>
                                <th><?= $header ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['rows'] as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= $cell ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-file-medical-alt fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">No Data Available</h4>
                <p class="text-muted">No report data found for the selected criteria.</p>
            </div>
        <?php endif; ?>

        <!-- MOH Report Footer -->
        <div class="row mt-5">
            <div class="col-md-6">
                <div class="border-top pt-3">
                    <h6>Prepared By:</h6>
                    <p class="mb-0">_________________________</p>
                    <small class="text-muted">Health Records Officer</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border-top pt-3">
                    <h6>Approved By:</h6>
                    <p class="mb-0">_________________________</p>
                    <small class="text-muted">Facility In-Charge</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MOH Submission Card -->
<div class="card mt-4">
    <div class="card-header bg-warning">
        <h5 class="card-title mb-0 text-white">
            <i class="fas fa-paper-plane mr-2"></i>MOH Submission
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <p class="mb-2"><strong>Submission Status:</strong> 
                    <span class="badge badge-secondary">Draft</span>
                </p>
                <p class="mb-0 text-muted">
                    This report is ready for submission to the Kenya Ministry of Health.
                    Ensure all data has been verified before submission.
                </p>
            </div>
            <div class="col-md-4 text-right">
                <button class="btn btn-success" onclick="submitToMOH()">
                    <i class="fas fa-paper-plane mr-2"></i>Submit to MOH
                </button>
                <button class="btn btn-outline-primary ml-2" onclick="saveAsDraft()">
                    <i class="fas fa-save mr-2"></i>Save Draft
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });
});

function setDateRange(range) {
    const today = new Date();
    let fromDate, toDate;

    switch(range) {
        case 'today':
            fromDate = today;
            toDate = today;
            break;
        case 'yesterday':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - 1);
            toDate = new Date(fromDate);
            break;
        case 'this_week':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - today.getDay());
            toDate = new Date(today);
            toDate.setDate(today.getDate() + (6 - today.getDay()));
            break;
        case 'last_week':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - today.getDay() - 7);
            toDate = new Date(fromDate);
            toDate.setDate(fromDate.getDate() + 6);
            break;
        case 'this_month':
            fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
            toDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'last_month':
            fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            toDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        case 'this_quarter':
            const quarter = Math.floor(today.getMonth() / 3);
            fromDate = new Date(today.getFullYear(), quarter * 3, 1);
            toDate = new Date(today.getFullYear(), (quarter + 1) * 3, 0);
            break;
        case 'this_year':
            fromDate = new Date(today.getFullYear(), 0, 1);
            toDate = new Date(today.getFullYear(), 11, 31);
            break;
    }

    $('input[name="date_from"]').val(formatDate(fromDate));
    $('input[name="date_to"]').val(formatDate(toDate));
}

function formatDate(date) {
    return date.toISOString().split('T')[0];
}

function printMOHReport() {
    const printContent = document.getElementById('mohReportTable').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <html>
            <head>
                <title>MOH Report - Kenya</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.0/css/bootstrap.min.css">
                <style>
                    body { padding: 20px; font-family: Arial, sans-serif; }
                    .table { font-size: 12px; border: 1px solid #000; }
                    .table th { background-color: #28a745 !important; color: white; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .footer { margin-top: 30px; border-top: 1px solid #000; padding-top: 10px; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h4>REPUBLIC OF KENYA</h4>
                    <h5>MINISTRY OF HEALTH</h5>
                    <h4><?= $report_data['title'] ?></h4>
                    <p>
                        Facility: <?= htmlspecialchars($facility['facility_name']) ?> | 
                        Period: <?= date('F j, Y', strtotime($date_from)) ?> to <?= date('F j, Y', strtotime($date_to)) ?> | 
                        Generated: <?= date('M j, Y') ?>
                    </p>
                </div>
                ${printContent}
                <div class="footer row">
                    <div class="col-md-6">
                        <p>Prepared By: _________________________</p>
                        <small>Health Records Officer</small>
                    </div>
                    <div class="col-md-6">
                        <p>Approved By: _________________________</p>
                        <small>Facility In-Charge</small>
                    </div>
                </div>
            </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

function submitToMOH() {
    if (confirm('Are you sure you want to submit this report to the Ministry of Health?')) {
        alert('Report submission functionality would be implemented here');
    }
}

function saveAsDraft() {
    alert('Draft saving functionality would be implemented here');
}
</script>
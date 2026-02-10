<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';


// Get patient_id from URL
$patient_id = intval($_GET['patient_id'] ?? 0);

if ($patient_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid patient ID";
    header("Location: patients.php");
    exit;
}

// Get patient details
$patient_sql = "SELECT * FROM patients WHERE patient_id = ?";
$patient_stmt = mysqli_prepare($mysqli, $patient_sql);
mysqli_stmt_bind_param($patient_stmt, "i", $patient_id);
mysqli_stmt_execute($patient_stmt);
$patient_result = mysqli_stmt_get_result($patient_stmt);
$patient = mysqli_fetch_assoc($patient_result);

if (!$patient) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Patient not found";
    header("Location: patients.php");
    exit;
}

// Get patient visits history
$visits_sql = "SELECT v.*, w.ward_name, b.bed_number, 
                      doctor_user.user_name AS doctor_name
               FROM visits v
               LEFT JOIN wards w ON v.visit_ward_id = w.ward_id
               LEFT JOIN beds b ON v.visit_bed_id = b.bed_id
               LEFT JOIN users doctor_user ON v.visit_doctor_id = doctor_user.user_id
               WHERE v.visit_patient_id = ?
               ORDER BY v.visit_date DESC, v.visit_created_at DESC";

$visits_stmt = mysqli_prepare($mysqli, $visits_sql);
mysqli_stmt_bind_param($visits_stmt, 'i', $patient_id);
mysqli_stmt_execute($visits_stmt);
$visits_result = mysqli_stmt_get_result($visits_stmt);

// Get vital signs for this visit
$vitals_sql = "SELECT pv.*, u.user_name AS recorded_by_name
               FROM patient_vitals pv
               LEFT JOIN users u ON pv.recorded_by = u.user_id
               WHERE pv.visit_id = ?
               ORDER BY pv.recorded_at DESC";

$vitals_stmt = mysqli_prepare($mysqli, $vitals_sql);
mysqli_stmt_bind_param($vitals_stmt, "i", $visit_id);
mysqli_stmt_execute($vitals_stmt);
$vitals_result = mysqli_stmt_get_result($vitals_stmt);

// Get nurse assignments for this patient
$assignments_sql = "SELECT pna.*, v.visit_id, v.visit_type, v.visit_date,
                           u.user_name as nurse_name, 
                           au.user_name as assigned_by_name,
                           w.ward_name, b.bed_number
                    FROM patient_nurse_assignments pna
                    JOIN visits v ON pna.visit_id = v.visit_id
                    JOIN users u ON pna.nurse_id = u.user_id
                    JOIN users au ON pna.assigned_by = au.user_id
                    LEFT JOIN wards w ON v.visit_ward_id = w.ward_id
                    LEFT JOIN beds b ON v.visit_bed_id = b.bed_id
                    WHERE pna.patient_id = ?
                    ORDER BY pna.assignment_date DESC, pna.assigned_at DESC";
$assignments_stmt = mysqli_prepare($mysqli, $assignments_sql);
mysqli_stmt_bind_param($assignments_stmt, "i", $patient_id);
mysqli_stmt_execute($assignments_stmt);
$assignments_result = mysqli_stmt_get_result($assignments_stmt);
// Get medications (prescriptions) for this visit
$medications_sql = "SELECT 
                        pi.*, 
                        d.drug_name AS medication_name,
                        u.user_name AS prescribed_by_name,
                        p.prescription_date,
                        p.prescription_status
                    FROM prescription_items pi
                    JOIN prescriptions p 
                        ON pi.pi_prescription_id = p.prescription_id
                    JOIN drugs d 
                        ON pi.pi_drug_id = d.drug_id
                    LEFT JOIN users u 
                        ON p.prescription_doctor_id = u.user_id
                    WHERE p.prescription_patient_id = (
                        SELECT visit_patient_id 
                        FROM visits 
                        WHERE visit_id = ?
                    )
                    ORDER BY p.prescription_date DESC";

$medications_stmt = mysqli_prepare($mysqli, $medications_sql);
mysqli_stmt_bind_param($medications_stmt, "i", $visit_id);
mysqli_stmt_execute($medications_stmt);
$medications_result = mysqli_stmt_get_result($medications_stmt);


// Prepare data for charts
$vitals_chart_data = [];
$blood_pressure_data = [];
$heart_rate_data = [];
$temperature_data = [];
$vitals_for_chart = [];

// Process vital signs for charting
if (mysqli_num_rows($vitals_result) > 0) {
    mysqli_data_seek($vitals_result, 0);
    while ($vital = mysqli_fetch_assoc($vitals_result)) {
        $timestamp = strtotime($vital['recorded_at']) * 1000; // Convert to milliseconds for JavaScript
        $vitals_for_chart[] = [
            'timestamp' => $timestamp,
            'blood_pressure' => $vital['blood_pressure'],
            'heart_rate' => $vital['heart_rate'],
            'temperature' => $vital['temperature'],
            'respiratory_rate' => $vital['respiratory_rate'],
            'oxygen_saturation' => $vital['oxygen_saturation']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Chart - <?php echo htmlspecialchars($patient['patient_name']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card {
            margin-bottom: 20px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .patient-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .vital-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s;
        }
        .vital-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .vital-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #007bff;
        }
        .vital-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        .badge-status-admitted { background-color: #17a2b8; }
        .badge-status-in-progress { background-color: #28a745; }
        .badge-status-discharged { background-color: #6c757d; }
        .badge-type-ipd { background-color: #007bff; }
        .badge-type-opd { background-color: #6f42c1; }
        .badge-type-er { background-color: #dc3545; }
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #007bff;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 20px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #007bff;
            border: 2px solid white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Patient Header -->
        <div class="row">
            <div class="col-md-12">
                <div class="patient-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1">
                                <i class="fas fa-user-injured mr-2"></i>
                                <?php echo htmlspecialchars($patient['patient_name']); ?>
                            </h2>
                            <p class="mb-0">MRN: <?php echo htmlspecialchars($patient['patient_mrn']); ?> | 
                                DOB: <?php echo date('M j, Y', strtotime($patient['patient_dob'])); ?> | 
                                Gender: <?php echo htmlspecialchars($patient['patient_gender']); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <a href="visits.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-light">
                                <i class="fas fa-file-medical mr-1"></i> View Visits
                            </a>
                            <a href="patients.php" class="btn btn-outline-light">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Patients
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row">
            <div class="col-md-3">
                <div class="vital-card">
                    <div class="vital-value"><?php echo mysqli_num_rows($visits_result); ?></div>
                    <div class="vital-label">Total Visits</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="vital-card">
                    <div class="vital-value"><?php echo mysqli_num_rows($vitals_result); ?></div>
                    <div class="vital-label">Vital Records</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="vital-card">
                    <div class="vital-value"><?php echo mysqli_num_rows($assignments_result); ?></div>
                    <div class="vital-label">Nurse Assignments</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="vital-card">
                    <div class="vital-value"><?php echo mysqli_num_rows($medications_result); ?></div>
                    <div class="vital-label">Medications</div>
                </div>
            </div>
        </div>

        <!-- Vital Signs Charts -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line mr-2"></i>Vital Signs Trends
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($vitals_for_chart) > 0): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="chart-container">
                                        <canvas id="heartRateChart"></canvas>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="chart-container">
                                        <canvas id="temperatureChart"></canvas>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="chart-container">
                                        <canvas id="bloodPressureChart"></canvas>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="chart-container">
                                        <canvas id="oxygenChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>No vital signs data available for charting.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity Timeline -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history mr-2"></i>Recent Activity
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <?php 
                            // Combine and sort recent activities
                            $activities = [];
                            
                            // Add visits to activities
                            mysqli_data_seek($visits_result, 0);
                            while ($visit = mysqli_fetch_assoc($visits_result)) {
                                $activities[] = [
                                    'type' => 'visit',
                                    'date' => $visit['visit_created_at'],
                                    'title' => 'New ' . $visit['visit_type'] . ' Visit',
                                    'description' => 'Visit status: ' . $visit['visit_status'],
                                    'badge' => $visit['visit_type']
                                ];
                            }
                            
                            // Add vital signs to activities
                            mysqli_data_seek($vitals_result, 0);
                            while ($vital = mysqli_fetch_assoc($vitals_result)) {
                                $activities[] = [
                                    'type' => 'vital',
                                    'date' => $vital['recorded_at'],
                                    'title' => 'Vital Signs Recorded',
                                    'description' => 'BP: ' . ($vital['blood_pressure'] ?? 'N/A') . ', HR: ' . ($vital['heart_rate'] ?? 'N/A'),
                                    'badge' => 'Vitals'
                                ];
                            }
                            
                            // Sort activities by date (newest first)
                            usort($activities, function($a, $b) {
                                return strtotime($b['date']) - strtotime($a['date']);
                            });
                            
                            // Display latest 10 activities
                            $display_activities = array_slice($activities, 0, 10);
                            
                            if (count($display_activities) > 0): 
                                foreach ($display_activities as $activity): 
                            ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($activity['title']); ?></h6>
                                        <span class="badge badge-<?php echo strtolower($activity['badge']); ?>">
                                            <?php echo htmlspecialchars($activity['badge']); ?>
                                        </span>
                                    </div>
                                    <p class="mb-1 text-muted"><?php echo htmlspecialchars($activity['description']); ?></p>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y H:i', strtotime($activity['date'])); ?>
                                    </small>
                                </div>
                            <?php 
                                endforeach;
                            else: 
                            ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i>No recent activity found.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Medications -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-pills mr-2"></i>Current Medications
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $current_meds = [];
                        mysqli_data_seek($medications_result, 0);
                        while ($med = mysqli_fetch_assoc($medications_result)) {
                            if ($med['status'] == 'active' && (!$med['end_date'] || strtotime($med['end_date']) >= time())) {
                                $current_meds[] = $med;
                            }
                        }
                        ?>
                        
                        <?php if (count($current_meds) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Medication</th>
                                            <th>Dosage</th>
                                            <th>Frequency</th>
                                            <th>Start Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($current_meds as $med): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($med['medication_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($med['medication_type']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($med['dosage']); ?></td>
                                                <td><?php echo htmlspecialchars($med['frequency']); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($med['start_date'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>No current medications.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Nurse Assignments -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-nurse mr-2"></i>Recent Nurse Assignments
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $recent_assignments = [];
                        mysqli_data_seek($assignments_result, 0);
                        while ($assignment = mysqli_fetch_assoc($assignments_result)) {
                            if ($assignment['status'] == 'active') {
                                $recent_assignments[] = $assignment;
                            }
                        }
                        $recent_assignments = array_slice($recent_assignments, 0, 5);
                        ?>
                        
                        <?php if (count($recent_assignments) > 0): ?>
                            <?php foreach ($recent_assignments as $assignment): ?>
                                <div class="mb-3 p-3 border rounded">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($assignment['nurse_name']); ?></h6>
                                            <p class="mb-1 text-muted">
                                                <?php echo ucfirst($assignment['shift']); ?> shift • 
                                                <?php echo ucfirst($assignment['assignment_type']); ?>
                                            </p>
                                            <?php if ($assignment['ward_name']): ?>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($assignment['ward_name']); ?> 
                                                    / Bed <?php echo $assignment['bed_number']; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge badge-success">Active</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>No active nurse assignments.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        <?php if (count($vitals_for_chart) > 0): ?>
            // Prepare chart data
            const timestamps = <?php echo json_encode(array_column($vitals_for_chart, 'timestamp')); ?>;
            const heartRates = <?php echo json_encode(array_column($vitals_for_chart, 'heart_rate')); ?>;
            const temperatures = <?php echo json_encode(array_column($vitals_for_chart, 'temperature')); ?>;
            const bloodPressures = <?php echo json_encode(array_column($vitals_for_chart, 'blood_pressure')); ?>;
            const oxygenLevels = <?php echo json_encode(array_column($vitals_for_chart, 'oxygen_saturation')); ?>;

            // Heart Rate Chart
            new Chart(document.getElementById('heartRateChart'), {
                type: 'line',
                data: {
                    labels: timestamps,
                    datasets: [{
                        label: 'Heart Rate (bpm)',
                        data: heartRates,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'day'
                            }
                        },
                        y: {
                            beginAtZero: false,
                            title: {
                                display: true,
                                text: 'BPM'
                            }
                        }
                    }
                }
            });

            // Temperature Chart
            new Chart(document.getElementById('temperatureChart'), {
                type: 'line',
                data: {
                    labels: timestamps,
                    datasets: [{
                        label: 'Temperature (°C)',
                        data: temperatures,
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'day'
                            }
                        },
                        y: {
                            beginAtZero: false,
                            title: {
                                display: true,
                                text: '°C'
                            }
                        }
                    }
                }
            });

            // Oxygen Saturation Chart
            new Chart(document.getElementById('oxygenChart'), {
                type: 'line',
                data: {
                    labels: timestamps,
                    datasets: [{
                        label: 'Oxygen Saturation (%)',
                        data: oxygenLevels,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'day'
                            }
                        },
                        y: {
                            beginAtZero: false,
                            min: 90,
                            max: 100,
                            title: {
                                display: true,
                                text: '%'
                            }
                        }
                    }
                }
            });

        <?php endif; ?>
    });
    </script>
</body>
</html>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['id']);

// Get surgical case details
$sql = "SELECT sc.*, 
               p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob, 
               p.patient_phone, p.patient_email, 
               ps.user_name as surgeon_name,
               a.user_name as anesthetist_name,
               rd.user_name as referring_doctor_name,
               t.theatre_name, t.theatre_number,
               creator.user_name as created_by_name,
               TIMESTAMPDIFF(MINUTE, sc.surgery_start_time, sc.surgery_end_time) as actual_duration
        FROM surgical_cases sc
        LEFT JOIN patients p ON sc.patient_id = p.patient_id
        LEFT JOIN users ps ON sc.primary_surgeon_id = ps.user_id
        LEFT JOIN users a ON sc.anesthetist_id = a.user_id
        LEFT JOIN users rd ON sc.referring_doctor_id = rd.user_id
        LEFT JOIN theatres t ON sc.theater_id = t.theatre_id
        LEFT JOIN users creator ON sc.created_by = creator.user_id
        WHERE sc.case_id = $case_id";

$result = $mysqli->query($sql);

if ($result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$case = $result->fetch_assoc();

// Get additional statistics
$stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM surgical_complications WHERE case_id = $case_id) as total_complications,
        (SELECT COUNT(*) FROM surgical_documents WHERE case_id = $case_id) as total_documents,
        (SELECT COUNT(*) FROM surgical_team WHERE case_id = $case_id) as total_team_members,
        (SELECT COUNT(*) FROM surgical_equipment_usage WHERE case_id = $case_id) as total_equipment
";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get complications by severity
$complications_sql = "SELECT  COUNT(*) as count FROM surgical_complications WHERE case_id = $case_id ";
$complications_result = $mysqli->query($complications_sql);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case Detail Report: <?php echo htmlspecialchars($case['case_number']); ?></title>
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .stat-card {
            border-radius: 5px;
            padding: 15px;
            color: white;
            margin-bottom: 15px;
            text-align: center;
        }
        .stat-card.primary { background-color: #007bff; }
        .stat-card.success { background-color: #28a745; }
        .stat-card.warning { background-color: #ffc107; color: #212529; }
        .stat-card.danger { background-color: #dc3545; }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 30px;
        }
        .timeline-chart {
            height: 200px;
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
        }
        .timeline-event {
            border-left: 3px solid #007bff;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .timeline-event.completed {
            border-left-color: #28a745;
        }
        .timeline-event.pending {
            border-left-color: #ffc107;
        }
        .print-only {
            display: none;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            body {
                font-size: 12px;
            }
            .chart-container {
                height: 200px;
            }
        }
    </style>
</head>
<body>

    
    <div class="container-fluid">
        <div class="row">
        
            <div class="col-lg-10">
                <!-- Report Header -->
                <div class="report-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h2 class="mb-1">Surgical Case Detail Report</h2>
                            <p class="mb-0">Case: <?php echo htmlspecialchars($case['case_number']); ?> | 
                            Patient: <?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?> |
                            Generated: <?php echo date('M j, Y H:i'); ?></p>
                        </div>
                        <div class="col-md-4 text-right">
                            <div class="btn-group no-print">
                                <button onclick="window.print()" class="btn btn-light">
                                    <i class="fas fa-print mr-2"></i>Print
                                </button>
                                <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-light">
                                    <i class="fas fa-eye mr-2"></i>View Case
                                </a>
                                <a href="theatre_dashboard.php" class="btn btn-light">
                                    <i class="fas fa-arrow-left mr-2"></i>Back
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card primary">
                            <h3 class="mb-0"><?php echo $stats['total_documents'] ?? 0; ?></h3>
                            <p class="mb-0">Documents</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card success">
                            <h3 class="mb-0"><?php echo $stats['total_team_members'] ?? 0; ?></h3>
                            <p class="mb-0">Team Members</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card warning">
                            <h3 class="mb-0"><?php echo $stats['total_complications'] ?? 0; ?></h3>
                            <p class="mb-0">Complications</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card danger">
                            <h3 class="mb-0"><?php echo $stats['total_equipment'] ?? 0; ?></h3>
                            <p class="mb-0">Equipment Used</p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <!-- Case Overview -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Case Overview</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Case Number:</th>
                                                <td><?php echo htmlspecialchars($case['case_number']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status:</th>
                                                <td>
                                                    <?php
                                                    $status_badge = '';
                                                    switch($case['case_status']) {
                                                        case 'referred': $status_badge = 'badge-info'; break;
                                                        case 'scheduled': $status_badge = 'badge-primary'; break;
                                                        case 'in_or': $status_badge = 'badge-warning'; break;
                                                        case 'completed': $status_badge = 'badge-success'; break;
                                                        case 'cancelled': $status_badge = 'badge-danger'; break;
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $status_badge; ?>">
                                                        <?php echo strtoupper(str_replace('_', ' ', $case['case_status'])); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Urgency:</th>
                                                <td>
                                                    <?php
                                                    $urgency_badge = '';
                                                    switch($case['surgical_urgency']) {
                                                        case 'emergency': $urgency_badge = 'badge-danger'; break;
                                                        case 'urgent': $urgency_badge = 'badge-warning'; break;
                                                        case 'elective': $urgency_badge = 'badge-info'; break;
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $urgency_badge; ?>">
                                                        <?php echo ucfirst($case['surgical_urgency']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Specialty:</th>
                                                <td><?php echo htmlspecialchars($case['surgical_specialty']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>ASA Score:</th>
                                                <td><?php echo $case['asa_score'] ?: 'N/A'; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Patient:</th>
                                                <td><?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>MRN:</th>
                                                <td><?php echo htmlspecialchars($case['patient_mrn']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Surgeon:</th>
                                                <td><?php echo htmlspecialchars($case['surgeon_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Anesthetist:</th>
                                                <td><?php echo htmlspecialchars($case['anesthetist_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Theatre:</th>
                                                <td><?php echo htmlspecialchars($case['theatre_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <h6>Procedure Details</h6>
                                        <div class="border rounded p-3 mb-3">
                                            <p><strong>Pre-op Diagnosis:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($case['pre_op_diagnosis'])); ?></p>
                                            <p><strong>Planned Procedure:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($case['planned_procedure'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Timeline Chart -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-stream mr-2"></i>Case Timeline</h5>
                            </div>
                            <div class="card-body">
                                <div class="timeline-chart">
                                    <?php
                                    $timeline_events = [];
                                    if ($case['presentation_date']) {
                                        $timeline_events[] = [
                                            'date' => $case['presentation_date'],
                                            'event' => 'Presentation',
                                            'status' => 'completed'
                                        ];
                                    }
                                    if ($case['decision_date']) {
                                        $timeline_events[] = [
                                            'date' => $case['decision_date'],
                                            'event' => 'Decision',
                                            'status' => 'completed'
                                        ];
                                    }
                                    if ($case['target_or_date']) {
                                        $timeline_events[] = [
                                            'date' => $case['target_or_date'],
                                            'event' => 'Target OR Date',
                                            'status' => 'completed'
                                        ];
                                    }
                                    if ($case['surgery_date']) {
                                        $timeline_events[] = [
                                            'date' => $case['surgery_date'],
                                            'event' => 'Surgery',
                                            'status' => 'completed'
                                        ];
                                    }
                                    ?>
                                    
                                    <?php foreach($timeline_events as $event): ?>
                                    <div class="timeline-event <?php echo $event['status']; ?>">
                                        <h6 class="mb-1"><?php echo $event['event']; ?></h6>
                                        <p class="mb-0 text-muted"><?php echo date('M j, Y', strtotime($event['date'])); ?></p>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if($case['case_status'] == 'completed'): ?>
                                    <div class="timeline-event completed">
                                        <h6 class="mb-1">Case Completed</h6>
                                        <p class="mb-0 text-muted"><?php echo date('M j, Y', strtotime($case['updated_at'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Complications Chart -->
                        <?php if($stats['total_complications'] > 0): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-exclamation-triangle mr-2"></i>Complications Overview</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="complicationsChart"></canvas>
                                </div>
                                
                                <?php
                                $complication_data = [];
                                $complication_labels = [];
                                $complication_colors = [];
                                
                                while($row = $complications_result->fetch_assoc()) {
                                    $complication_labels[] = ucfirst($row['severity']);
                                    $complication_data[] = $row['count'];
                                    
                                    switch($row['severity']) {
                                        case 'critical': $complication_colors[] = '#dc3545'; break;
                                        case 'severe': $complication_colors[] = '#fd7e14'; break;
                                        case 'moderate': $complication_colors[] = '#ffc107'; break;
                                        case 'minor': $complication_colors[] = '#28a745'; break;
                                        default: $complication_colors[] = '#6c757d';
                                    }
                                }
                                ?>
                                
                                <script>
                                var ctx = document.getElementById('complicationsChart').getContext('2d');
                                var complicationsChart = new Chart(ctx, {
                                    type: 'doughnut',
                                    data: {
                                        labels: <?php echo json_encode($complication_labels); ?>,
                                        datasets: [{
                                            data: <?php echo json_encode($complication_data); ?>,
                                            backgroundColor: <?php echo json_encode($complication_colors); ?>,
                                            borderWidth: 1
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: {
                                            legend: {
                                                position: 'bottom'
                                            }
                                        }
                                    }
                                });
                                </script>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Equipment Usage -->
                        <?php if($equipment_result->num_rows > 0): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-tools mr-2"></i>Equipment Usage</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Equipment</th>
                                                <th>Quantity</th>
                                                <th>Duration</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($equipment = $equipment_result->fetch_assoc()): 
                                                $duration = 'N/A';
                                                if ($equipment['usage_start_time'] && $equipment['usage_end_time']) {
                                                    $start = strtotime($equipment['usage_start_time']);
                                                    $end = strtotime($equipment['usage_end_time']);
                                                    $hours = floor(($end - $start) / 3600);
                                                    $minutes = floor((($end - $start) % 3600) / 60);
                                                    $duration = $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($equipment['equipment_name']); ?></td>
                                                <td><?php echo $equipment['quantity_used']; ?></td>
                                                <td><?php echo $duration; ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Case Metrics -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-chart-line mr-2"></i>Case Metrics</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td>Estimated Duration:</td>
                                        <td class="text-right"><?php echo $case['estimated_duration_minutes'] ? $case['estimated_duration_minutes'] . ' min' : 'N/A'; ?></td>
                                    </tr>
                                    <tr>
                                        <td>Actual Duration:</td>
                                        <td class="text-right"><?php echo $case['actual_duration'] ? $case['actual_duration'] . ' min' : 'N/A'; ?></td>
                                    </tr>
                                    <tr>
                                        <td>Variance:</td>
                                        <td class="text-right">
                                            <?php 
                                            if ($case['estimated_duration_minutes'] && $case['actual_duration']) {
                                                $variance = $case['actual_duration'] - $case['estimated_duration_minutes'];
                                                $variance_class = $variance > 0 ? 'text-danger' : 'text-success';
                                                echo '<span class="' . $variance_class . '">' . ($variance > 0 ? '+' : '') . $variance . ' min</span>';
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>Case Age:</td>
                                        <td class="text-right">
                                            <?php
                                            $created = new DateTime($case['created_at']);
                                            $now = new DateTime();
                                            $interval = $created->diff($now);
                                            echo $interval->days . ' days';
                                            ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Section -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-file-alt mr-2"></i>Report Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <h6>Case Summary</h6>
                                <p>
                                    Surgical case <?php echo htmlspecialchars($case['case_number']); ?> 
                                    for patient <?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?> 
                                    (MRN: <?php echo htmlspecialchars($case['patient_mrn']); ?>) 
                                    was <?php echo $case['case_status']; ?> on 
                                    <?php echo date('M j, Y', strtotime($case['updated_at'])); ?>.
                                </p>
                                
                                <?php if($stats['total_complications'] > 0): ?>
                                <p>
                                    <strong>Note:</strong> This case had <?php echo $stats['total_complications']; ?> 
                                    complication<?php echo $stats['total_complications'] > 1 ? 's' : ''; ?> 
                                    recorded.
                                </p>
                                <?php endif; ?>
                                
                                <h6>Key Performance Indicators</h6>
                                <ul>
                                    <li>Case Status: <?php echo ucfirst($case['case_status']); ?></li>
                                    <li>Complication Rate: <?php echo $stats['total_complications'] > 0 ? 'Present' : 'None'; ?></li>
                                    <li>Documentation: <?php echo $stats['total_documents']; ?> document(s)</li>
                                    <li>Team Size: <?php echo $stats['total_team_members']; ?> member(s)</li>
                                    <li>Equipment Used: <?php echo $stats['total_equipment']; ?> item(s)</li>
                                </ul>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Report Information:</strong> This report was generated on 
                                    <?php echo date('M j, Y \a\t H:i'); ?> by 
                                    <?php echo $_SESSION['user_name'] ?? 'System'; ?>.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
</body>
</html>
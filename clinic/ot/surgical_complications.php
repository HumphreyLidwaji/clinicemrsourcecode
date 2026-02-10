<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['case_id']);

// Get case details
$case_sql = "SELECT sc.*, p.patient_first_name, p.patient_last_name, p.patient_mrn 
             FROM surgical_cases sc 
             LEFT JOIN patients p ON sc.patient_id = p.patient_id 
             WHERE sc.case_id = $case_id";
$case_result = $mysqli->query($case_sql);

if ($case_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$case = $case_result->fetch_assoc();

// Handle resolution of complication
if (isset($_GET['resolve_complication'])) {
    $complication_id = intval($_GET['resolve_complication']);
    $resolution_date = date('Y-m-d');
    
    $update_sql = "UPDATE surgical_complications SET 
                   complication_status = 'resolved',
                   resolution_date = '$resolution_date',
                   outcome = 'Resolved'
                   WHERE complication_id = $complication_id";
    
    if ($mysqli->query($update_sql)) {
        // Log activity
        $log_description = "Resolved complication for case: " . $case['case_number'];
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Complication', log_action = 'Resolve', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_message'] = "Complication marked as resolved";
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error resolving complication: " . $mysqli->error;
    }
    
    header("Location: surgical_complications.php?case_id=$case_id");
    exit();
}

// Handle deletion of complication
if (isset($_GET['delete_complication'])) {
    $complication_id = intval($_GET['delete_complication']);
    
    $delete_sql = "DELETE FROM surgical_complications WHERE complication_id = $complication_id";
    
    if ($mysqli->query($delete_sql)) {
        // Log activity
        $log_description = "Deleted complication from case: " . $case['case_number'];
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Complication', log_action = 'Delete', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_message'] = "Complication deleted successfully";
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error deleting complication: " . $mysqli->error;
    }
    
    header("Location: surgical_complications.php?case_id=$case_id");
    exit();
}

// Get complications for this case
$complications_sql = "SELECT sc.*, 
                             u.user_name as reported_by_name,
                             d.user_name as detected_by_name,
                             DATEDIFF(CURDATE(), sc.occurred_at) as days_since_occurrence,
                             DATEDIFF(sc.resolution_date, CURDATE()) as days_until_resolution
                      FROM surgical_complications sc 
                      LEFT JOIN users u ON sc.reported_by = u.user_id
                      LEFT JOIN users d ON sc.detected_by = d.user_id
                      WHERE sc.case_id = $case_id 
                      ORDER BY sc.occurred_at DESC";
$complications_result = $mysqli->query($complications_sql);

// Get statistics
$total_complications = $complications_result->num_rows;
$active_count = 0;
$resolved_count = 0;
$critical_count = 0;
$intraoperative_count = 0;
$postoperative_count = 0;

// Clavien-Dindo distribution
$clavien_counts = [
    'I' => 0, 'II' => 0, 'IIIa' => 0, 'IIIb' => 0, 
    'IVa' => 0, 'IVb' => 0, 'V' => 0
];

// Severity distribution
$severity_counts = [
    'Minor' => 0, 'Moderate' => 0, 'Severe' => 0, 'Critical' => 0
];

// Reset pointer for statistics
mysqli_data_seek($complications_result, 0);
while ($comp = $complications_result->fetch_assoc()) {
    if ($comp['complication_status'] == 'active') {
        $active_count++;
    } elseif ($comp['complication_status'] == 'resolved') {
        $resolved_count++;
    }
    
    if ($comp['severity'] == 'Critical') {
        $critical_count++;
    }
    
    if ($comp['intraoperative']) {
        $intraoperative_count++;
    }
    
    if ($comp['postoperative']) {
        $postoperative_count++;
    }
    
    // Count Clavien-Dindo grades
    if (isset($clavien_counts[$comp['clavien_dindo_grade']])) {
        $clavien_counts[$comp['clavien_dindo_grade']]++;
    }
    
    // Count severity levels
    if ($comp['severity'] && isset($severity_counts[$comp['severity']])) {
        $severity_counts[$comp['severity']]++;
    }
}
mysqli_data_seek($complications_result, 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surgical Complications - Case: <?php echo htmlspecialchars($case['case_number']); ?></title>
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
    <style>
        .case-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .clavien-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .clavien-I { background-color: #28a745; color: white; }
        .clavien-II { background-color: #ffc107; color: #212529; }
        .clavien-IIIa { background-color: #fd7e14; color: white; }
        .clavien-IIIb { background-color: #dc3545; color: white; }
        .clavien-IVa { background-color: #6f42c1; color: white; }
        .clavien-IVb { background-color: #e83e8c; color: white; }
        .clavien-V { background-color: #343a40; color: white; }
        .severity-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.8em;
            margin-right: 5px;
        }
        .severity-minor { background-color: #28a745; color: white; }
        .severity-moderate { background-color: #ffc107; color: #212529; }
        .severity-severe { background-color: #fd7e14; color: white; }
        .severity-critical { background-color: #dc3545; color: white; }
        .status-badge {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.8em;
        }
        .status-active { background-color: #dc3545; color: white; }
        .status-resolved { background-color: #28a745; color: white; }
        .complication-card {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .complication-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .complication-card.active {
            border-left: 4px solid #dc3545;
        }
        .complication-card.resolved {
            border-left: 4px solid #28a745;
            opacity: 0.8;
        }
        .stat-card {
            border-radius: 5px;
            padding: 15px;
            color: white;
            margin-bottom: 15px;
            text-align: center;
        }
        .stat-card.primary { background-color: #007bff; }
        .stat-card.danger { background-color: #dc3545; }
        .stat-card.success { background-color: #28a745; }
        .stat-card.warning { background-color: #ffc107; color: #212529; }
        .stat-card.info { background-color: #17a2b8; }
        .timeline-marker {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .timeline-intra { background-color: #007bff; }
        .timeline-post { background-color: #6c757d; }
        .complication-type {
            display: inline-block;
            background-color: #e9ecef;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.8em;
            margin-right: 5px;
        }
        .dropdown-menu-lg {
            min-width: 300px;
        }
        .chart-container {
            height: 200px;
            position: relative;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <!-- Case Header -->
                <div class="case-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="mb-1"><i class="fas fa-exclamation-triangle mr-2"></i>Surgical Complications</h3>
                            <p class="mb-0">
                                Case: <?php echo htmlspecialchars($case['case_number']); ?> | 
                                Patient: <?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?> | 
                                MRN: <?php echo htmlspecialchars($case['patient_mrn']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-arrow-left mr-1"></i>Back to Case
                            </a>
                            <a href="surgical_complications_new.php?case_id=<?php echo $case_id; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-plus mr-1"></i>New Complication
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="stat-card danger">
                            <h3 class="mb-0"><?php echo $total_complications; ?></h3>
                            <p class="mb-0">Total</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-card warning">
                            <h3 class="mb-0"><?php echo $active_count; ?></h3>
                            <p class="mb-0">Active</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-card success">
                            <h3 class="mb-0"><?php echo $resolved_count; ?></h3>
                            <p class="mb-0">Resolved</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-card primary">
                            <h3 class="mb-0"><?php echo $intraoperative_count; ?></h3>
                            <p class="mb-0">Intra-op</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-card info">
                            <h3 class="mb-0"><?php echo $postoperative_count; ?></h3>
                            <p class="mb-0">Post-op</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stat-card danger">
                            <h3 class="mb-0"><?php echo $critical_count; ?></h3>
                            <p class="mb-0">Critical</p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <!-- Complications List -->
                        <div class="card mb-4">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-list mr-2"></i>Complications List
                                    <span class="badge badge-primary"><?php echo $total_complications; ?></span>
                                </h5>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#filterModal">
                                        <i class="fas fa-filter mr-1"></i>Filter
                                    </button>
                                    <a href="reports_complications.php?case_id=<?php echo $case_id; ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-chart-bar mr-1"></i>Report
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($total_complications > 0): ?>
                                    <div class="list-group">
                                        <?php while ($complication = $complications_result->fetch_assoc()): 
                                            $complication_id = $complication['complication_id'];
                                            $is_active = $complication['complication_status'] == 'active';
                                            $is_resolved = $complication['complication_status'] == 'resolved';
                                            $clavien_class = 'clavien-' . $complication['clavien_dindo_grade'];
                                            $severity_class = 'severity-' . strtolower($complication['severity']);
                                        ?>
                                        <div class="list-group-item list-group-item-action p-3 complication-card <?php echo $is_active ? 'active' : 'resolved'; ?>">
                                            <div class="d-flex w-100 justify-content-between">
                                                <div class="mb-1">
                                                    <h6 class="mb-1">
                                                        <?php echo htmlspecialchars($complication['complication_category']); ?>
                                                        <span class="<?php echo $clavien_class; ?> clavien-badge"><?php echo $complication['clavien_dindo_grade']; ?></span>
                                                        <span class="<?php echo $severity_class; ?> severity-badge"><?php echo $complication['severity']; ?></span>
                                                        <span class="status-badge status-<?php echo $is_active ? 'active' : 'resolved'; ?>">
                                                            <?php echo $is_active ? 'Active' : 'Resolved'; ?>
                                                        </span>
                                                    </h6>
                                                    <div class="small mb-2">
                                                        <span class="complication-type"><?php echo htmlspecialchars($complication['complication_type']); ?></span>
                                                        <?php if($complication['anatomical_location']): ?>
                                                            <span class="text-muted">Location: <?php echo htmlspecialchars($complication['anatomical_location']); ?></span>
                                                        <?php endif; ?>
                                                        <span class="text-muted ml-2">
                                                            <?php if($complication['intraoperative']): ?>
                                                                <span class="timeline-marker timeline-intra"></span>Intraoperative
                                                            <?php endif; ?>
                                                            <?php if($complication['postoperative']): ?>
                                                                <span class="timeline-marker timeline-post"></span>Postoperative
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y', strtotime($complication['occurred_at'])); ?>
                                                </small>
                                            </div>
                                            
                                            <p class="mb-2">
                                                <?php 
                                                $description = htmlspecialchars($complication['complication_description']);
                                                echo strlen($description) > 200 ? substr($description, 0, 200) . '...' : $description;
                                                ?>
                                            </p>
                                            
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="small">
                                                    <span class="text-muted">
                                                        <i class="fas fa-user mr-1"></i>
                                                        Reported by: <?php echo htmlspecialchars($complication['reported_by_name']); ?>
                                                    </span>
                                                    <?php if($complication['detected_by_name']): ?>
                                                        <span class="text-muted ml-3">
                                                            <i class="fas fa-search mr-1"></i>
                                                            Detected by: <?php echo htmlspecialchars($complication['detected_by_name']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if($complication['time_from_surgery']): ?>
                                                        <span class="text-muted ml-3">
                                                            <i class="fas fa-clock mr-1"></i>
                                                            <?php echo htmlspecialchars($complication['time_from_surgery']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="btn-group btn-group-sm">
                                                    <a href="surgical_complications_view.php?id=<?php echo $complication_id; ?>" class="btn btn-outline-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="surgical_complications_edit.php?id=<?php echo $complication_id; ?>" class="btn btn-outline-secondary">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <div class="dropdown-menu dropdown-menu-right">
                                                            <?php if($is_active): ?>
                                                                <a class="dropdown-item text-success" href="?case_id=<?php echo $case_id; ?>&resolve_complication=<?php echo $complication_id; ?>" onclick="return confirm('Mark this complication as resolved?')">
                                                                    <i class="fas fa-check mr-2"></i>Mark Resolved
                                                                </a>
                                                            <?php endif; ?>
                                                            <a class="dropdown-item" href="surgical_complications_print.php?id=<?php echo $complication_id; ?>" target="_blank">
                                                                <i class="fas fa-print mr-2"></i>Print Details
                                                            </a>
                                                            <div class="dropdown-divider"></div>
                                                            <a class="dropdown-item text-danger confirm-delete" href="?case_id=<?php echo $case_id; ?>&delete_complication=<?php echo $complication_id; ?>">
                                                                <i class="fas fa-trash mr-2"></i>Delete
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                        <h5>No Complications Recorded</h5>
                                        <p class="text-muted">This surgical case has no recorded complications.</p>
                                        <a href="surgical_complications_new.php?case_id=<?php echo $case_id; ?>" class="btn btn-primary">
                                            <i class="fas fa-plus mr-2"></i>Record First Complication
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Clavien-Dindo Distribution -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-chart-pie mr-2"></i>Clavien-Dindo Distribution</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="clavienChart"></canvas>
                                </div>
                                <div class="mt-3">
                                    <?php foreach($clavien_counts as $grade => $count): 
                                        if ($count > 0): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div>
                                                <span class="clavien-badge clavien-<?php echo $grade; ?>"><?php echo $grade; ?></span>
                                                <small><?php echo $grade; ?></small>
                                            </div>
                                            <span class="badge badge-light"><?php echo $count; ?></span>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Severity Distribution -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Severity Distribution</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach($severity_counts as $severity => $count): 
                                    if ($count > 0):
                                        $percentage = $total_complications > 0 ? ($count / $total_complications) * 100 : 0;
                                        $severity_class = 'severity-' . strtolower($severity);
                                ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="<?php echo $severity_class; ?> severity-badge"><?php echo $severity; ?></span>
                                            <small><?php echo $count; ?> (<?php echo round($percentage, 1); ?>%)</small>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar <?php echo $severity_class; ?>" role="progressbar" 
                                                 style="width: <?php echo $percentage; ?>%" 
                                                 aria-valuenow="<?php echo $percentage; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                <?php endif; endforeach; ?>
                                
                                <?php if ($total_complications == 0): ?>
                                    <div class="text-center py-3">
                                        <i class="fas fa-chart-bar fa-2x text-muted mb-2"></i>
                                        <p class="text-muted">No data to display</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="btn-group-vertical btn-block">
                                    <a href="surgical_complications_new.php?case_id=<?php echo $case_id; ?>" class="btn btn-success mb-2">
                                        <i class="fas fa-plus mr-2"></i>New Complication
                                    </a>
                                    <a href="reports_complications.php?case_id=<?php echo $case_id; ?>" class="btn btn-info mb-2">
                                        <i class="fas fa-chart-bar mr-2"></i>Generate Report
                                    </a>
                                    <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-secondary mb-2">
                                        <i class="fas fa-arrow-left mr-2"></i>Back to Case
                                    </a>
                                    <a href="theatre_dashboard.php?complication=has_complications" class="btn btn-warning">
                                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Case Summary -->
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Case Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <strong>Procedure:</strong>
                                    <p class="small mb-2"><?php echo htmlspecialchars($case['planned_procedure']); ?></p>
                                    
                                    <strong>Urgency:</strong>
                                    <span class="badge badge-<?php 
                                        switch($case['surgical_urgency']) {
                                            case 'emergency': echo 'danger'; break;
                                            case 'urgent': echo 'warning'; break;
                                            case 'elective': echo 'info'; break;
                                        }
                                    ?>"><?php echo ucfirst($case['surgical_urgency']); ?></span>
                                    
                                    <?php if($case['asa_score']): ?>
                                        <div class="mt-2">
                                            <strong>ASA Score:</strong>
                                            <span class="badge badge-secondary"><?php echo $case['asa_score']; ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if($case['surgery_date']): ?>
                                        <div class="mt-2">
                                            <strong>Surgery Date:</strong>
                                            <p class="small mb-0"><?php echo date('M j, Y', strtotime($case['surgery_date'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="alert alert-info small">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <?php if ($total_complications > 0): ?>
                                        This case has <?php echo $total_complications; ?> complication(s). 
                                        <?php echo $active_count; ?> active, <?php echo $resolved_count; ?> resolved.
                                    <?php else: ?>
                                        No complications recorded for this case.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Filter Complications</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form method="GET" action="">
                    <input type="hidden" name="case_id" value="<?php echo $case_id; ?>">
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control" name="status">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Clavien-Dindo Grade</label>
                            <select class="form-control" name="clavien">
                                <option value="">All Grades</option>
                                <?php foreach($clavien_counts as $grade => $count): ?>
                                    <option value="<?php echo $grade; ?>">Grade <?php echo $grade; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Severity</label>
                            <select class="form-control" name="severity">
                                <option value="">All Severities</option>
                                <?php foreach($severity_counts as $severity => $count): ?>
                                    <option value="<?php echo $severity; ?>"><?php echo $severity; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Type</label>
                            <select class="form-control" name="type">
                                <option value="">All Types</option>
                                <option value="Intraoperative">Intraoperative</option>
                                <option value="Postoperative">Postoperative</option>
                                <option value="Anesthesia">Anesthesia</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
    <script>
        $(document).ready(function() {
            // Confirm delete links
            $('.confirm-delete').click(function(e) {
                if (!confirm('Are you sure you want to delete this complication? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
            
            // Initialize Clavien-Dindo chart
            <?php if ($total_complications > 0): ?>
            var clavienCtx = document.getElementById('clavienChart').getContext('2d');
            var clavienChart = new Chart(clavienCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Grade I', 'Grade II', 'Grade IIIa', 'Grade IIIb', 'Grade IVa', 'Grade IVb', 'Grade V'],
                    datasets: [{
                        data: [
                            <?php echo $clavien_counts['I']; ?>,
                            <?php echo $clavien_counts['II']; ?>,
                            <?php echo $clavien_counts['IIIa']; ?>,
                            <?php echo $clavien_counts['IIIb']; ?>,
                            <?php echo $clavien_counts['IVa']; ?>,
                            <?php echo $clavien_counts['IVb']; ?>,
                            <?php echo $clavien_counts['V']; ?>
                        ],
                        backgroundColor: [
                            '#28a745', // I
                            '#ffc107', // II
                            '#fd7e14', // IIIa
                            '#dc3545', // IIIb
                            '#6f42c1', // IVa
                            '#e83e8c', // IVb
                            '#343a40'  // V
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += context.raw + ' (' + Math.round(context.parsed * 100 / <?php echo $total_complications; ?>) + '%)';
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
            
            // Toggle complication details
            $('.complication-card').click(function(e) {
                if (!$(e.target).closest('.btn-group').length) {
                    var complicationId = $(this).find('a[href*="surgical_complications_view.php"]').attr('href').split('id=')[1];
                    window.location.href = 'surgical_complications_view.php?id=' + complicationId;
                }
            });
            
            // Keyboard shortcuts
            $(document).keydown(function(e) {
                // Ctrl + N for new complication
                if (e.ctrlKey && e.keyCode === 78) {
                    e.preventDefault();
                    window.location.href = 'surgical_complications_new.php?case_id=<?php echo $case_id; ?>';
                }
                // Ctrl + F for filter
                if (e.ctrlKey && e.keyCode === 70) {
                    e.preventDefault();
                    $('#filterModal').modal('show');
                }
                // Esc to close modals
                if (e.keyCode === 27) {
                    $('.modal').modal('hide');
                }
            });
            
            // Auto-refresh every 5 minutes if there are active complications
            <?php if ($active_count > 0): ?>
            setTimeout(function() {
                location.reload();
            }, 300000); // 5 minutes
            <?php endif; ?>
        });
    </script>
</body>
</html>
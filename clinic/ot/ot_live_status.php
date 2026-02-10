<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    

// Get current OT status
$current_ot_sql = "SELECT t.*, 
                          s.surgery_id, s.surgery_number, s.scheduled_time,
                          p.patient_name, p.patient_mrn,
                          CONCAT(sur.first_name, ' ', sur.last_name) as surgeon_name,
                          st.type_name as surgery_type,
                          s.actual_start_time, s.estimated_duration_minutes,
                          TIMESTAMPDIFF(MINUTE, s.actual_start_time, NOW()) as minutes_in_progress
                   FROM theatres t
                   LEFT JOIN surgeries s ON t.theatre_id = s.theatre_id 
                    AND s.scheduled_date = CURDATE() 
                    AND s.status = 'in_progress'
                   LEFT JOIN patients p ON s.patient_id = p.patient_id
                   LEFT JOIN surgeons sur ON s.primary_surgeon_id = sur.surgeon_id
                   LEFT JOIN surgery_types st ON s.surgery_type_id = st.type_id
                   WHERE t.is_active = 1
                   ORDER BY t.theatre_number";
$current_ot_result = $mysqli->query($current_ot_sql);

// Get today's completed surgeries
$completed_sql = "SELECT s.*, p.patient_name, t.theatre_number,
                         CONCAT(sur.first_name, ' ', sur.last_name) as surgeon_name,
                         st.type_name as surgery_type,
                         TIMESTAMPDIFF(MINUTE, s.actual_start_time, s.actual_end_time) as duration
                  FROM surgeries s
                  LEFT JOIN patients p ON s.patient_id = p.patient_id
                  LEFT JOIN surgeons sur ON s.primary_surgeon_id = sur.surgeon_id
                  LEFT JOIN theatres t ON s.theatre_id = t.theatre_id
                  LEFT JOIN surgery_types st ON s.surgery_type_id = st.type_id
                  WHERE s.scheduled_date = CURDATE() 
                  AND s.status = 'completed'
                  ORDER BY s.actual_end_time DESC
                  LIMIT 10";
$completed_result = $mysqli->query($completed_sql);

// Get upcoming surgeries
$upcoming_sql = "SELECT s.*, p.patient_name, t.theatre_number,
                        CONCAT(sur.first_name, ' ', sur.last_name) as surgeon_name,
                        st.type_name as surgery_type,
                        TIMEDIFF(s.scheduled_time, CURTIME()) as time_until
                 FROM surgeries s
                 LEFT JOIN patients p ON s.patient_id = p.patient_id
                 LEFT JOIN surgeons sur ON s.primary_surgeon_id = sur.surgeon_id
                 LEFT JOIN theatres t ON s.theatre_id = t.theatre_id
                 LEFT JOIN surgery_types st ON s.surgery_type_id = st.type_id
                 WHERE s.scheduled_date = CURDATE() 
                 AND s.status IN ('scheduled', 'confirmed')
                 AND s.scheduled_time > CURTIME()
                 ORDER BY s.scheduled_time
                 LIMIT 10";
$upcoming_result = $mysqli->query($upcoming_sql);
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-tachometer-alt mr-2"></i>Live OT Status</h1>
                </div>
                <div class="col-sm-6">
                    <div class="float-right">
                        <div class="btn-group">
                            <button type="button" class="btn btn-success" onclick="window.location.href='surgery_new.php'">
                                <i class="fas fa-plus mr-2"></i>New Surgery
                            </button>
                            <button type="button" class="btn btn-primary" onclick="window.location.href='ot_schedule.php'">
                                <i class="fas fa-calendar-alt mr-2"></i>OT Schedule
                            </button>
                            <button type="button" class="btn btn-info" onclick="location.reload()">
                                <i class="fas fa-sync-alt mr-2"></i>Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <!-- Current Time Display -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card bg-gradient-info">
                        <div class="card-body text-center">
                            <h2 class="text-white mb-1 current-time"><?php echo date('H:i:s'); ?></h2>
                            <h4 class="text-white"><?php echo date('l, F j, Y'); ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current OT Status -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-primary">
                            <h3 class="card-title text-white">
                                <i class="fas fa-procedures mr-2"></i>Current Operation Theatre Status
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">Live</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                             <?php 
                                while($ot = $current_ot_result->fetch_assoc()): 
                                    $is_in_use = $ot['surgery_id'] && $ot['status'] == 'in_use';
                                    $minutes_elapsed = $ot['minutes_in_progress'] ?? 0;
                                    $estimated_duration = $ot['estimated_duration_minutes'] ?? 0;

                                    if ($estimated_duration > 0) {
                                        $progress_percentage = min(100, ($minutes_elapsed / $estimated_duration) * 100);
                                    } else {
                                        $progress_percentage = 0; // or handle differently if unknown duration
                                    }
                                ?>

                                <div class="col-lg-4 col-md-6 mb-4">
                                    <div class="card h-100 <?php echo $is_in_use ? 'border-warning' : 'border-success'; ?>">
                                        <div class="card-header <?php echo $is_in_use ? 'bg-warning' : 'bg-success'; ?>">
                                            <h5 class="card-title text-white mb-0">
                                                <?php echo htmlspecialchars($ot['theatre_number']); ?> - <?php echo htmlspecialchars($ot['theatre_name']); ?>
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if($is_in_use): ?>
                                                <!-- Surgery in progress -->
                                                <div class="text-center mb-3">
                                                    <i class="fas fa-procedures fa-3x text-warning mb-2"></i>
                                                    <h6 class="text-warning">Surgery in Progress</h6>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <strong>Patient:</strong> <?php echo htmlspecialchars($ot['patient_name']); ?><br>
                                                    <strong>MRN:</strong> <?php echo htmlspecialchars($ot['patient_mrn']); ?><br>
                                                    <strong>Surgery:</strong> <?php echo htmlspecialchars($ot['surgery_type']); ?><br>
                                                    <strong>Surgeon:</strong> <?php echo htmlspecialchars($ot['surgeon_name']); ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <strong>Started:</strong> <?php echo date('H:i', strtotime($ot['actual_start_time'])); ?><br>
                                                    <strong>Elapsed:</strong> <?php echo $minutes_elapsed; ?> minutes<br>
                                                    <strong>Estimated:</strong> <?php echo $ot['estimated_duration_minutes']; ?> minutes
                                                </div>
                                                
                                                <!-- Progress bar -->
                                                <div class="progress mb-2" style="height: 20px;">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $progress_percentage; ?>%"
                                                         aria-valuenow="<?php echo $progress_percentage; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                        <?php echo round($progress_percentage); ?>%
                                                    </div>
                                                </div>
                                                <small class="text-muted">Surgery Progress</small>
                                                
                                            <?php else: ?>
                                                <!-- Theatre available -->
                                                <div class="text-center mb-3">
                                                    <i class="fas fa-check-circle fa-3x text-success mb-2"></i>
                                                    <h6 class="text-success">Available</h6>
                                                </div>
                                                
                                                <div class="text-center">
                                                    <p class="text-muted">Theatre is ready for next surgery</p>
                                                    <a href="surgery_new.php?theatre_id=<?php echo $ot['theatre_id']; ?>" class="btn btn-success btn-sm">
                                                        <i class="fas fa-plus mr-1"></i> Schedule Surgery
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-footer">
                                            <small class="text-muted">
                                                <strong>Status:</strong> 
                                                <span class="badge badge-<?php echo $is_in_use ? 'warning' : 'success'; ?>">
                                                    <?php echo ucfirst($ot['status']); ?>
                                                </span>
                                                <?php if($ot['location']): ?>
                                                    • <strong>Location:</strong> <?php echo htmlspecialchars($ot['location']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upcoming and Completed Surgeries -->
            <div class="row mt-4">
                <!-- Upcoming Surgeries -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-success">
                            <h3 class="card-title text-white">
                                <i class="fas fa-clock mr-2"></i>Upcoming Surgeries Today
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>Patient</th>
                                            <th>Surgery</th>
                                            <th>Theatre</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($surgery = $upcoming_result->fetch_assoc()): 
                                            $time_until = $surgery['time_until'];
                                            $is_soon = strtotime($time_until) < strtotime('01:00:00'); // Less than 1 hour
                                        ?>
                                        <tr class="<?php echo $is_soon ? 'table-warning' : ''; ?>">
                                            <td class="font-weight-bold"><?php echo date('H:i', strtotime($surgery['scheduled_time'])); ?></td>
                                            <td><?php echo htmlspecialchars($surgery['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars($surgery['surgery_type']); ?></td>
                                            <td>
                                                <span class="badge badge-light"><?php echo htmlspecialchars($surgery['theatre_number']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $surgery['status'] == 'confirmed' ? 'info' : 'primary'; ?>">
                                                    <?php echo ucfirst($surgery['status']); ?>
                                                </span>
                                                <?php if($is_soon): ?>
                                                    <br><small class="text-danger">Starting soon</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php if($upcoming_result->num_rows == 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">
                                                No upcoming surgeries for today
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Completed Surgeries -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-info">
                            <h3 class="card-title text-white">
                                <i class="fas fa-check-circle mr-2"></i>Recently Completed Today
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>Patient</th>
                                            <th>Surgery</th>
                                            <th>Theatre</th>
                                            <th>Duration</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($surgery = $completed_result->fetch_assoc()): ?>
                                        <tr>
                                            <td class="font-weight-bold"><?php echo date('H:i', strtotime($surgery['actual_end_time'])); ?></td>
                                            <td><?php echo htmlspecialchars($surgery['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars($surgery['surgery_type']); ?></td>
                                            <td>
                                                <span class="badge badge-light"><?php echo htmlspecialchars($surgery['theatre_number']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-success"><?php echo $surgery['duration']; ?> min</span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php if($completed_result->num_rows == 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">
                                                No completed surgeries today
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
$(document).ready(function() {
    // Update clock every second
    function updateClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString();
        $('.current-time').text(timeString);
    }
    setInterval(updateClock, 1000);

    // Auto-refresh every 30 seconds
    setInterval(function() {
        location.reload();
    }, 30000);

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // R to refresh
        if (e.keyCode === 82) {
            e.preventDefault();
            location.reload();
        }
        // N for new surgery
        if (e.keyCode === 78) {
            e.preventDefault();
            window.location.href = 'surgery_new.php';
        }
    });
});
</script>

<style>
.card {
    transition: transform 0.2s ease-in-out;
}
.card:hover {
    transform: translateY(-2px);
}
.progress-bar-animated {
    animation: progress-bar-stripes 1s linear infinite;
}
@keyframes progress-bar-stripes {
    0% { background-position: 1rem 0; }
    100% { background-position: 0 0; }
}
</style>

<?php 
     require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    ?>
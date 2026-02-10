<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get theatre ID from URL
$theatre_id = intval($_GET['id'] ?? 0);

if ($theatre_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid theatre ID.";
    header("Location: theatres.php");
    exit;
}

// Fetch theatre details with additional statistics
$theatre_sql = "SELECT t.*, 
                       u.user_name as created_by_name,
                       mu.user_name as modified_by_name,
                       (SELECT COUNT(*) FROM surgeries s 
                        WHERE s.theatre_id = t.theatre_id 
                        AND s.scheduled_date = CURDATE() 
                        AND s.status IN ('scheduled', 'confirmed', 'in_progress')
                        AND s.archived_at IS NULL) as today_surgeries,
                       (SELECT COUNT(*) FROM surgeries s 
                        WHERE s.theatre_id = t.theatre_id 
                        AND s.scheduled_date >= CURDATE() 
                        AND s.status IN ('scheduled', 'confirmed')
                        AND s.archived_at IS NULL) as upcoming_surgeries,
                       (SELECT COUNT(*) FROM maintenance tm 
                        WHERE tm.theatre_id = t.theatre_id 
                        AND tm.status = 'pending') as pending_maintenance
                FROM theatres t
                LEFT JOIN users u ON t.created_by = u.user_id
                LEFT JOIN users mu ON t.modified_by = mu.user_id
                WHERE t.theatre_id = ? 
                AND t.archived_at IS NULL";

$theatre_stmt = $mysqli->prepare($theatre_sql);
$theatre_stmt->bind_param("i", $theatre_id);
$theatre_stmt->execute();
$theatre_result = $theatre_stmt->get_result();

if ($theatre_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Theatre not found or has been archived.";
    header("Location: theatres.php");
    exit;
}

$theatre = $theatre_result->fetch_assoc();

// Get statistics for this theatre
$total_surgeries = $mysqli->query("SELECT COUNT(*) as total FROM surgeries WHERE theatre_id = $theatre_id AND archived_at IS NULL")->fetch_assoc()['total'];
$completed_surgeries = $mysqli->query("SELECT COUNT(*) as total FROM surgeries WHERE theatre_id = $theatre_id AND status = 'completed' AND archived_at IS NULL")->fetch_assoc()['total'];
$active_equipment = $mysqli->query("SELECT COUNT(*) as total FROM theatre_equipment WHERE theatre_id = $theatre_id AND is_active = 1 AND archived_at IS NULL")->fetch_assoc()['total'];

// Status badge styling
$status_badge = '';
$status_icon = '';
switch($theatre['status']) {
    case 'available':
        $status_badge = 'badge-success';
        $status_icon = 'fa-check-circle';
        break;
    case 'in_use':
        $status_badge = 'badge-warning';
        $status_icon = 'fa-procedures';
        break;
    case 'maintenance':
        $status_badge = 'badge-danger';
        $status_icon = 'fa-tools';
        break;
    case 'cleaning':
        $status_badge = 'badge-info';
        $status_icon = 'fa-broom';
        break;
    default:
        $status_badge = 'badge-secondary';
        $status_icon = 'fa-question';
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-hospital-alt mr-2"></i>
            Theatre Details: OT <?php echo htmlspecialchars($theatre['theatre_number']); ?> - <?php echo htmlspecialchars($theatre['theatre_name']); ?>
        </h3>
        <div class="card-tools">
            <a href="theatres.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Theatres
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible m-3">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    <?php endif; ?>

    <div class="card-body">
        <!-- Theatre Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge <?php echo $status_badge; ?> ml-2">
                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $theatre['status'])); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Location:</strong> 
                            <span class="badge badge-info ml-2">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?php echo htmlspecialchars($theatre['location']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Capacity:</strong> 
                            <span class="badge badge-primary ml-2">
                                <i class="fas fa-users mr-1"></i>
                                <?php echo $theatre['capacity']; ?> person(s)
                            </span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="theatre_edit.php?id=<?php echo $theatre_id; ?>" class="btn btn-warning">
                            <i class="fas fa-edit mr-2"></i>Edit Theatre
                        </a>
                      
                        <div class="dropdown">
                            <button class="btn btn-primary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-cog mr-2"></i>Quick Actions
                            </button>
                            <div class="dropdown-menu">
                                <?php if($theatre['status'] == 'available'): ?>
                                    <a class="dropdown-item text-warning confirm-action" href="post.php?set_theatre_maintenance=<?php echo $theatre_id; ?>" data-message="Set this theatre to maintenance mode?">
                                        <i class="fas fa-tools mr-2"></i>Set Maintenance
                                    </a>
                                    <a class="dropdown-item text-info confirm-action" href="post.php?set_theatre_cleaning=<?php echo $theatre_id; ?>" data-message="Mark this theatre as cleaning?">
                                        <i class="fas fa-broom mr-2"></i>Set Cleaning
                                    </a>
                                <?php elseif($theatre['status'] == 'maintenance' || $theatre['status'] == 'cleaning'): ?>
                                    <a class="dropdown-item text-success confirm-action" href="post.php?set_theatre_available=<?php echo $theatre_id; ?>" data-message="Set this theatre as available?">
                                        <i class="fas fa-check mr-2"></i>Set Available
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="theatre_equipment.php?id=<?php echo $theatre_id; ?>">
                                    <i class="fas fa-tools mr-2"></i>Manage Equipment
                                </a>
                                <a class="dropdown-item" href="theatre_maintenance.php?id=<?php echo $theatre_id; ?>">
                                    <i class="fas fa-wrench mr-2"></i>Maintenance History
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Theatre Information -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Theatre Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Theatre Number:</th>
                                    <td><strong>OT <?php echo htmlspecialchars($theatre['theatre_number']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Theatre Name:</th>
                                    <td><strong><?php echo htmlspecialchars($theatre['theatre_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Location:</th>
                                    <td>
                                        <i class="fas fa-map-marker-alt text-muted mr-1"></i>
                                        <?php echo htmlspecialchars($theatre['location']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Capacity:</th>
                                    <td>
                                        <i class="fas fa-users text-muted mr-1"></i>
                                        <?php echo $theatre['capacity']; ?> person(s)
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Floor Area:</th>
                                    <td><?php echo $theatre['floor_area'] ? htmlspecialchars($theatre['floor_area']) . ' sq.ft' : 'Not specified'; ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Air Changes:</th>
                                    <td><?php echo $theatre['air_changes_per_hour'] ? htmlspecialchars($theatre['air_changes_per_hour']) . '/hour' : 'Not specified'; ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Created:</th>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($theatre['created_at'])); ?>
                                        <small class="text-muted">by <?php echo htmlspecialchars($theatre['created_by_name']); ?></small>
                                    </td>
                                </tr>
                                <?php if($theatre['modified_at']): ?>
                                <tr>
                                    <th class="text-muted">Last Modified:</th>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($theatre['modified_at'])); ?>
                                        <?php if($theatre['modified_by_name']): ?>
                                            <small class="text-muted">by <?php echo htmlspecialchars($theatre['modified_by_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2">
                                    <div class="h4 text-primary mb-1"><?php echo $total_surgeries; ?></div>
                                    <small class="text-muted">Total Surgeries</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2">
                                    <div class="h4 text-success mb-1"><?php echo $completed_surgeries; ?></div>
                                    <small class="text-muted">Completed</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <div class="h4 text-info mb-1"><?php echo $theatre['today_surgeries']; ?></div>
                                    <small class="text-muted">Today's Surgeries</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <div class="h4 text-warning mb-1"><?php echo $active_equipment; ?></div>
                                    <small class="text-muted">Active Equipment</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Theatre Description & Notes -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Theatre Description</h4>
                    </div>
                    <div class="card-body">
                        <?php if($theatre['description']): ?>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($theatre['description'])); ?></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">No description provided.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Today's Schedule -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-calendar-day mr-2"></i>Today's Schedule</h4>
                            <a href="ot_schedule.php?theatre=<?php echo $theatre_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-calendar-alt mr-1"></i>Full Schedule
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php
                        // Fetch today's surgeries
                        $today_surgeries_sql = "SELECT s.surgery_id, s.surgery_number, s.scheduled_time, s.status,
                                                       p.patient_first_name, p.patient_last_name, p.patient_mrn,
                                                       CONCAT(sur.first_name, ' ', sur.last_name) as surgeon_name,
                                                       st.type_name as surgery_type
                                                FROM surgeries s
                                                LEFT JOIN patients p ON s.patient_id = p.patient_id
                                                LEFT JOIN surgeons sur ON s.primary_surgeon_id = sur.surgeon_id
                                                LEFT JOIN surgery_types st ON s.surgery_type_id = st.type_id
                                                WHERE s.theatre_id = ?
                                                AND s.scheduled_date = CURDATE()
                                                AND s.archived_at IS NULL
                                                ORDER BY s.scheduled_time";
                        
                        $today_stmt = $mysqli->prepare($today_surgeries_sql);
                        $today_stmt->bind_param("i", $theatre_id);
                        $today_stmt->execute();
                        $today_surgeries = $today_stmt->get_result();
                        ?>
                        
                        <?php if ($today_surgeries->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>Patient</th>
                                            <th>Surgeon</th>
                                            <th>Surgery Type</th>
                                            <th>Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($surgery = $today_surgeries->fetch_assoc()): 
                                            $surgery_status_badge = '';
                                            switch($surgery['status']) {
                                                case 'scheduled': $surgery_status_badge = 'badge-primary'; break;
                                                case 'confirmed': $surgery_status_badge = 'badge-info'; break;
                                                case 'in_progress': $surgery_status_badge = 'badge-warning'; break;
                                                case 'completed': $surgery_status_badge = 'badge-success'; break;
                                                default: $surgery_status_badge = 'badge-secondary';
                                            }
                                        ?>
                                            <tr>
                                                <td class="font-weight-bold"><?php echo date('H:i', strtotime($surgery['scheduled_time'])); ?></td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($surgery['patient_first_name'] . ' ' . $surgery['patient_last_name']); ?></div>
                                                    <small class="text-muted">MRN: <?php echo htmlspecialchars($surgery['patient_mrn']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($surgery['surgeon_name'] ?? 'NA'); ?></td>
                                                <td><?php echo htmlspecialchars($surgery['surgery_type'] ?? 'NA'); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $surgery_status_badge; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $surgery['status'])); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="dropdown dropleft">
                                                        <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                            <i class="fas fa-ellipsis-h"></i>
                                                        </button>
                                                        <div class="dropdown-menu">
                                                            <a class="dropdown-item" href="surgery_view.php?id=<?php echo $surgery['surgery_id']; ?>">
                                                                <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                                            </a>
                                                            <a class="dropdown-item" href="surgery_edit.php?id=<?php echo $surgery['surgery_id']; ?>">
                                                                <i class="fas fa-fw fa-edit mr-2"></i>Edit Surgery
                                                            </a>
                                                            <?php if($surgery['status'] == 'scheduled' || $surgery['status'] == 'confirmed'): ?>
                                                                <a class="dropdown-item" href="post.php?start_surgery=<?php echo $surgery['surgery_id']; ?>">
                                                                    <i class="fas fa-fw fa-play mr-2"></i>Start Surgery
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if($surgery['status'] == 'in_progress'): ?>
                                                                <a class="dropdown-item" href="post.php?complete_surgery=<?php echo $surgery['surgery_id']; ?>">
                                                                    <i class="fas fa-fw fa-check mr-2"></i>Complete Surgery
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No surgeries scheduled for today</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Surgeries -->
                <div class="card mt-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-calendar-week mr-2"></i>Upcoming Surgeries (Next 3 Days)</h4>
                    </div>
                    <div class="card-body p-0">
                        <?php
                        // Fetch upcoming surgeries
                        $upcoming_surgeries_sql = "SELECT s.surgery_id, s.surgery_number, s.scheduled_date, s.scheduled_time, s.status,
                                                          p.patient_first_name, p.patient_last_name, p.patient_mrn,
                                                          CONCAT(sur.first_name, ' ', sur.last_name) as surgeon_name,
                                                          st.type_name as surgery_type,
                                                          DATEDIFF(s.scheduled_date, CURDATE()) as days_until
                                                   FROM surgeries s
                                                   LEFT JOIN patients p ON s.patient_id = p.patient_id
                                                   LEFT JOIN surgeons sur ON s.primary_surgeon_id = sur.surgeon_id
                                                   LEFT JOIN surgery_types st ON s.surgery_type_id = st.type_id
                                                   WHERE s.theatre_id = ?
                                                   AND s.scheduled_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                                                   AND s.status IN ('scheduled', 'confirmed')
                                                   AND s.archived_at IS NULL
                                                   ORDER BY s.scheduled_date, s.scheduled_time
                                                   LIMIT 5";

                        $upcoming_stmt = $mysqli->prepare($upcoming_surgeries_sql);
                        $upcoming_stmt->bind_param("i", $theatre_id);
                        $upcoming_stmt->execute();
                        $upcoming_surgeries = $upcoming_stmt->get_result();
                        ?>
                        
                        <?php if ($upcoming_surgeries->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Patient</th>
                                            <th>Surgeon</th>
                                            <th>Surgery Type</th>
                                            <th>Days Until</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($surgery = $upcoming_surgeries->fetch_assoc()): ?>
                                            <tr>
                                                <td class="font-weight-bold"><?php echo date('M j', strtotime($surgery['scheduled_date'])); ?></td>
                                                <td><?php echo date('H:i', strtotime($surgery['scheduled_time'])); ?></td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($surgery['patient_first_name'] . ' ' . $surgery['patient_last_name']); ?></div>
                                                    <small class="text-muted">MRN: <?php echo htmlspecialchars($surgery['patient_mrn']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($surgery['surgeon_name'] ?? 'NA'); ?></td>
                                                <td><?php echo htmlspecialchars($surgery['surgery_type'] ?? 'NA'); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $surgery['days_until'] == 1 ? 'badge-warning' : 'badge-info'; ?>">
                                                        <?php echo $surgery['days_until'] == 1 ? 'Tomorrow' : $surgery['days_until'] . ' days'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-plus fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No upcoming surgeries in the next 3 days</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm action links
    $('.confirm-action').click(function(e) {
        if (!confirm($(this).data('message'))) {
            e.preventDefault();
        }
    });

    // Auto-refresh every 60 seconds for live updates
    setInterval(function() {
        $.get('ajax/theatre_status.php?id=<?php echo $theatre_id; ?>', function(data) {
            if(data.status) {
                // Update status badge
                const statusMap = {
                    'available': {badge: 'badge-success', icon: 'fa-check-circle'},
                    'in_use': {badge: 'badge-warning', icon: 'fa-procedures'},
                    'maintenance': {badge: 'badge-danger', icon: 'fa-tools'},
                    'cleaning': {badge: 'badge-info', icon: 'fa-broom'}
                };
                
                const statusInfo = statusMap[data.status] || {badge: 'badge-secondary', icon: 'fa-question'};
                $('.card-header .badge')
                    .removeClass('badge-success badge-warning badge-danger badge-info badge-secondary')
                    .addClass(statusInfo.badge)
                    .html('<i class="fas ' + statusInfo.icon + ' mr-1"></i>' + data.status.charAt(0).toUpperCase() + data.status.slice(1).replace('_', ' '));
            }
        });
    }, 60000);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E for edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'theatre_edit.php?id=<?php echo $theatre_id; ?>';
    }
    // Ctrl + S for schedule surgery
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        window.location.href = 'surgery_new.php?theatre_id=<?php echo $theatre_id; ?>';
    }
    // Ctrl + B for back
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = 'theatres.php';
    }
    // Ctrl + F for full schedule
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        window.location.href = 'ot_schedule.php?theatre=<?php echo $theatre_id; ?>';
    }
});
</script>

<style>
.badge-lg {
    font-size: 0.9rem;
    padding: 0.5rem 0.75rem;
}
.table-borderless td {
    border: none !important;
}
.card .table td {
    vertical-align: middle;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
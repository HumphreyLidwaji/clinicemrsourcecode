<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);        

require_once "./../includes/inc_all.php";

// Get additional statistics for dashboard
$stats = [];
$stats['active_patients'] = mysqli_fetch_assoc(mysqli_query($mysqli, 
    "SELECT COUNT(*) as count FROM patients WHERE archived_at IS NULL AND patient_status = 'ACTIVE'"))['count'];

$stats['todays_appointments'] = mysqli_fetch_assoc(mysqli_query($mysqli, 
    "SELECT COUNT(*) as count FROM visits WHERE DATE(visit_datetime) = CURDATE()"))['count'];

$stats['pending_radiology'] = mysqli_fetch_assoc(mysqli_query($mysqli, 
    "SELECT COUNT(*) as count FROM radiology_orders WHERE order_status IN ('Pending', 'Scheduled')"))['count'];

$stats['pending_lab'] = mysqli_fetch_assoc(mysqli_query($mysqli, 
    "SELECT COUNT(*) as count FROM lab_orders WHERE lab_order_status IN ('Pending', 'Collected')"))['count'];

$stats['unbilled_orders'] = mysqli_fetch_assoc(mysqli_query($mysqli, 
    "SELECT COUNT(*) as count FROM radiology_orders WHERE is_billed = 0 AND order_status = 'Completed'"))['count'] +
    mysqli_fetch_assoc(mysqli_query($mysqli, 
    "SELECT COUNT(*) as count FROM lab_orders WHERE is_billed = 0 AND lab_order_status = 'Completed'"))['count'];

$stats['monthly_revenue'] = mysqli_fetch_assoc(mysqli_query($mysqli, 
    "SELECT SUM(total_amount) as total FROM invoices WHERE MONTH(invoice_date) = MONTH(CURDATE()) 
     AND YEAR(invoice_date) = YEAR(CURDATE()) AND invoice_status = 'Paid'"))['total'] ?? 0;

// Get pending orders with alerts
$urgent_orders = mysqli_query($mysqli, 
    "SELECT 'radiology' as type, radiology_order_id as id, order_number, order_priority, 
            order_status, patient_id, order_date, 'View Radiology Order' as action_text,
            CONCAT('/clinic/radiology/radiology_order_details.php?radiology_order_id=', radiology_order_id) as url
     FROM radiology_orders 
     WHERE order_status IN ('Pending', 'Scheduled') AND order_priority IN ('urgent', 'stat')
     UNION ALL
     SELECT 'lab' as type, lab_order_id as id, order_number, order_priority, 
            lab_order_status as order_status, lab_order_patient_id as patient_id, order_date, 
            'View Lab Order' as action_text,
            CONCAT('/clinic/lab/lab_order_details.php?lab_order_id=', lab_order_id) as url
     FROM lab_orders 
     WHERE lab_order_status IN ('Pending', 'Collected') AND order_priority IN ('urgent', 'stat')
     ORDER BY 
         CASE order_priority 
             WHEN 'stat' THEN 1 
             WHEN 'urgent' THEN 2 
             ELSE 3 
         END,
         order_date ASC
     LIMIT 5");

$urgent_order_count = mysqli_num_rows($urgent_orders);
mysqli_data_seek($urgent_orders, 0);
?>

<div class="card border-0">
    <div class="card-header bg-gradient-primary text-white py-3 border-0">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mb-0"><i class="fas fa-hospital-alt mr-2"></i>Clinic Dashboard</h3>
                <p class="mb-0 opacity-75"><?php echo date('l, F j, Y'); ?> • Professional Edition</p>
            </div>
            <div class="text-right">
                <div class="d-flex align-items-center">
                    <div class="mr-3 text-right">
                        <h6 class="mb-0 font-weight-bold"><?php echo $session_name; ?></h6>
                        <small class="opacity-75">
                            <?php 
                            $role_name = mysqli_fetch_assoc(mysqli_query($mysqli, 
                                "SELECT role_name FROM user_roles WHERE role_id = $session_user_role"));
                            echo $role_name['role_name'] ?? 'User';
                            ?>
                        </small>
                    </div>
                    <div class="avatar avatar-lg bg-white text-primary rounded-circle d-flex align-items-center justify-content-center">
                        <i class="fas fa-user-md fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card-body bg-light">
        <!-- Alerts & Notifications -->
        <?php if ($urgent_order_count > 0): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning border-warning shadow-sm d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle fa-lg mr-3"></i>
                        <div>
                            <h6 class="alert-heading mb-1">Priority Alert</h6>
                            <p class="mb-0">You have <strong><?php echo $urgent_order_count; ?> urgent orders</strong> requiring immediate attention</p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-warning" data-toggle="modal" data-target="#urgentOrdersModal">
                        View Details
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Key Performance Indicators -->
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="text-muted font-weight-bold mb-3"><i class="fas fa-chart-line mr-2"></i>Key Performance Indicators</h5>
            </div>
            
            <!-- Active Patients -->
            <?php if (SimplePermission::any(['module_patients', 'patient_view', '*'])): ?>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                <div class="card card-hover border-left-primary shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted font-weight-bold small">Active Patients</h6>
                                <h3 class="font-weight-bold text-primary mb-0"><?php echo number_format($stats['active_patients']); ?></h3>
                                <div class="mt-2">
                                    <span class="text-success small font-weight-bold">
                                        <i class="fas fa-arrow-up mr-1"></i>Active
                                    </span>
                                </div>
                            </div>
                            <div class="icon-circle bg-primary-light">
                                <i class="fas fa-user-injured text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Today's Appointments -->
            <?php if (SimplePermission::any(['module_visit', 'visit_view', '*'])): ?>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                <div class="card card-hover border-left-success shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted font-weight-bold small">Today's Appointments</h6>
                                <h3 class="font-weight-bold text-success mb-0"><?php echo number_format($stats['todays_appointments']); ?></h3>
                                <div class="mt-2">
                                    <span class="text-info small font-weight-bold">
                                        <i class="fas fa-calendar-alt mr-1"></i>Scheduled
                                    </span>
                                </div>
                            </div>
                            <div class="icon-circle bg-success-light">
                                <i class="fas fa-calendar-check text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pending Radiology -->
            <?php if (SimplePermission::any(['module_radiology', 'radiology_view', '*'])): ?>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                <div class="card card-hover border-left-danger shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted font-weight-bold small">Pending Radiology</h6>
                                <h3 class="font-weight-bold text-danger mb-0"><?php echo number_format($stats['pending_radiology']); ?></h3>
                                <div class="mt-2">
                                    <span class="text-warning small font-weight-bold">
                                        <i class="fas fa-clock mr-1"></i>Awaiting
                                    </span>
                                </div>
                            </div>
                            <div class="icon-circle bg-danger-light">
                                <i class="fas fa-x-ray text-danger"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pending Lab -->
            <?php if (SimplePermission::any(['module_lab', 'lab_view', '*'])): ?>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                <div class="card card-hover border-left-warning shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted font-weight-bold small">Pending Lab Tests</h6>
                                <h3 class="font-weight-bold text-warning mb-0"><?php echo number_format($stats['pending_lab']); ?></h3>
                                <div class="mt-2">
                                    <span class="text-info small font-weight-bold">
                                        <i class="fas fa-vial mr-1"></i>Processing
                                    </span>
                                </div>
                            </div>
                            <div class="icon-circle bg-warning-light">
                                <i class="fas fa-flask text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Unbilled Orders -->
            <?php if (SimplePermission::any(['module_billing', 'module_accounts', '*'])): ?>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                <div class="card card-hover border-left-info shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted font-weight-bold small">Unbilled Orders</h6>
                                <h3 class="font-weight-bold text-info mb-0"><?php echo number_format($stats['unbilled_orders']); ?></h3>
                                <div class="mt-2">
                                    <span class="text-danger small font-weight-bold">
                                        <i class="fas fa-file-invoice-dollar mr-1"></i>Pending
                                    </span>
                                </div>
                            </div>
                            <div class="icon-circle bg-info-light">
                                <i class="fas fa-receipt text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Monthly Revenue -->
            <?php if (SimplePermission::any(['module_billing', 'module_accounts', '*'])): ?>
            <div class="col-xl-2 col-md-4 col-sm-6 mb-4">
                <div class="card card-hover border-left-purple shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-uppercase text-muted font-weight-bold small">Monthly Revenue</h6>
                                <h3 class="font-weight-bold text-purple mb-0">$<?php echo number_format($stats['monthly_revenue'], 0); ?></h3>
                                <div class="mt-2">
                                    <span class="text-success small font-weight-bold">
                                        <i class="fas fa-chart-line mr-1"></i>Current
                                    </span>
                                </div>
                            </div>
                            <div class="icon-circle bg-purple-light">
                                <i class="fas fa-dollar-sign text-purple"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Dashboard Content -->
        <div class="row">
            <!-- Left Column: Clinical Workflow -->
            <div class="col-lg-8">
                <div class="row">
                    <!-- Today's Schedule -->
                    <?php if (SimplePermission::any(['module_visit', 'visit_view', '*'])): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="font-weight-bold text-primary mb-0">
                                        <i class="fas fa-calendar-day mr-2"></i>Today's Schedule
                                    </h6>
                                    <small class="text-muted">Appointments for <?php echo date('M j, Y'); ?></small>
                                </div>
                                <a href="/clinic/visit/visits.php" class="btn btn-sm btn-outline-primary rounded-pill">
                                    View All <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                            <div class="card-body p-0">
                                <?php
                                $today_visits = mysqli_query($mysqli, 
                                    "SELECT v.visit_id, v.visit_datetime, v.visit_type, v.visit_status, 
                                            p.first_name, p.last_name, p.patient_id,
                                            CONCAT(TIME_FORMAT(v.visit_datetime, '%h:%i'), ' ', 
                                                   CASE WHEN HOUR(v.visit_datetime) >= 12 THEN 'PM' ELSE 'AM' END) as formatted_time
                                     FROM visits v 
                                     LEFT JOIN patients p ON v.patient_id = p.patient_id 
                                     WHERE DATE(v.visit_datetime) = CURDATE() 
                                     ORDER BY v.visit_datetime ASC 
                                     LIMIT 6");
                                
                                if (mysqli_num_rows($today_visits) > 0): ?>
                                    <div class="list-group list-group-flush">
                                        <?php while ($visit = mysqli_fetch_assoc($today_visits)): 
                                            $status_badge = "";
                                            $status_text = "";
                                            switch($visit['visit_status']) {
                                                case 'ACTIVE': $status_badge = "bg-info"; $status_text = "Scheduled"; break;
                                                case 'In Progress': $status_badge = "bg-warning"; $status_text = "In Progress"; break;
                                                case 'CLOSED': $status_badge = "bg-success"; $status_text = "Completed"; break;
                                                case 'CANCELLED': $status_badge = "bg-danger"; $status_text = "Cancelled"; break;
                                                default: $status_badge = "bg-secondary"; $status_text = $visit['visit_status'];
                                            }
                                        ?>
                                            <a href="/clinic/visit/visit_details.php?visit_id=<?php echo $visit['visit_id']; ?>" 
                                               class="list-group-item list-group-item-action border-0 py-3">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div class="d-flex align-items-center">
                                                        <div class="mr-3">
                                                            <span class="badge <?php echo $status_badge; ?> badge-pill"><?php echo $status_text; ?></span>
                                                        </div>
                                                        <div>
                                                            <h6 class="font-weight-bold mb-0"><?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?></h6>
                                                            <small class="text-muted"><?php echo htmlspecialchars($visit['visit_type']); ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <small class="text-muted d-block"><?php echo $visit['formatted_time']; ?></small>
                                                        <i class="fas fa-chevron-right text-muted"></i>
                                                    </div>
                                                </div>
                                            </a>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                        <p class="text-muted mb-0">No appointments scheduled</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Patients -->
                    <?php if (SimplePermission::any(['module_patients', 'patient_view', '*'])): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="font-weight-bold text-primary mb-0">
                                        <i class="fas fa-users mr-2"></i>Recent Patients
                                    </h6>
                                    <small class="text-muted">Recently added patients</small>
                                </div>
                                <a href="/clinic/patient/patient.php" class="btn btn-sm btn-outline-primary rounded-pill">
                                    View All <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                            <div class="card-body p-0">
                                <?php
                                $recent_patients = mysqli_query($mysqli, 
                                    "SELECT patient_id, first_name, last_name, email, phone_primary, 
                                            DATE_FORMAT(created_at, '%b %d') as added_date
                                     FROM patients 
                                     WHERE archived_at IS NULL 
                                     ORDER BY created_at DESC 
                                     LIMIT 6");
                                
                                if (mysqli_num_rows($recent_patients) > 0): ?>
                                    <div class="list-group list-group-flush">
                                        <?php while ($patient = mysqli_fetch_assoc($recent_patients)): ?>
                                            <a href="/clinic/patient/patient_details.php?patient_id=<?php echo $patient['patient_id']; ?>" 
                                               class="list-group-item list-group-item-action border-0 py-3">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar avatar-sm bg-primary-light text-primary rounded-circle mr-3 d-flex align-items-center justify-content-center">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                        <div>
                                                            <h6 class="font-weight-bold mb-0"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h6>
                                                            <small class="text-muted">
                                                                <?php echo $patient['email'] ? htmlspecialchars($patient['email']) : 
                                                                      ($patient['phone_primary'] ? htmlspecialchars($patient['phone_primary']) : 'No contact'); ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <small class="text-muted">Added <?php echo $patient['added_date']; ?></small>
                                                        <i class="fas fa-chevron-right text-muted ml-2"></i>
                                                    </div>
                                                </div>
                                            </a>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <p class="text-muted mb-0">No patients found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Pending Orders (Radiology & Lab) -->
                    <div class="col-12 mb-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white border-0 py-3">
                                <h6 class="font-weight-bold text-primary mb-0">
                                    <i class="fas fa-tasks mr-2"></i>Pending Orders
                                </h6>
                                <small class="text-muted">Radiology and Laboratory orders requiring attention</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- Pending Radiology Orders -->
                                    <?php if (SimplePermission::any(['module_radiology', 'radiology_view', '*'])): ?>
                                    <div class="col-md-6">
                                        <div class="card border-left-danger h-100">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h6 class="font-weight-bold text-danger mb-0">
                                                        <i class="fas fa-x-ray mr-2"></i>Radiology
                                                    </h6>
                                                    <a href="/clinic/radiology/radiology_orders.php" class="btn btn-sm btn-outline-danger rounded-pill">
                                                        View <i class="fas fa-arrow-right ml-1"></i>
                                                    </a>
                                                </div>
                                                <?php
                                                $radiology_orders = mysqli_query($mysqli, 
                                                    "SELECT ro.order_number, ro.order_type, ro.order_priority, ro.order_status,
                                                            p.first_name, p.last_name, ro.order_date
                                                     FROM radiology_orders ro
                                                     LEFT JOIN patients p ON ro.patient_id = p.patient_id
                                                     WHERE ro.order_status IN ('Pending', 'Scheduled')
                                                     ORDER BY ro.order_date ASC
                                                     LIMIT 4");
                                                
                                                if (mysqli_num_rows($radiology_orders) > 0): ?>
                                                    <div class="list-group list-group-flush">
                                                        <?php while ($order = mysqli_fetch_assoc($radiology_orders)): 
                                                            $priority_badge = $order['order_priority'] == 'stat' ? 'badge-danger' : 
                                                                             ($order['order_priority'] == 'urgent' ? 'badge-warning' : 'badge-info');
                                                        ?>
                                                            <div class="list-group-item border-0 px-0 py-2">
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <div>
                                                                        <h6 class="font-weight-bold mb-0 small"><?php echo htmlspecialchars($order['first_name'] ?? '' . ' ' . $order['last_name'] ?? ''); ?></h6>
                                                                        <small class="text-muted"><?php echo htmlspecialchars($order['order_type'] ?? ''); ?></small>
                                                                    </div>
                                                                    <span class="badge <?php echo $priority_badge; ?> badge-pill"><?php echo ucfirst($order['order_priority']); ?></span>
                                                                </div>
                                                            </div>
                                                        <?php endwhile; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center py-3">
                                                        <p class="text-muted mb-0">No pending radiology orders</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Pending Lab Orders -->
                                    <?php if (SimplePermission::any(['module_lab', 'lab_view', '*'])): ?>
                                    <div class="col-md-6">
                                        <div class="card border-left-warning h-100">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h6 class="font-weight-bold text-warning mb-0">
                                                        <i class="fas fa-vial mr-2"></i>Laboratory
                                                    </h6>
                                                    <a href="/clinic/lab/lab_orders.php" class="btn btn-sm btn-outline-warning rounded-pill">
                                                        View <i class="fas fa-arrow-right ml-1"></i>
                                                    </a>
                                                </div>
                                                <?php
                                                $lab_orders = mysqli_query($mysqli, 
                                                    "SELECT lo.order_number, lo.lab_order_type, lo.order_priority, lo.lab_order_status,
                                                            p.first_name, p.last_name, lo.order_date
                                                     FROM lab_orders lo
                                                     LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id
                                                     WHERE lo.lab_order_status IN ('Pending', 'Collected')
                                                     ORDER BY lo.order_date ASC
                                                     LIMIT 4");
                                                
                                                if (mysqli_num_rows($lab_orders) > 0): ?>
                                                    <div class="list-group list-group-flush">
                                                        <?php while ($order = mysqli_fetch_assoc($lab_orders)): 
                                                            $priority_badge = $order['order_priority'] == 'stat' ? 'badge-danger' : 
                                                                             ($order['order_priority'] == 'urgent' ? 'badge-warning' : 'badge-info');
                                                        ?>
                                                            <div class="list-group-item border-0 px-0 py-2">
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <div>
                                                                        <h6 class="font-weight-bold mb-0 small"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></h6>
                                                                        <small class="text-muted"><?php echo htmlspecialchars($order['lab_order_type'] ?? 'Routine'); ?></small>
                                                                    </div>
                                                                    <span class="badge <?php echo $priority_badge; ?> badge-pill"><?php echo ucfirst($order['order_priority']); ?></span>
                                                                </div>
                                                            </div>
                                                        <?php endwhile; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-center py-3">
                                                        <p class="text-muted mb-0">No pending lab orders</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Statistics & Quick Actions -->
            <div class="col-lg-4">
                <!-- System Status -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="font-weight-bold text-primary mb-0">
                            <i class="fas fa-chart-bar mr-2"></i>System Statistics
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h6 class="font-weight-bold text-muted mb-3">Visit Distribution</h6>
                            <?php
                            $visit_stats = mysqli_query($mysqli, 
                                "SELECT visit_type, COUNT(*) as count 
                                 FROM visits 
                                 WHERE DATE(visit_datetime) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                 GROUP BY visit_type");
                            
                            while ($stat = mysqli_fetch_assoc($visit_stats)): 
                                $percentage = $stats['todays_appointments'] > 0 ? ($stat['count'] / $stats['todays_appointments'] * 100) : 0;
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted"><?php echo $stat['visit_type']; ?></span>
                                        <span class="font-weight-bold"><?php echo $stat['count']; ?></span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-primary" role="progressbar" 
                                             style="width: <?php echo min($percentage, 100); ?>%;" 
                                             aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>

                        <div class="border-top pt-3">
                            <h6 class="font-weight-bold text-muted mb-3">System Health</h6>
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-success-light rounded">
                                        <i class="fas fa-database fa-2x text-success mb-2"></i>
                                        <h6 class="font-weight-bold mb-0">Database</h6>
                                        <small class="text-success">Online</small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-info-light rounded">
                                        <i class="fas fa-users fa-2x text-info mb-2"></i>
                                        <h6 class="font-weight-bold mb-0">Active Users</h6>
                                        <small class="text-info"><?php echo $stats['active_patients']; ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="font-weight-bold text-primary mb-0">
                            <i class="fas fa-bolt mr-2"></i>Quick Actions
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php if (SimplePermission::any(['module_visit', 'visit_add', '*'])): ?>
                            <div class="col-6 mb-3">
                                <a href="/clinic/visit/visit_add.php" class="btn btn-outline-primary btn-block py-3 rounded">
                                    <i class="fas fa-calendar-plus fa-lg mb-2"></i><br>
                                    <span>New Visit</span>
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (SimplePermission::any(['module_patients', 'patient_add', '*'])): ?>
                            <div class="col-6 mb-3">
                                <a href="/clinic/patient/patient_add.php" class="btn btn-outline-success btn-block py-3 rounded">
                                    <i class="fas fa-user-plus fa-lg mb-2"></i><br>
                                    <span>New Patient</span>
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (SimplePermission::any(['module_radiology', 'radiology_add', '*'])): ?>
                            <div class="col-6 mb-3">
                                <a href="/clinic/radiology/radiology_order_add.php" class="btn btn-outline-danger btn-block py-3 rounded">
                                    <i class="fas fa-x-ray fa-lg mb-2"></i><br>
                                    <span>New Radiology</span>
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (SimplePermission::any(['module_lab', 'lab_add', '*'])): ?>
                            <div class="col-6 mb-3">
                                <a href="/clinic/lab/lab_order_add.php" class="btn btn-outline-warning btn-block py-3 rounded">
                                    <i class="fas fa-vial fa-lg mb-2"></i><br>
                                    <span>New Lab Test</span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="card-footer bg-white border-top py-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <small class="text-muted">
                    <i class="fas fa-sync-alt mr-1"></i> Last updated: <?php echo date('g:i A'); ?>
                </small>
            </div>
            <div class="col-md-6 text-md-right">
                <small class="text-muted">
                    ClinicEMR v2.0 • Data refreshed automatically every 10 minutes
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Urgent Orders Modal -->
<div class="modal fade" id="urgentOrdersModal" tabindex="-1" role="dialog" aria-labelledby="urgentOrdersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title" id="urgentOrdersModalLabel">
                    <i class="fas fa-exclamation-triangle mr-2"></i>Urgent Orders Requiring Attention
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="bg-light">
                            <tr>
                                <th>Order Type</th>
                                <th>Order #</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($order = mysqli_fetch_assoc($urgent_orders)): 
                                $priority_badge = $order['order_priority'] == 'stat' ? 'badge-danger' : 'badge-warning';
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-light">
                                            <?php echo ucfirst($order['type']); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo $order['order_number']; ?></strong></td>
                                    <td>
                                        <span class="badge <?php echo $priority_badge; ?>">
                                            <?php echo strtoupper($order['order_priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?php echo $order['order_status']; ?></span>
                                    </td>
                                    <td><?php echo date('M j, g:i A', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <a href="<?php echo $order['url']; ?>" class="btn btn-sm btn-warning">
                                            <?php echo $order['action_text']; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.card-hover:hover {
    transform: translateY(-2px);
    transition: transform 0.2s ease;
}

.icon-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-primary-light { background-color: rgba(66, 153, 225, 0.1); }
.bg-success-light { background-color: rgba(72, 187, 120, 0.1); }
.bg-danger-light { background-color: rgba(245, 101, 101, 0.1); }
.bg-warning-light { background-color: rgba(237, 137, 54, 0.1); }
.bg-info-light { background-color: rgba(102, 217, 232, 0.1); }
.bg-purple-light { background-color: rgba(159, 122, 234, 0.1); }

.border-left-purple { border-left: 4px solid #9f7aea !important; }
.text-purple { color: #9f7aea !important; }

.avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.progress {
    border-radius: 10px;
    overflow: hidden;
}
</style>

<script>
// Auto-refresh dashboard every 10 minutes
setTimeout(function() {
    window.location.reload();
}, 600000);

// Add smooth hover effects
$(document).ready(function() {
    $('.card-hover').hover(
        function() {
            $(this).addClass('shadow-lg');
        },
        function() {
            $(this).removeClass('shadow-lg');
        }
    );
    
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Auto-dismiss alerts after 10 seconds
    setTimeout(function() {
        $('.alert').fadeTo(500, 0).slideUp(500, function() {
            $(this).remove();
        });
    }, 10000);
});
</script>
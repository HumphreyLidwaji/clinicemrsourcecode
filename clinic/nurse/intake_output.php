<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    
    // AUDIT LOG: Invalid visit ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Intake Output',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access intake_output.php with invalid visit ID: " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Get visit and patient information in a single query
$sql = "SELECT 
            v.*,
            p.*,
            v.visit_type,
            v.visit_number,
            v.visit_datetime,
            v.admission_datetime,
            v.discharge_datetime,
            ia.admission_number,
            ia.admission_status,
            ia.ward_id,
            ia.bed_id
        FROM visits v
        JOIN patients p ON v.patient_id = p.patient_id
        LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id
        WHERE v.visit_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $visit_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    
    // AUDIT LOG: Visit not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Intake Output',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access intake/output for visit ID " . $visit_id . " but visit not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

$visit_info = $result->fetch_assoc();
$patient_info = $visit_info;
$visit_type = $visit_info['visit_type'];

// Get I/O entries for this visit
$io_sql = "SELECT io.*, u.user_name as recorded_by_name
          FROM intake_output io
          JOIN users u ON io.recorded_by = u.user_id
          WHERE io.visit_id = ?
          ORDER BY io.record_date DESC, io.record_time DESC";
$io_stmt = $mysqli->prepare($io_sql);
$io_stmt->bind_param("i", $visit_id);
$io_stmt->execute();
$io_result = $io_stmt->get_result();
$io_entries = $io_result->fetch_all(MYSQLI_ASSOC);

// Calculate daily totals
$daily_totals = [];
foreach ($io_entries as $entry) {
    $date = $entry['record_date'];
    if (!isset($daily_totals[$date])) {
        $daily_totals[$date] = [
            'intake' => 0,
            'output' => 0,
            'balance' => 0
        ];
    }
    $daily_totals[$date]['intake'] += $entry['oral_intake'] + $entry['iv_intake'] + $entry['tube_intake'];
    $daily_totals[$date]['output'] += $entry['urine_output'] + $entry['vomit_output'] + $entry['drain_output'] + $entry['other_output'];
    $daily_totals[$date]['balance'] = $daily_totals[$date]['intake'] - $daily_totals[$date]['output'];
}

// Get patient's latest weight from vitals
$weight_sql = "SELECT weight, recorded_at 
              FROM vitals 
              WHERE patient_id = ? 
              AND weight IS NOT NULL 
              ORDER BY recorded_at DESC 
              LIMIT 1";
$weight_stmt = $mysqli->prepare($weight_sql);
$weight_stmt->bind_param("i", $patient_info['patient_id']);
$weight_stmt->execute();
$weight_result = $weight_stmt->get_result();
$current_weight = $weight_result->fetch_assoc();

// AUDIT LOG: Successful access to intake/output page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Intake Output',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed intake/output page for visit ID " . $visit_id . " (Patient: " . $patient_info['first_name'] . " " . $patient_info['last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new I/O entry
    if (isset($_POST['add_io_entry'])) {
        $record_date = $_POST['record_date'];
        $record_time = $_POST['record_time'];
        $oral_intake = floatval($_POST['oral_intake'] ?? 0);
        $iv_intake = floatval($_POST['iv_intake'] ?? 0);
        $tube_intake = floatval($_POST['tube_intake'] ?? 0);
        $urine_output = floatval($_POST['urine_output'] ?? 0);
        $vomit_output = floatval($_POST['vomit_output'] ?? 0);
        $drain_output = floatval($_POST['drain_output'] ?? 0);
        $other_output = floatval($_POST['other_output'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $recorded_by = $_SESSION['user_id'];
        
        // Calculate totals
        $total_intake = $oral_intake + $iv_intake + $tube_intake;
        $total_output = $urine_output + $vomit_output + $drain_output + $other_output;
        $fluid_balance = $total_intake - $total_output;
        
        // AUDIT LOG: I/O entry attempt
        audit_log($mysqli, [
            'user_id'     => $recorded_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'INTAKE_OUTPUT_ADD',
            'module'      => 'Intake Output',
            'table_name'  => 'intake_output',
            'entity_type' => 'intake_output',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to add intake/output entry. Date: " . $record_date . ", Time: " . $record_time . ", Total Intake: " . $total_intake . "ml, Total Output: " . $total_output . "ml",
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => [
                'record_date' => $record_date,
                'record_time' => $record_time,
                'total_intake' => $total_intake,
                'total_output' => $total_output,
                'fluid_balance' => $fluid_balance,
                'recorded_by' => $recorded_by
            ]
        ]);
        
        $insert_sql = "INSERT INTO intake_output 
                      (visit_id, patient_id, record_date, record_time,
                       oral_intake, iv_intake, tube_intake,
                       urine_output, vomit_output, drain_output, other_output,
                       total_intake, total_output, fluid_balance,
                       notes, recorded_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("isssddddddddddsi", 
            $visit_id, $patient_info['patient_id'], $record_date, $record_time,
            $oral_intake, $iv_intake, $tube_intake,
            $urine_output, $vomit_output, $drain_output, $other_output,
            $total_intake, $total_output, $fluid_balance,
            $notes, $recorded_by
        );
        
        if ($insert_stmt->execute()) {
            $io_id = $insert_stmt->insert_id;
            
            // AUDIT LOG: Successful I/O entry
            audit_log($mysqli, [
                'user_id'     => $recorded_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'INTAKE_OUTPUT_ADD',
                'module'      => 'Intake Output',
                'table_name'  => 'intake_output',
                'entity_type' => 'intake_output',
                'record_id'   => $io_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Intake/output entry recorded successfully. ID: " . $io_id . ", Fluid Balance: " . $fluid_balance . "ml",
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'io_id' => $io_id,
                    'record_date' => $record_date,
                    'record_time' => $record_time,
                    'total_intake' => $total_intake,
                    'total_output' => $total_output,
                    'fluid_balance' => $fluid_balance,
                    'recorded_by' => $recorded_by,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "I/O entry recorded successfully";
            header("Location: intake_output.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed I/O entry
            audit_log($mysqli, [
                'user_id'     => $recorded_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'INTAKE_OUTPUT_ADD',
                'module'      => 'Intake Output',
                'table_name'  => 'intake_output',
                'entity_type' => 'intake_output',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to record I/O entry. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error recording I/O entry: " . $mysqli->error;
        }
    }
    
    // Delete I/O entry
    if (isset($_POST['delete_io_entry'])) {
        $io_id = intval($_POST['io_id']);
        $deleted_by = $_SESSION['user_id'];
        
        // Get entry details for audit log
        $entry_sql = "SELECT record_date, record_time, total_intake, total_output FROM intake_output WHERE id = ?";
        $entry_stmt = $mysqli->prepare($entry_sql);
        $entry_stmt->bind_param("i", $io_id);
        $entry_stmt->execute();
        $entry_result = $entry_stmt->get_result();
        $entry_details = $entry_result->fetch_assoc();
        
        // AUDIT LOG: Delete I/O entry attempt
        audit_log($mysqli, [
            'user_id'     => $deleted_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'INTAKE_OUTPUT_DELETE',
            'module'      => 'Intake Output',
            'table_name'  => 'intake_output',
            'entity_type' => 'intake_output',
            'record_id'   => $io_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to delete I/O entry ID " . $io_id . ". Date: " . ($entry_details['record_date'] ?? 'N/A') . ", Intake: " . ($entry_details['total_intake'] ?? 0) . "ml, Output: " . ($entry_details['total_output'] ?? 0) . "ml",
            'status'      => 'ATTEMPT',
            'old_values'  => [
                'record_date' => $entry_details['record_date'] ?? null,
                'record_time' => $entry_details['record_time'] ?? null,
                'total_intake' => $entry_details['total_intake'] ?? 0,
                'total_output' => $entry_details['total_output'] ?? 0
            ],
            'new_values'  => null
        ]);
        
        $delete_sql = "DELETE FROM intake_output WHERE id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $io_id);
        
        if ($delete_stmt->execute()) {
            // AUDIT LOG: Successful I/O entry deletion
            audit_log($mysqli, [
                'user_id'     => $deleted_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'INTAKE_OUTPUT_DELETE',
                'module'      => 'Intake Output',
                'table_name'  => 'intake_output',
                'entity_type' => 'intake_output',
                'record_id'   => $io_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "I/O entry deleted successfully. ID: " . $io_id,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'record_date' => $entry_details['record_date'] ?? null,
                    'record_time' => $entry_details['record_time'] ?? null,
                    'total_intake' => $entry_details['total_intake'] ?? 0,
                    'total_output' => $entry_details['total_output'] ?? 0
                ],
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "I/O entry deleted successfully";
            header("Location: intake_output.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed I/O entry deletion
            audit_log($mysqli, [
                'user_id'     => $deleted_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'INTAKE_OUTPUT_DELETE',
                'module'      => 'Intake Output',
                'table_name'  => 'intake_output',
                'entity_type' => 'intake_output',
                'record_id'   => $io_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to delete I/O entry. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error deleting I/O entry: " . $mysqli->error;
        }
    }
}

// Get patient full name
$full_name = $patient_info['first_name'] . 
            ($patient_info['middle_name'] ? ' ' . $patient_info['middle_name'] : '') . 
            ' ' . $patient_info['last_name'];

// Calculate fluid requirements (30ml/kg/day)
$fluid_requirement = null;
if ($current_weight && $current_weight['weight'] > 0) {
    $fluid_requirement = $current_weight['weight'] * 30; // 30ml per kg
}

// Get today's totals
$today = date('Y-m-d');
$today_intake = 0;
$today_output = 0;
$today_balance = 0;

if (isset($daily_totals[$today])) {
    $today_intake = $daily_totals[$today]['intake'];
    $today_output = $daily_totals[$today]['output'];
    $today_balance = $daily_totals[$today]['balance'];
}

// Get visit number
$visit_number = $visit_info['visit_number'];
if ($visit_type === 'IPD' && !empty($visit_info['admission_number'])) {
    $visit_number = $visit_info['admission_number'];
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-tint mr-2"></i>Intake/Output Chart: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addIOEntryModal">
                    <i class="fas fa-plus mr-2"></i>Add I/O Entry
                </button>
                <button type="button" class="btn btn-info" onclick="printIOCard()">
                    <i class="fas fa-print mr-2"></i>Print I/O Chart
                </button>
                <a href="/clinic/nurse/tasks.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-warning">
                    <i class="fas fa-tasks mr-2"></i>Tasks
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Patient and Stats Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <th width="40%" class="text-muted">Patient:</th>
                                        <td><strong><?php echo htmlspecialchars($full_name); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">MRN:</th>
                                        <td><span class="badge badge-info"><?php echo htmlspecialchars($patient_info['patient_mrn']); ?></span></td>
                                    </tr>
                                    <?php if ($current_weight): ?>
                                    <tr>
                                        <th class="text-muted">Current Weight:</th>
                                        <td>
                                            <span class="badge badge-success"><?php echo $current_weight['weight']; ?> kg</span>
                                            <small class="text-muted ml-2">
                                                (<?php echo date('M j', strtotime($current_weight['recorded_at'])); ?>)
                                            </small>
                                        </td>
                                    </tr>
                                    <?php if ($fluid_requirement): ?>
                                    <tr>
                                        <th class="text-muted">Fluid Requirement:</th>
                                        <td>
                                            <span class="badge badge-info"><?php echo number_format($fluid_requirement, 0); ?> ml/day</span>
                                            <small class="text-muted ml-2">(30ml/kg/day)</small>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="text-muted">Today's Intake</div>
                                        <div class="h4 text-primary"><?php echo number_format($today_intake, 0); ?> ml</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted">Today's Output</div>
                                        <div class="h4 text-warning"><?php echo number_format($today_output, 0); ?> ml</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted">Balance</div>
                                        <div class="h4 <?php echo $today_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($today_balance, 0); ?> ml
                                        </div>
                                        <?php if ($fluid_requirement): ?>
                                        <div class="progress" style="height: 5px;">
                                            <div class="progress-bar <?php echo $today_intake >= $fluid_requirement ? 'bg-success' : 'bg-warning'; ?>" 
                                                 style="width: <?php echo min(100, ($today_intake / $fluid_requirement) * 100); ?>%">
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo number_format(($today_intake / $fluid_requirement) * 100, 0); ?>% of requirement
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- I/O Entry Form (Quick Add) -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-plus-circle mr-2"></i>Quick I/O Entry
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="quickIOForm" class="row">
                            <input type="hidden" name="record_date" value="<?php echo $today; ?>">
                            <input type="hidden" name="record_time" value="<?php echo date('H:i'); ?>">
                            
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="oral_intake">Oral Intake (ml)</label>
                                    <input type="number" class="form-control" id="oral_intake" name="oral_intake" 
                                           min="0" step="10" value="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="iv_intake">IV Intake (ml)</label>
                                    <input type="number" class="form-control" id="iv_intake" name="iv_intake" 
                                           min="0" step="10" value="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="urine_output">Urine Output (ml)</label>
                                    <input type="number" class="form-control" id="urine_output" name="urine_output" 
                                           min="0" step="10" value="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" name="add_io_entry" class="btn btn-primary btn-block">
                                        <i class="fas fa-save mr-2"></i>Save Entry
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Quick Entry Buttons -->
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="btn-group btn-group-sm d-flex" role="group">
                                    <button type="button" class="btn btn-outline-primary flex-fill" onclick="quickEntry('water', 250)">
                                        <i class="fas fa-glass-water mr-1"></i>Water (250ml)
                                    </button>
                                    <button type="button" class="btn btn-outline-success flex-fill" onclick="quickEntry('iv', 500)">
                                        <i class="fas fa-tint mr-1"></i>IV Bag (500ml)
                                    </button>
                                    <button type="button" class="btn btn-outline-warning flex-fill" onclick="quickEntry('urine', 300)">
                                        <i class="fas fa-toilet mr-1"></i>Urine (300ml)
                                    </button>
                                    <button type="button" class="btn btn-outline-danger flex-fill" onclick="quickEntry('vomit', 150)">
                                        <i class="fas fa-lungs-virus mr-1"></i>Vomit (150ml)
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- I/O Chart -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-chart-bar mr-2"></i>Intake/Output Chart
                            <span class="badge badge-light float-right"><?php echo count($io_entries); ?> entries</span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($io_entries)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="ioTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th class="text-center">Intake (ml)</th>
                                            <th class="text-center">Output (ml)</th>
                                            <th class="text-center">Balance</th>
                                            <th>Recorded By</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_date = null;
                                        foreach ($io_entries as $entry): 
                                            $entry_date = new DateTime($entry['record_date']);
                                            $is_today = ($entry['record_date'] == $today);
                                            $row_class = $is_today ? 'table-info' : '';
                                            
                                            if ($current_date != $entry['record_date']) {
                                                $current_date = $entry['record_date'];
                                                $date_display = $entry_date->format('M j, Y');
                                                if ($is_today) {
                                                    $date_display = '<strong>Today</strong>';
                                                }
                                                // Show daily totals row
                                                if (isset($daily_totals[$current_date])):
                                        ?>
                                            <tr class="bg-light font-weight-bold">
                                                <td colspan="2" class="text-right">
                                                    <i class="fas fa-calendar-day mr-2"></i><?php echo $date_display; ?> TOTAL:
                                                </td>
                                                <td class="text-center text-primary">
                                                    <?php echo number_format($daily_totals[$current_date]['intake'], 0); ?> ml
                                                </td>
                                                <td class="text-center text-warning">
                                                    <?php echo number_format($daily_totals[$current_date]['output'], 0); ?> ml
                                                </td>
                                                <td class="text-center <?php echo $daily_totals[$current_date]['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo number_format($daily_totals[$current_date]['balance'], 0); ?> ml
                                                </td>
                                                <td></td>
                                            </tr>
                                        <?php 
                                                endif;
                                            }
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>
                                                    <div>
                                                        <?php echo date('H:i', strtotime($entry['record_time'])); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php 
                                                        $total_intake = $entry['oral_intake'] + $entry['iv_intake'] + $entry['tube_intake'];
                                                        $total_output = $entry['urine_output'] + $entry['vomit_output'] + $entry['drain_output'] + $entry['other_output'];
                                                        ?>
                                                        <?php if ($total_intake > 0): ?>
                                                            <span class="badge badge-primary mr-1">
                                                                <i class="fas fa-arrow-down"></i> <?php echo $total_intake; ?>ml
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($total_output > 0): ?>
                                                            <span class="badge badge-warning">
                                                                <i class="fas fa-arrow-up"></i> <?php echo $total_output; ?>ml
                                                            </span>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td class="text-center">
                                                    <div class="text-primary">
                                                        <?php if ($entry['oral_intake'] > 0): ?>
                                                            <div>
                                                                <small>Oral: <?php echo $entry['oral_intake']; ?>ml</small>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($entry['iv_intake'] > 0): ?>
                                                            <div>
                                                                <small>IV: <?php echo $entry['iv_intake']; ?>ml</small>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($entry['tube_intake'] > 0): ?>
                                                            <div>
                                                                <small>Tube: <?php echo $entry['tube_intake']; ?>ml</small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($total_intake > 0): ?>
                                                        <div class="font-weight-bold">
                                                            <?php echo $total_intake; ?> ml
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="text-warning">
                                                        <?php if ($entry['urine_output'] > 0): ?>
                                                            <div>
                                                                <small>Urine: <?php echo $entry['urine_output']; ?>ml</small>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($entry['vomit_output'] > 0): ?>
                                                            <div>
                                                                <small>Vomit: <?php echo $entry['vomit_output']; ?>ml</small>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($entry['drain_output'] > 0): ?>
                                                            <div>
                                                                <small>Drain: <?php echo $entry['drain_output']; ?>ml</small>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($entry['other_output'] > 0): ?>
                                                            <div>
                                                                <small>Other: <?php echo $entry['other_output']; ?>ml</small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($total_output > 0): ?>
                                                        <div class="font-weight-bold">
                                                            <?php echo $total_output; ?> ml
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="font-weight-bold <?php echo $entry['fluid_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo $entry['fluid_balance']; ?> ml
                                                    </div>
                                                    <?php if ($entry['fluid_balance'] > 0): ?>
                                                        <small class="text-success">
                                                            <i class="fas fa-plus-circle"></i> Positive
                                                        </small>
                                                    <?php elseif ($entry['fluid_balance'] < 0): ?>
                                                        <small class="text-danger">
                                                            <i class="fas fa-minus-circle"></i> Negative
                                                        </small>
                                                    <?php else: ?>
                                                        <small class="text-muted">Balanced</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($entry['recorded_by_name']); ?>
                                                    <?php if (!empty($entry['notes'])): ?>
                                                        <br>
                                                        <small class="text-muted" title="<?php echo htmlspecialchars($entry['notes']); ?>">
                                                            <i class="fas fa-note mr-1"></i>
                                                            <?php echo htmlspecialchars(substr($entry['notes'], 0, 30)); ?>...
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewIOEntry(<?php echo htmlspecialchars(json_encode($entry)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="io_id" value="<?php echo $entry['id']; ?>">
                                                        <button type="submit" name="delete_io_entry" class="btn btn-sm btn-danger" 
                                                                title="Delete Entry" onclick="return confirm('Delete this I/O entry?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-tint fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No I/O Entries</h5>
                                <p class="text-muted">No intake/output entries have been recorded for this visit yet.</p>
                                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addIOEntryModal">
                                    <i class="fas fa-plus mr-2"></i>Add First Entry
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Summary Statistics -->
                    <?php if (!empty($io_entries)): ?>
                    <div class="card-footer">
                        <div class="row text-center">
                            <div class="col-3">
                                <div class="text-muted">Total Intake</div>
                                <div class="h4 text-primary">
                                    <?php 
                                    $total_intake = array_sum(array_column($io_entries, 'total_intake'));
                                    echo number_format($total_intake, 0); ?> ml
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="text-muted">Total Output</div>
                                <div class="h4 text-warning">
                                    <?php 
                                    $total_output = array_sum(array_column($io_entries, 'total_output'));
                                    echo number_format($total_output, 0); ?> ml
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="text-muted">Net Balance</div>
                                <div class="h4 <?php echo ($total_intake - $total_output) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format($total_intake - $total_output, 0); ?> ml
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="text-muted">Avg Daily</div>
                                <div class="h4 text-info">
                                    <?php 
                                    $days = count($daily_totals);
                                    echo $days > 0 ? number_format($total_intake / $days, 0) : 0; ?> ml
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Fluid Balance Chart -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-secondary py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-chart-line mr-2"></i>24-Hour Fluid Balance Trend
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="fluidBalanceChart" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add I/O Entry Modal -->
<div class="modal fade" id="addIOEntryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="addIOEntryForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add I/O Entry</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="entry_date">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="entry_date" name="record_date" 
                                       value="<?php echo $today; ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="entry_time">Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="entry_time" name="record_time" 
                                       value="<?php echo date('H:i'); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Intake Section -->
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white py-2">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-arrow-down mr-2"></i>Intake (ml)
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="oral_intake_full">Oral (Food & Fluids)</label>
                                        <input type="number" class="form-control" id="oral_intake_full" 
                                               name="oral_intake" min="0" step="10" value="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="iv_intake_full">IV Fluids</label>
                                        <input type="number" class="form-control" id="iv_intake_full" 
                                               name="iv_intake" min="0" step="10" value="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="tube_intake_full">Tube Feeding</label>
                                        <input type="number" class="form-control" id="tube_intake_full" 
                                               name="tube_intake" min="0" step="10" value="0">
                                    </div>
                                </div>
                            </div>
                            <!-- Quick Intake Buttons -->
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="btn-group btn-group-sm d-flex" role="group">
                                        <button type="button" class="btn btn-outline-primary flex-fill" onclick="setIntake('oral', 100)">
                                            Water 100ml
                                        </button>
                                        <button type="button" class="btn btn-outline-primary flex-fill" onclick="setIntake('oral', 250)">
                                            Juice 250ml
                                        </button>
                                        <button type="button" class="btn btn-outline-primary flex-fill" onclick="setIntake('iv', 500)">
                                            IV Bag 500ml
                                        </button>
                                        <button type="button" class="btn btn-outline-primary flex-fill" onclick="setIntake('iv', 1000)">
                                            IV Bag 1000ml
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Output Section -->
                    <div class="card mb-3">
                        <div class="card-header bg-warning text-white py-2">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-arrow-up mr-2"></i>Output (ml)
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="urine_output_full">Urine</label>
                                        <input type="number" class="form-control" id="urine_output_full" 
                                               name="urine_output" min="0" step="10" value="0">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="vomit_output_full">Vomit</label>
                                        <input type="number" class="form-control" id="vomit_output_full" 
                                               name="vomit_output" min="0" step="10" value="0">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="drain_output_full">Drain</label>
                                        <input type="number" class="form-control" id="drain_output_full" 
                                               name="drain_output" min="0" step="10" value="0">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="other_output_full">Other</label>
                                        <input type="number" class="form-control" id="other_output_full" 
                                               name="other_output" min="0" step="10" value="0">
                                    </div>
                                </div>
                            </div>
                            <!-- Quick Output Buttons -->
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="btn-group btn-group-sm d-flex" role="group">
                                        <button type="button" class="btn btn-outline-warning flex-fill" onclick="setOutput('urine', 100)">
                                            Urine 100ml
                                        </button>
                                        <button type="button" class="btn btn-outline-warning flex-fill" onclick="setOutput('urine', 300)">
                                            Urine 300ml
                                        </button>
                                        <button type="button" class="btn btn-outline-warning flex-fill" onclick="setOutput('vomit', 150)">
                                            Vomit 150ml
                                        </button>
                                        <button type="button" class="btn btn-outline-warning flex-fill" onclick="setOutput('drain', 50)">
                                            Drain 50ml
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div class="form-group">
                        <label for="notes_full">Notes</label>
                        <textarea class="form-control" id="notes_full" name="notes" 
                                  rows="2" placeholder="Additional notes..."></textarea>
                    </div>
                    
                    <!-- Preview -->
                    <div class="card bg-light">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <h6>Total Intake</h6>
                                    <div class="h4 text-primary" id="preview_intake">0 ml</div>
                                </div>
                                <div class="col-md-4">
                                    <h6>Total Output</h6>
                                    <div class="h4 text-warning" id="preview_output">0 ml</div>
                                </div>
                                <div class="col-md-4">
                                    <h6>Fluid Balance</h6>
                                    <div class="h4 text-success" id="preview_balance">0 ml</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_io_entry" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i>Save Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- I/O Entry Details Modal -->
<div class="modal fade" id="ioDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">I/O Entry Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="ioDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    // Initialize date/time pickers
    $('#entry_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });
    
    // Calculate preview on input change
    $('input[name^="oral_intake"], input[name^="iv_intake"], input[name^="tube_intake"], ' +
      'input[name^="urine_output"], input[name^="vomit_output"], input[name^="drain_output"], input[name^="other_output"]')
      .on('input', calculatePreview);
    
    calculatePreview(); // Initial calculation
    
    // Initialize fluid balance chart
    initFluidBalanceChart();
    
    // Auto-expand textareas
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});

function quickEntry(type, amount) {
    switch(type) {
        case 'water':
            $('#oral_intake').val(amount);
            break;
        case 'iv':
            $('#iv_intake').val(amount);
            break;
        case 'urine':
            $('#urine_output').val(amount);
            break;
        case 'vomit':
            $('#vomit_output').val(amount);
            break;
    }
    
    // Submit the form
    $('#quickIOForm').submit();
}

function setIntake(type, amount) {
    switch(type) {
        case 'oral':
            $('#oral_intake_full').val(amount);
            break;
        case 'iv':
            $('#iv_intake_full').val(amount);
            break;
        case 'tube':
            $('#tube_intake_full').val(amount);
            break;
    }
    calculatePreview();
}

function setOutput(type, amount) {
    switch(type) {
        case 'urine':
            $('#urine_output_full').val(amount);
            break;
        case 'vomit':
            $('#vomit_output_full').val(amount);
            break;
        case 'drain':
            $('#drain_output_full').val(amount);
            break;
        case 'other':
            $('#other_output_full').val(amount);
            break;
    }
    calculatePreview();
}

function calculatePreview() {
    // Get intake values
    const oral = parseFloat($('#oral_intake_full').val()) || 0;
    const iv = parseFloat($('#iv_intake_full').val()) || 0;
    const tube = parseFloat($('#tube_intake_full').val()) || 0;
    
    // Get output values
    const urine = parseFloat($('#urine_output_full').val()) || 0;
    const vomit = parseFloat($('#vomit_output_full').val()) || 0;
    const drain = parseFloat($('#drain_output_full').val()) || 0;
    const other = parseFloat($('#other_output_full').val()) || 0;
    
    // Calculate totals
    const totalIntake = oral + iv + tube;
    const totalOutput = urine + vomit + drain + other;
    const balance = totalIntake - totalOutput;
    
    // Update preview
    $('#preview_intake').text(totalIntake + ' ml');
    $('#preview_output').text(totalOutput + ' ml');
    $('#preview_balance').text(balance + ' ml');
    
    // Color code balance
    const balanceEl = $('#preview_balance');
    balanceEl.removeClass('text-success text-danger text-warning');
    if (balance > 0) {
        balanceEl.addClass('text-success');
    } else if (balance < 0) {
        balanceEl.addClass('text-danger');
    } else {
        balanceEl.addClass('text-warning');
    }
}

function viewIOEntry(entry) {
    const modalContent = document.getElementById('ioDetailsContent');
    const entryDate = new Date(entry.record_date + 'T' + entry.record_time);
    const totalIntake = entry.oral_intake + entry.iv_intake + entry.tube_intake;
    const totalOutput = entry.urine_output + entry.vomit_output + entry.drain_output + entry.other_output;
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <h6 class="card-title mb-0">
                    I/O Entry - ${entryDate.toLocaleDateString()} ${entryDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                </h6>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="50%">Recorded By:</th>
                                <td>${entry.recorded_by_name}</td>
                            </tr>
                            <tr>
                                <th>Date:</th>
                                <td>${entryDate.toLocaleDateString()}</td>
                            </tr>
                            <tr>
                                <th>Time:</th>
                                <td>${entryDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="text-center">
                            <h6>Fluid Balance</h6>
                            <div class="display-4 ${entry.fluid_balance >= 0 ? 'text-success' : 'text-danger'}">
                                ${entry.fluid_balance} ml
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Intake Details -->
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white py-1">
                        <h6 class="card-title mb-0">Intake Details</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h6>Oral</h6>
                                    <div class="h3 text-primary">${entry.oral_intake} ml</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h6>IV</h6>
                                    <div class="h3 text-primary">${entry.iv_intake} ml</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h6>Tube</h6>
                                    <div class="h3 text-primary">${entry.tube_intake} ml</div>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <strong>Total Intake: ${totalIntake} ml</strong>
                        </div>
                    </div>
                </div>
                
                <!-- Output Details -->
                <div class="card mb-3">
                    <div class="card-header bg-warning text-white py-1">
                        <h6 class="card-title mb-0">Output Details</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h6>Urine</h6>
                                    <div class="h3 text-warning">${entry.urine_output} ml</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h6>Vomit</h6>
                                    <div class="h3 text-warning">${entry.vomit_output} ml</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h6>Drain</h6>
                                    <div class="h3 text-warning">${entry.drain_output} ml</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h6>Other</h6>
                                    <div class="h3 text-warning">${entry.other_output} ml</div>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <strong>Total Output: ${totalOutput} ml</strong>
                        </div>
                    </div>
                </div>
                
                ${entry.notes ? `<div class="mb-3">
                    <h6><i class="fas fa-note mr-2"></i>Notes</h6>
                    <div class="p-3 bg-light rounded">${entry.notes.replace(/\n/g, '<br>')}</div>
                </div>` : ''}
            </div>
        </div>
    `;
    
    modalContent.innerHTML = html;
    $('#ioDetailsModal').modal('show');
}

function initFluidBalanceChart() {
    // Prepare data for last 24 hours
    const now = new Date();
    const labels = [];
    const intakeData = [];
    const outputData = [];
    const balanceData = [];
    
    // Create 24 hourly slots
    for (let i = 23; i >= 0; i--) {
        const hour = new Date(now);
        hour.setHours(now.getHours() - i);
        labels.push(hour.getHours() + ':00');
        
        // Filter entries for this hour
        const hourStart = new Date(hour);
        hourStart.setMinutes(0, 0, 0);
        const hourEnd = new Date(hour);
        hourEnd.setMinutes(59, 59, 999);
        
        let hourIntake = 0;
        let hourOutput = 0;
        
        <?php foreach ($io_entries as $entry): ?>
            const entryTime = new Date('<?php echo $entry['record_date']; ?>T<?php echo $entry['record_time']; ?>');
            if (entryTime >= hourStart && entryTime <= hourEnd) {
                hourIntake += <?php echo $entry['total_intake']; ?>;
                hourOutput += <?php echo $entry['total_output']; ?>;
            }
        <?php endforeach; ?>
        
        intakeData.push(hourIntake);
        outputData.push(hourOutput);
        balanceData.push(hourIntake - hourOutput);
    }
    
    const ctx = document.getElementById('fluidBalanceChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Intake (ml)',
                    data: intakeData,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Output (ml)',
                    data: outputData,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Balance (ml)',
                    data: balanceData,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: false,
                    tension: 0.4,
                    borderDash: [5, 5]
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Milliliters (ml)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Time (Hours)'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            }
        }
    });
}

function printIOCard() {
    const printWindow = window.open('', '_blank');
    const patientInfo = `Patient: <?php echo $full_name; ?> | MRN: <?php echo $patient_info['patient_mrn']; ?> | Visit ID: <?php echo $visit_id; ?>`;
    
    let html = `
        <html>
        <head>
            <title>I/O Chart - <?php echo $patient_info['patient_mrn']; ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1, h2 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
                th { background-color: #f2f2f2; }
                .intake { background-color: #e3f2fd; }
                .output { background-color: #fff3e0; }
                .positive { color: #2e7d32; }
                .negative { color: #c62828; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; padding: 10px; }
                    table { font-size: 10px; }
                }
            </style>
        </head>
        <body>
            <h1>Intake/Output Chart</h1>
            <h2>${patientInfo}</h2>
            <p>Printed: ${new Date().toLocaleString()}</p>
            
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th colspan="3" class="intake">Intake (ml)</th>
                        <th colspan="4" class="output">Output (ml)</th>
                        <th>Balance</th>
                    </tr>
                    <tr>
                        <th></th>
                        <th class="intake">Oral</th>
                        <th class="intake">IV</th>
                        <th class="intake">Tube</th>
                        <th class="output">Urine</th>
                        <th class="output">Vomit</th>
                        <th class="output">Drain</th>
                        <th class="output">Other</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    <?php foreach ($io_entries as $entry): ?>
        const entryDate = new Date('<?php echo $entry['record_date']; ?>T<?php echo $entry['record_time']; ?>');
        const totalIntake = <?php echo $entry['oral_intake'] + $entry['iv_intake'] + $entry['tube_intake']; ?>;
        const totalOutput = <?php echo $entry['urine_output'] + $entry['vomit_output'] + $entry['drain_output'] + $entry['other_output']; ?>;
        html += `
            <tr>
                <td>${entryDate.toLocaleDateString()} ${entryDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                <td class="intake"><?php echo $entry['oral_intake']; ?></td>
                <td class="intake"><?php echo $entry['iv_intake']; ?></td>
                <td class="intake"><?php echo $entry['tube_intake']; ?></td>
                <td class="output"><?php echo $entry['urine_output']; ?></td>
                <td class="output"><?php echo $entry['vomit_output']; ?></td>
                <td class="output"><?php echo $entry['drain_output']; ?></td>
                <td class="output"><?php echo $entry['other_output']; ?></td>
                <td class="${<?php echo $entry['fluid_balance']; ?> >= 0 ? 'positive' : 'negative'}">
                    <?php echo $entry['fluid_balance']; ?>
                </td>
            </tr>
        `;
    <?php endforeach; ?>
    
    html += `
                </tbody>
            </table>
            
            <div class="no-print">
                <br><br>
                <button onclick="window.print()">Print</button>
                <button onclick="window.close()">Close</button>
            </div>
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
}

function showToast(message, type = 'info') {
    const toast = $(`
        <div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000">
            <div class="toast-header bg-${type} text-white">
                <strong class="mr-auto"><i class="fas fa-info-circle mr-2"></i>Notification</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `);
    
    $('.toast-container').remove();
    $('<div class="toast-container position-fixed" style="top: 20px; right: 20px; z-index: 9999;"></div>')
        .append(toast)
        .appendTo('body');
    
    toast.toast('show');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + I for new I/O entry
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        $('#addIOEntryModal').modal('show');
    }
    // Ctrl + Q for quick entry
    if (e.ctrlKey && e.keyCode === 81) {
        e.preventDefault();
        $('#oral_intake').focus();
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printIOCard();
    }
});
</script>

<style>
.table-info {
    background-color: #d1ecf1 !important;
}
.bg-light td {
    font-weight: bold;
}

.progress {
    background-color: #e9ecef;
}
.progress-bar {
    transition: width 0.3s ease;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .card-footer, .toast-container, .no-print {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    table {
        font-size: 9px !important;
    }
    canvas {
        display: none !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
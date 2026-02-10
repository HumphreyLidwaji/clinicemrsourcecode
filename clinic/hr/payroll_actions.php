<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check for period_id in both GET and POST
$period_id = null;
if (isset($_GET['period_id']) && !empty($_GET['period_id'])) {
    $period_id = intval($_GET['period_id']);
} elseif (isset($_POST['period_id']) && !empty($_POST['period_id'])) {
    $period_id = intval($_POST['period_id']);
}

if (!$period_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Payroll period ID is required!";
    header("Location: payroll_management.php");
    exit;
}

// Get period details
$period_sql = "SELECT * FROM payroll_periods WHERE period_id = ?";
$period_stmt = $mysqli->prepare($period_sql);
$period_stmt->bind_param("i", $period_id);
$period_stmt->execute();
$period = $period_stmt->get_result()->fetch_assoc();

if (!$period) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Payroll period not found!";
    header("Location: payroll_management.php");
    exit;
}

// Get active employees
$employees_sql = "SELECT e.*, d.department_name, j.title as job_title 
                 FROM employees e 
                 LEFT JOIN departments d ON e.department_id = d.department_id 
                 LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id 
                 WHERE e.employment_status = 'Active' 
                 ORDER BY e.first_name, e.last_name";
$employees_result = $mysqli->query($employees_sql);

if (!$employees_result) {
    die("Error getting employees: " . $mysqli->error);
}

// Handle all payroll actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Handle payroll processing
    if ($_POST['action'] == 'process_payroll') {
        try {
            $mysqli->begin_transaction();
            
            $employee_ids = $_POST['employee_id'] ?? [];
            $basic_salaries = $_POST['basic_salary'] ?? [];
            $allowances = $_POST['allowances'] ?? [];
            $deductions = $_POST['deductions'] ?? [];
            
            $processed_count = 0;
            
            foreach ($employee_ids as $index => $employee_id) {
                $employee_id = intval($employee_id);
                $basic_salary = floatval($basic_salaries[$index]);
                $total_allowances = floatval($allowances[$index]);
                $other_deductions = floatval($deductions[$index]);
                
                // Calculate gross pay
                $gross_pay = $basic_salary + $total_allowances;
                
                // Calculate statutory deductions (Kenya)
                $paye = calculatePAYE($gross_pay);
                $nhif = calculateNHIF($gross_pay);
                $nssf_tier1 = calculateNSSFTier1($gross_pay);
                $nssf_tier2 = calculateNSSFTier2($gross_pay);
                
                $total_statutory_deductions = $paye + $nhif + $nssf_tier1 + $nssf_tier2;
                $total_deductions = $total_statutory_deductions + $other_deductions;
                $net_pay = $gross_pay - $total_deductions;
                
                // Check if transaction already exists
                $check_sql = "SELECT transaction_id FROM payroll_transactions WHERE period_id = ? AND employee_id = ?";
                $check_stmt = $mysqli->prepare($check_sql);
                $check_stmt->bind_param("ii", $period_id, $employee_id);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows > 0) {
                    // Update existing transaction
                    $sql = "UPDATE payroll_transactions SET 
                            basic_salary = ?, total_allowances = ?, gross_pay = ?,
                            paye = ?, nhif = ?, nssf_tier1 = ?, nssf_tier2 = ?, total_statutory_deductions = ?,
                            other_deductions = ?, total_deductions = ?, net_pay = ?, status = 'calculated'
                            WHERE period_id = ? AND employee_id = ?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("dddddddddddii", 
                        $basic_salary, $total_allowances, $gross_pay,
                        $paye, $nhif, $nssf_tier1, $nssf_tier2, $total_statutory_deductions,
                        $other_deductions, $total_deductions, $net_pay,
                        $period_id, $employee_id
                    );
                } else {
                    // Insert new transaction
                    $sql = "INSERT INTO payroll_transactions (
                            period_id, employee_id, basic_salary, total_allowances, gross_pay,
                            paye, nhif, nssf_tier1, nssf_tier2, total_statutory_deductions,
                            other_deductions, total_deductions, net_pay, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'calculated')";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("iiddddddddddd", 
                        $period_id, $employee_id, $basic_salary, $total_allowances, $gross_pay,
                        $paye, $nhif, $nssf_tier1, $nssf_tier2, $total_statutory_deductions,
                        $other_deductions, $total_deductions, $net_pay
                    );
                }
                
                if ($stmt->execute()) {
                    $processed_count++;
                } else {
                    throw new Exception("Error processing employee ID $employee_id: " . $stmt->error);
                }
            }
            
            // Update period status
            $update_sql = "UPDATE payroll_periods SET status = 'processing' WHERE period_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $period_id);
            $update_stmt->execute();
            
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'payroll_processed', ?, 'payroll_periods', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Processed payroll for period: " . $period['period_name'] . " ($processed_count employees)";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $period_id);
            $audit_stmt->execute();
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Payroll processed successfully for $processed_count employees!";
            header("Location: payroll_actions.php?period_id=" . $period_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error processing payroll: " . $e->getMessage();
            header("Location: payroll_actions.php?period_id=" . $period_id);
            exit;
        }
    }
    
    // Handle payroll approval
    if ($_POST['action'] == 'approve_payroll') {
        try {
            $mysqli->begin_transaction();
            
            // Verify period exists and is in processing status
            $check_sql = "SELECT * FROM payroll_periods WHERE period_id = ? AND status = 'processing'";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("i", $period_id);
            $check_stmt->execute();
            $period_data = $check_stmt->get_result()->fetch_assoc();
            
            if (!$period_data) {
                throw new Exception("Payroll period not found or not ready for approval!");
            }
            
            // Verify all transactions are calculated
            $transactions_sql = "SELECT COUNT(*) as total, 
                                SUM(CASE WHEN status = 'calculated' THEN 1 ELSE 0 END) as calculated
                                FROM payroll_transactions 
                                WHERE period_id = ?";
            $transactions_stmt = $mysqli->prepare($transactions_sql);
            $transactions_stmt->bind_param("i", $period_id);
            $transactions_stmt->execute();
            $transaction_stats = $transactions_stmt->get_result()->fetch_assoc();
            
            if ($transaction_stats['total'] == 0) {
                throw new Exception("No payroll transactions found for this period!");
            }
            
            if ($transaction_stats['calculated'] != $transaction_stats['total']) {
                throw new Exception("Not all payroll transactions are in calculated status!");
            }
            
            // Update payroll period status to approved
            $update_sql = "UPDATE payroll_periods SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE period_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("ii", $_SESSION['user_id'], $period_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Error approving payroll: " . $update_stmt->error);
            }
            
            // Update all transactions to approved status
            $update_trans_sql = "UPDATE payroll_transactions SET status = 'approved' WHERE period_id = ?";
            $update_trans_stmt = $mysqli->prepare($update_trans_sql);
            $update_trans_stmt->bind_param("i", $period_id);
            $update_trans_stmt->execute();
            
            // Log the approval action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'payroll_approved', ?, 'payroll_periods', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Approved payroll for period: " . $period_data['period_name'];
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $period_id);
            $audit_stmt->execute();
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Payroll approved successfully! The payroll is now locked for payment processing.";
            header("Location: payroll_actions.php?period_id=" . $period_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error approving payroll: " . $e->getMessage();
            header("Location: payroll_actions.php?period_id=" . $period_id);
            exit;
        }
    }
    
    // Handle payroll reopening
    if ($_POST['action'] == 'reopen_payroll') {
        try {
            $mysqli->begin_transaction();
            
            // Verify period exists and is in processing status
            $check_sql = "SELECT * FROM payroll_periods WHERE period_id = ? AND status = 'processing'";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("i", $period_id);
            $check_stmt->execute();
            $period_data = $check_stmt->get_result()->fetch_assoc();
            
            if (!$period_data) {
                throw new Exception("Payroll period not found or cannot be reopened!");
            }
            
            // Update payroll period status back to open
            $update_sql = "UPDATE payroll_periods SET status = 'open' WHERE period_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $period_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Error reopening payroll: " . $update_stmt->error);
            }
            
            // Update all transactions back to draft status
            $update_trans_sql = "UPDATE payroll_transactions SET status = 'draft' WHERE period_id = ?";
            $update_trans_stmt = $mysqli->prepare($update_trans_sql);
            $update_trans_stmt->bind_param("i", $period_id);
            $update_trans_stmt->execute();
            
            // Log the reopening action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'payroll_reopened', ?, 'payroll_periods', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Reopened payroll for editing: " . $period_data['period_name'];
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $period_id);
            $audit_stmt->execute();
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Payroll reopened successfully! You can now make changes to the payroll calculations.";
            header("Location: payroll_actions.php?period_id=" . $period_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error reopening payroll: " . $e->getMessage();
            header("Location: payroll_actions.php?period_id=" . $period_id);
            exit;
        }
    }
}

// Kenya Statutory Calculation Functions
function calculatePAYE($gross_salary) {
    // Personal relief
    $personal_relief = 2400;
    
    if ($gross_salary <= 24000) {
        $tax = $gross_salary * 0.10;
    } elseif ($gross_salary <= 32333) {
        $tax = 2400 + (($gross_salary - 24000) * 0.25);
    } elseif ($gross_salary <= 500000) {
        $tax = 4483 + (($gross_salary - 32333) * 0.30);
    } elseif ($gross_salary <= 800000) {
        $tax = 144783 + (($gross_salary - 500000) * 0.325);
    } else {
        $tax = 242283 + (($gross_salary - 800000) * 0.35);
    }
    
    return max(0, $tax - $personal_relief);
}

function calculateNHIF($gross_salary) {
    $rates = [
        5999 => 150,
        7999 => 300,
        11999 => 400,
        14999 => 500,
        19999 => 600,
        24999 => 750,
        29999 => 850,
        34999 => 900,
        39999 => 950,
        44999 => 1000,
        49999 => 1100,
        59999 => 1200,
        69999 => 1300,
        79999 => 1400,
        89999 => 1500,
        99999 => 1600,
        PHP_FLOAT_MAX => 1700
    ];
    
    foreach ($rates as $limit => $amount) {
        if ($gross_salary <= $limit) {
            return $amount;
        }
    }
    return 1700;
}

function calculateNSSFTier1($gross_salary) {
    $tier1_limit = 6000;
    $tier1_amount = min($gross_salary, $tier1_limit) * 0.06;
    return $tier1_amount;
}

function calculateNSSFTier2($gross_salary) {
    $tier1_limit = 6000;
    $tier2_limit = 18000;
    if ($gross_salary > $tier1_limit) {
        $tier2_amount = min($gross_salary - $tier1_limit, $tier2_limit - $tier1_limit) * 0.06;
        return $tier2_amount;
    }
    return 0;
}

// Get existing payroll transactions for this period
$transactions_sql = "SELECT * FROM payroll_transactions WHERE period_id = ?";
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $period_id);
$transactions_stmt->execute();
$existing_transactions = $transactions_stmt->get_result();

$transaction_data = [];
while ($transaction = $existing_transactions->fetch_assoc()) {
    $transaction_data[$transaction['employee_id']] = $transaction;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payroll - HR System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-header { background-color: #17a2b8; }
        .table th { background-color: #f8f9fa; }
        .totals-row { background-color: #343a40 !important; color: white; }
        .alert { border-radius: 0.5rem; }
        .form-control-sm { min-width: 100px; }
        .badge { font-size: 0.75em; }
        .status-badge { font-size: 0.8em; padding: 0.4em 0.8em; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="card">
            <div class="card-header bg-info py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <h3 class="card-title mt-2 mb-0 text-white">
                        <i class="fas fa-fw fa-calculator mr-2"></i>Process Payroll
                    </h3>
                    <div class="card-tools">
                        <a href="payroll_management.php" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Payroll
                        </a>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <?php if (isset($_SESSION['alert_message'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show" role="alert">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?> mr-2"></i>
                        <?php echo $_SESSION['alert_message']; ?>
                    </div>
                    <?php 
                    unset($_SESSION['alert_type']);
                    unset($_SESSION['alert_message']);
                    ?>
                <?php endif; ?>

                <!-- Period Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Payroll Period: <?php echo htmlspecialchars($period['period_name']); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Start Date:</strong> <?php echo date('M j, Y', strtotime($period['start_date'])); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>End Date:</strong> <?php echo date('M j, Y', strtotime($period['end_date'])); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Pay Date:</strong> <?php echo date('M j, Y', strtotime($period['pay_date'])); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Status:</strong> 
                                <span class="badge status-badge bg-<?php 
                                    switch($period['status']) {
                                        case 'open': echo 'primary'; break;
                                        case 'processing': echo 'warning'; break;
                                        case 'approved': echo 'success'; break;
                                        case 'closed': echo 'secondary'; break;
                                        case 'paid': echo 'info'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst($period['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status Alerts -->
                <?php if ($period['status'] == 'approved'): ?>
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle mr-2"></i>Payroll Approved</h5>
                        <p class="mb-0">This payroll has been approved and is ready for payment processing. No further changes can be made.</p>
                    </div>
                <?php elseif ($period['status'] == 'processing'): ?>
                    <div class="alert alert-warning">
                        <h5><i class="fas fa-clock mr-2"></i>Payroll Processing</h5>
                        <p class="mb-0">Payroll calculations are complete. Please review and approve to proceed with payment.</p>
                    </div>
                <?php endif; ?>

                <!-- Debug Info -->
                <?php if ($employees_result->num_rows == 0): ?>
                <div class="alert alert-warning">
                    <h5><i class="fas fa-exclamation-triangle mr-2"></i>No Active Employees Found</h5>
                    <p>There are no active employees in the system. Please add employees first before processing payroll.</p>
                    <a href="add_employee.php" class="btn btn-primary">
                        <i class="fas fa-user-plus mr-2"></i>Add Employee
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($period['status'] == 'open' || $period['status'] == 'processing'): ?>
                <form method="POST" id="payrollForm">
                    <input type="hidden" name="period_id" value="<?php echo $period_id; ?>">
                    <input type="hidden" name="action" value="process_payroll">
                    
                    <!-- Employees Payroll -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Employee Payroll Calculations</h5>
                            <div>
                                <?php if ($period['status'] == 'open'): ?>
                                    <button type="button" class="btn btn-sm btn-success" onclick="calculateAll()">
                                        <i class="fas fa-calculator mr-2"></i>Calculate All
                                    </button>
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="fas fa-save mr-2"></i>Process Payroll
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($employees_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="payrollTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Department</th>
                                            <th>Basic Salary</th>
                                            <th>Allowances</th>
                                            <th>Gross Pay</th>
                                            <th>PAYE</th>
                                            <th>NHIF</th>
                                            <th>NSSF</th>
                                            <th>Other Ded.</th>
                                            <th>Total Ded.</th>
                                            <th>Net Pay</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $employees_result->data_seek(0); // Reset pointer
                                        while($employee = $employees_result->fetch_assoc()): 
                                            $existing = $transaction_data[$employee['employee_id']] ?? null;
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="hidden" name="employee_id[]" value="<?php echo $employee['employee_id']; ?>">
                                                <strong><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($employee['employee_number']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($period['status'] == 'open'): ?>
                                                    <input type="number" class="form-control form-control-sm basic-salary" 
                                                           name="basic_salary[]" 
                                                           value="<?php echo $existing['basic_salary'] ?? $employee['basic_salary']; ?>" 
                                                           step="0.01" min="0" onchange="calculateRow(this)">
                                                <?php else: ?>
                                                    <span><?php echo number_format($existing['basic_salary'] ?? $employee['basic_salary'], 2); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($period['status'] == 'open'): ?>
                                                    <input type="number" class="form-control form-control-sm allowances" 
                                                           name="allowances[]" 
                                                           value="<?php echo $existing['total_allowances'] ?? 0; ?>" 
                                                           step="0.01" min="0" onchange="calculateRow(this)">
                                                <?php else: ?>
                                                    <span><?php echo number_format($existing['total_allowances'] ?? 0, 2); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="gross-pay"><?php echo number_format($existing['gross_pay'] ?? $employee['basic_salary'], 2); ?></span>
                                            </td>
                                            <td>
                                                <span class="paye"><?php echo number_format($existing['paye'] ?? 0, 2); ?></span>
                                            </td>
                                            <td>
                                                <span class="nhif"><?php echo number_format($existing['nhif'] ?? 0, 2); ?></span>
                                            </td>
                                            <td>
                                                <span class="nssf"><?php echo number_format(($existing['nssf_tier1'] ?? 0) + ($existing['nssf_tier2'] ?? 0), 2); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($period['status'] == 'open'): ?>
                                                    <input type="number" class="form-control form-control-sm deductions" 
                                                           name="deductions[]" 
                                                           value="<?php echo $existing['other_deductions'] ?? 0; ?>" 
                                                           step="0.01" min="0" onchange="calculateRow(this)">
                                                <?php else: ?>
                                                    <span><?php echo number_format($existing['other_deductions'] ?? 0, 2); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="total-deductions"><?php echo number_format($existing['total_deductions'] ?? 0, 2); ?></span>
                                            </td>
                                            <td>
                                                <strong class="net-pay text-success"><?php echo number_format($existing['net_pay'] ?? $employee['basic_salary'], 2); ?></strong>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot class="bg-dark text-white">
                                        <tr>
                                            <td colspan="2"><strong>TOTALS</strong></td>
                                            <td><strong id="total-basic">0.00</strong></td>
                                            <td><strong id="total-allowances">0.00</strong></td>
                                            <td><strong id="total-gross">0.00</strong></td>
                                            <td><strong id="total-paye">0.00</strong></td>
                                            <td><strong id="total-nhif">0.00</strong></td>
                                            <td><strong id="total-nssf">0.00</strong></td>
                                            <td><strong id="total-other-ded">0.00</strong></td>
                                            <td><strong id="total-deductions">0.00</strong></td>
                                            <td><strong id="total-net">0.00</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Action Buttons for Processing Status -->
                        <?php if ($period['status'] == 'processing'): ?>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="text-warning">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>
                                            Payroll is processed and ready for approval
                                        </span>
                                    </div>
                                    <div>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="period_id" value="<?php echo $period_id; ?>">
                                            <input type="hidden" name="action" value="approve_payroll">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to approve this payroll? This will lock the payroll for payment and cannot be undone.')">
                                                <i class="fas fa-check-circle mr-2"></i>Approve Payroll
                                            </button>
                                        </form>
                                        
                                        <form method="POST" class="d-inline ms-2">
                                            <input type="hidden" name="period_id" value="<?php echo $period_id; ?>">
                                            <input type="hidden" name="action" value="reopen_payroll">
                                            <button type="submit" class="btn btn-warning" onclick="return confirm('Reopen this payroll for editing? All calculations will remain but status will be reset to draft.')">
                                                <i class="fas fa-edit mr-2"></i>Reopen for Editing
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap & jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    function calculateRow(input) {
        const row = input.closest('tr');
        const basicSalary = parseFloat(row.querySelector('.basic-salary').value) || 0;
        const allowances = parseFloat(row.querySelector('.allowances').value) || 0;
        const otherDeductions = parseFloat(row.querySelector('.deductions').value) || 0;
        
        // Calculate gross pay
        const grossPay = basicSalary + allowances;
        row.querySelector('.gross-pay').textContent = grossPay.toFixed(2);
        
        // Calculate statutory deductions (simplified for frontend - actual calculation is server-side)
        const paye = Math.max(0, (grossPay * 0.1) - 2400); // Simplified PAYE
        const nhif = calculateNHIFFrontend(grossPay);
        const nssfTier1 = Math.min(6000, grossPay) * 0.06;
        const nssfTier2 = grossPay > 6000 ? Math.min(12000, grossPay - 6000) * 0.06 : 0;
        const nssf = nssfTier1 + nssfTier2;
        
        row.querySelector('.paye').textContent = paye.toFixed(2);
        row.querySelector('.nhif').textContent = nhif.toFixed(2);
        row.querySelector('.nssf').textContent = nssf.toFixed(2);
        
        // Calculate totals
        const totalDeductions = paye + nhif + nssf + otherDeductions;
        const netPay = grossPay - totalDeductions;
        
        row.querySelector('.total-deductions').textContent = totalDeductions.toFixed(2);
        row.querySelector('.net-pay').textContent = netPay.toFixed(2);
        
        calculateTotals();
    }

    function calculateNHIFFrontend(grossSalary) {
        const rates = [
            [5999, 150],
            [7999, 300],
            [11999, 400],
            [14999, 500],
            [19999, 600],
            [24999, 750],
            [29999, 850],
            [34999, 900],
            [39999, 950],
            [44999, 1000],
            [49999, 1100],
            [59999, 1200],
            [69999, 1300],
            [79999, 1400],
            [89999, 1500],
            [99999, 1600],
            [Infinity, 1700]
        ];
        
        for (const [limit, amount] of rates) {
            if (grossSalary <= limit) {
                return amount;
            }
        }
        return 1700;
    }

    function calculateAll() {
        const rows = document.querySelectorAll('#payrollTable tbody tr');
        rows.forEach(row => {
            const basicInput = row.querySelector('.basic-salary');
            calculateRow(basicInput);
        });
    }

    function calculateTotals() {
        let totalBasic = 0, totalAllowances = 0, totalGross = 0, totalPaye = 0;
        let totalNhif = 0, totalNssf = 0, totalOtherDed = 0, totalDeductions = 0, totalNet = 0;
        
        document.querySelectorAll('#payrollTable tbody tr').forEach(row => {
            totalBasic += parseFloat(row.querySelector('.basic-salary').value) || 0;
            totalAllowances += parseFloat(row.querySelector('.allowances').value) || 0;
            totalGross += parseFloat(row.querySelector('.gross-pay').textContent) || 0;
            totalPaye += parseFloat(row.querySelector('.paye').textContent) || 0;
            totalNhif += parseFloat(row.querySelector('.nhif').textContent) || 0;
            totalNssf += parseFloat(row.querySelector('.nssf').textContent) || 0;
            totalOtherDed += parseFloat(row.querySelector('.deductions').value) || 0;
            totalDeductions += parseFloat(row.querySelector('.total-deductions').textContent) || 0;
            totalNet += parseFloat(row.querySelector('.net-pay').textContent) || 0;
        });
        
        document.getElementById('total-basic').textContent = totalBasic.toFixed(2);
        document.getElementById('total-allowances').textContent = totalAllowances.toFixed(2);
        document.getElementById('total-gross').textContent = totalGross.toFixed(2);
        document.getElementById('total-paye').textContent = totalPaye.toFixed(2);
        document.getElementById('total-nhif').textContent = totalNhif.toFixed(2);
        document.getElementById('total-nssf').textContent = totalNssf.toFixed(2);
        document.getElementById('total-other-ded').textContent = totalOtherDed.toFixed(2);
        document.getElementById('total-deductions').textContent = totalDeductions.toFixed(2);
        document.getElementById('total-net').textContent = totalNet.toFixed(2);
    }

    // Initialize calculations on page load
    document.addEventListener('DOMContentLoaded', function() {
        calculateAll();
        
        // Form submission for processing
        const payrollForm = document.getElementById('payrollForm');
        if (payrollForm) {
            payrollForm.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to process payroll? This will calculate all statutory deductions and move to approval stage.')) {
                    e.preventDefault();
                    return;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                submitBtn.disabled = true;
            });
        }
    });
    </script>
</body>
</html>
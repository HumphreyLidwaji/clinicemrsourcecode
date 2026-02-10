<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Employee ID is required!";
    header("Location: manage_employees.php");
    exit;
}

$employee_id = intval($_GET['id']);

// Get employee details
$employee_sql = "SELECT e.*, d.department_name, j.title as job_title 
                 FROM employees e 
                 LEFT JOIN departments d ON e.department_id = d.department_id 
                 LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id 
                 WHERE e.employee_id = ?";
$employee_stmt = $mysqli->prepare($employee_sql);
$employee_stmt->bind_param("i", $employee_id);
$employee_stmt->execute();
$employee = $employee_stmt->get_result()->fetch_assoc();

if (!$employee) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Employee not found!";
    header("Location: manage_employees.php");
    exit;
}

// Get payroll periods for dropdown
$periods_sql = "SELECT period_id, period_name, start_date, end_date, pay_date, status 
                FROM payroll_periods 
                WHERE status IN ('open', 'processing', 'approved', 'paid')
                ORDER BY start_date DESC";
$periods_result = $mysqli->query($periods_sql);

// Get payroll history for this employee
$payroll_history_sql = "SELECT pt.*, pp.period_name, pp.start_date, pp.end_date, pp.pay_date, pp.status as period_status
                       FROM payroll_transactions pt
                       JOIN payroll_periods pp ON pt.period_id = pp.period_id
                       WHERE pt.employee_id = ?
                       ORDER BY pp.start_date DESC";
$payroll_history_stmt = $mysqli->prepare($payroll_history_sql);
$payroll_history_stmt->bind_param("i", $employee_id);
$payroll_history_stmt->execute();
$payroll_history = $payroll_history_stmt->get_result();

// Calculate totals
$total_gross = 0;
$total_deductions = 0;
$total_net = 0;
$history_data = [];
while ($row = $payroll_history->fetch_assoc()) {
    $history_data[] = $row;
    $total_gross += $row['gross_pay'];
    $total_deductions += $row['total_deductions'];
    $total_net += $row['net_pay'];
}

// Handle quick payroll calculation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'quick_calculate') {
        try {
            $period_id = intval($_POST['period_id']);
            $basic_salary = floatval($_POST['basic_salary'] ?? $employee['basic_salary']);
            $allowances = floatval($_POST['allowances'] ?? 0);
            $deductions = floatval($_POST['other_deductions'] ?? 0);
            
            // Calculate payroll
            $gross_pay = $basic_salary + $allowances;
            $paye = calculatePAYE($gross_pay);
            $nhif = calculateNHIF($gross_pay);
            $nssf_tier1 = calculateNSSFTier1($gross_pay);
            $nssf_tier2 = calculateNSSFTier2($gross_pay);
            $total_statutory_deductions = $paye + $nhif + $nssf_tier1 + $nssf_tier2;
            $total_deductions = $total_statutory_deductions + $deductions;
            $net_pay = $gross_pay - $total_deductions;
            
            $calculation_result = [
                'basic_salary' => $basic_salary,
                'allowances' => $allowances,
                'gross_pay' => $gross_pay,
                'paye' => $paye,
                'nhif' => $nhif,
                'nssf_tier1' => $nssf_tier1,
                'nssf_tier2' => $nssf_tier2,
                'total_statutory_deductions' => $total_statutory_deductions,
                'other_deductions' => $deductions,
                'total_deductions' => $total_deductions,
                'net_pay' => $net_pay,
                'period_id' => $period_id
            ];
            
        } catch (Exception $e) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Calculation error: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] == 'save_payroll') {
        try {
            $mysqli->begin_transaction();
            
            $period_id = intval($_POST['period_id']);
            $basic_salary = floatval($_POST['basic_salary']);
            $allowances = floatval($_POST['allowances']);
            $deductions = floatval($_POST['other_deductions']);
            
            // Calculate payroll
            $gross_pay = $basic_salary + $allowances;
            $paye = calculatePAYE($gross_pay);
            $nhif = calculateNHIF($gross_pay);
            $nssf_tier1 = calculateNSSFTier1($gross_pay);
            $nssf_tier2 = calculateNSSFTier2($gross_pay);
            $total_statutory_deductions = $paye + $nhif + $nssf_tier1 + $nssf_tier2;
            $total_deductions = $total_statutory_deductions + $deductions;
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
                    $basic_salary, $allowances, $gross_pay,
                    $paye, $nhif, $nssf_tier1, $nssf_tier2, $total_statutory_deductions,
                    $deductions, $total_deductions, $net_pay,
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
                    $period_id, $employee_id, $basic_salary, $allowances, $gross_pay,
                    $paye, $nhif, $nssf_tier1, $nssf_tier2, $total_statutory_deductions,
                    $deductions, $total_deductions, $net_pay
                );
            }
            
            if ($stmt->execute()) {
                // Log the action
                $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'payroll_calculated', ?, 'payroll_transactions', ?)";
                $audit_stmt = $mysqli->prepare($audit_sql);
                $description = "Calculated payroll for " . $employee['first_name'] . " " . $employee['last_name'];
                $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $employee_id);
                $audit_stmt->execute();
                
                $mysqli->commit();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Payroll calculated and saved successfully!";
                header("Location: employee_payroll.php?id=" . $employee_id);
                exit;
            } else {
                throw new Exception("Save failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error saving payroll: " . $e->getMessage();
        }
    }
}

// Kenya Statutory Calculation Functions (same as before)
function calculatePAYE($gross_salary) {
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Payroll - <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-header { background-color: #17a2b8; }
        .table th { background-color: #f8f9fa; }
        .calculation-card { border-left: 4px solid #28a745; }
        .history-card { border-left: 4px solid #ffc107; }
        .stat-card { background: linear-gradient(45deg, #f8f9fa, #e9ecef); }
        .badge-status { font-size: 0.75em; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="card">
            <div class="card-header bg-info py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <h3 class="card-title mt-2 mb-0 text-white">
                        <i class="fas fa-fw fa-money-bill-wave mr-2"></i>Employee Payroll
                    </h3>
                    <div class="card-tools">
                        <a href="view_employee.php?id=<?php echo $employee_id; ?>" class="btn btn-light btn-sm">
                            <i class="fas fa-user mr-2"></i>View Employee
                        </a>
                        <a href="manage_employees.php" class="btn btn-light btn-sm ml-2">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Employees
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

                <!-- Employee Summary -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-tie mr-2"></i>
                            <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Employee ID:</strong> <?php echo htmlspecialchars($employee['employee_number']); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Department:</strong> <?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Job Title:</strong> <?php echo htmlspecialchars($employee['job_title'] ?? 'N/A'); ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Basic Salary:</strong> KES <?php echo number_format($employee['basic_salary'], 2); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <!-- Quick Payroll Calculation -->
                        <div class="card calculation-card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-calculator mr-2"></i>Quick Payroll Calculation
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="calculationForm">
                                    <input type="hidden" name="action" value="quick_calculate">
                                    
                                    <div class="form-group mb-3">
                                        <label>Payroll Period <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="period_id" required>
                                            <option value="">Select Period</option>
                                            <?php while($period = $periods_result->fetch_assoc()): ?>
                                                <option value="<?php echo $period['period_id']; ?>">
                                                    <?php echo htmlspecialchars($period['period_name']); ?> 
                                                    (<?php echo date('M j, Y', strtotime($period['start_date'])); ?> - <?php echo date('M j, Y', strtotime($period['end_date'])); ?>)
                                                    - <?php echo ucfirst($period['status']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label>Basic Salary (KES)</label>
                                                <input type="number" class="form-control" name="basic_salary" 
                                                       value="<?php echo $employee['basic_salary']; ?>" step="0.01" min="0">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-3">
                                                <label>Allowances (KES)</label>
                                                <input type="number" class="form-control" name="allowances" value="0" step="0.01" min="0">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <label>Other Deductions (KES)</label>
                                        <input type="number" class="form-control" name="other_deductions" value="0" step="0.01" min="0">
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-calculator mr-2"></i>Calculate Payroll
                                    </button>
                                </form>

                                <?php if (isset($calculation_result)): ?>
                                <hr>
                                <div class="calculation-result">
                                    <h6 class="text-success mb-3"><i class="fas fa-chart-bar mr-2"></i>Calculation Results</h6>
                                    
                                    <form method="POST" id="saveForm">
                                        <input type="hidden" name="action" value="save_payroll">
                                        <input type="hidden" name="period_id" value="<?php echo $calculation_result['period_id']; ?>">
                                        <input type="hidden" name="basic_salary" value="<?php echo $calculation_result['basic_salary']; ?>">
                                        <input type="hidden" name="allowances" value="<?php echo $calculation_result['allowances']; ?>">
                                        <input type="hidden" name="other_deductions" value="<?php echo $calculation_result['other_deductions']; ?>">
                                        
                                        <div class="row">
                                            <div class="col-6">
                                                <strong>Gross Pay:</strong>
                                            </div>
                                            <div class="col-6 text-end">
                                                KES <?php echo number_format($calculation_result['gross_pay'], 2); ?>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6">
                                                <small>PAYE:</small>
                                            </div>
                                            <div class="col-6 text-end">
                                                <small>KES <?php echo number_format($calculation_result['paye'], 2); ?></small>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6">
                                                <small>NHIF:</small>
                                            </div>
                                            <div class="col-6 text-end">
                                                <small>KES <?php echo number_format($calculation_result['nhif'], 2); ?></small>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6">
                                                <small>NSSF:</small>
                                            </div>
                                            <div class="col-6 text-end">
                                                <small>KES <?php echo number_format($calculation_result['nssf_tier1'] + $calculation_result['nssf_tier2'], 2); ?></small>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6">
                                                <strong>Total Deductions:</strong>
                                            </div>
                                            <div class="col-6 text-end">
                                                <strong>KES <?php echo number_format($calculation_result['total_deductions'], 2); ?></strong>
                                            </div>
                                        </div>
                                        <hr>
                                        <div class="row">
                                            <div class="col-6">
                                                <h6>Net Pay:</h6>
                                            </div>
                                            <div class="col-6 text-end">
                                                <h6 class="text-success">KES <?php echo number_format($calculation_result['net_pay'], 2); ?></h6>
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-success w-100 mt-3">
                                            <i class="fas fa-save mr-2"></i>Save Calculation
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Statistics -->
                        <div class="card stat-card">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Payroll Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="border rounded p-3 bg-white">
                                            <h4 class="text-primary mb-0"><?php echo count($history_data); ?></h4>
                                            <small class="text-muted">Pay Periods</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded p-3 bg-white">
                                            <h4 class="text-success mb-0">KES <?php echo number_format($total_net, 2); ?></h4>
                                            <small class="text-muted">Total Net Pay</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded p-3 bg-white">
                                            <h4 class="text-danger mb-0">KES <?php echo number_format($total_deductions, 2); ?></h4>
                                            <small class="text-muted">Total Deductions</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <!-- Payroll History -->
                        <div class="card history-card">
                            <div class="card-header bg-warning">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history mr-2"></i>Payroll History
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($history_data) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Period</th>
                                                    <th>Gross Pay</th>
                                                    <th>Deductions</th>
                                                    <th>Net Pay</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($history_data as $payroll): ?>
                                                <tr>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($payroll['period_name']); ?></small><br>
                                                        <small class="text-muted"><?php echo date('M j, Y', strtotime($payroll['pay_date'])); ?></small>
                                                    </td>
                                                    <td>KES <?php echo number_format($payroll['gross_pay'], 2); ?></td>
                                                    <td>KES <?php echo number_format($payroll['total_deductions'], 2); ?></td>
                                                    <td><strong>KES <?php echo number_format($payroll['net_pay'], 2); ?></strong></td>
                                                    <td>
                                                        <span class="badge badge-status bg-<?php 
                                                            switch($payroll['period_status']) {
                                                                case 'open': echo 'primary'; break;
                                                                case 'processing': echo 'warning'; break;
                                                                case 'approved': echo 'success'; break;
                                                                case 'paid': echo 'info'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo ucfirst($payroll['period_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="payroll_view.php?period_id=<?php echo $payroll['period_id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if ($payroll['period_status'] == 'open'): ?>
                                                            <a href="payroll_actions.php?period_id=<?php echo $payroll['period_id']; ?>" 
                                                               class="btn btn-sm btn-outline-warning" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No payroll records found for this employee.</p>
                                        <p class="text-muted small">Use the calculator to create payroll records.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2').select2();

        // Form submission handling
        $('#calculationForm').on('submit', function(e) {
            $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Calculating...').prop('disabled', true);
        });

        $('#saveForm').on('submit', function(e) {
            if (!confirm('Save this payroll calculation?')) {
                e.preventDefault();
                return;
            }
            $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
        });
    });
    </script>
</body>
</html>
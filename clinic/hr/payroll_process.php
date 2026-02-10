<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Check if period ID is provided
if (!isset($_GET['period_id']) || empty($_GET['period_id'])) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'No payroll period ID provided.';
    header("Location: payroll_management.php");
    exit;
}

$period_id = intval($_GET['period_id']);

// Get period details
$period_sql = "SELECT * FROM payroll_periods WHERE period_id = ?";
$period_stmt = $mysqli->prepare($period_sql);
$period_stmt->bind_param("i", $period_id);
$period_stmt->execute();
$period_result = $period_stmt->get_result();

if ($period_result->num_rows === 0) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'Payroll period not found.';
    header("Location: payroll_management.php");
    exit;
}

$period = $period_result->fetch_assoc();

// Handle payroll processing actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($_POST['action'] == 'calculate_payroll') {
        // Get all active employees
        $employees_sql = "SELECT * FROM employees WHERE employment_status = 'Active'";
        $employees_result = $mysqli->query($employees_sql);
        
        $processed_count = 0;
        $errors = [];
        
        while ($employee = $employees_result->fetch_assoc()) {
            try {
                // Calculate payroll for each employee
                $basic_salary = $employee['basic_salary'];
                $allowances = 0;
                $overtime = 0;
                $bonuses = 0;
                
                // Calculate gross pay
                $gross_pay = $basic_salary + $allowances + $overtime + $bonuses;
                
                // Calculate deductions (Kenya-specific)
                $paye = calculatePAYE($gross_pay);
                $nhif = calculateNHIF($gross_pay);
                $nssf = calculateNSSF($gross_pay);
                $other_deductions = 0;
                
                $total_deductions = $paye + $nhif + $nssf + $other_deductions;
                $net_pay = $gross_pay - $total_deductions;
                
                // Check if transaction already exists
                $check_sql = "SELECT transaction_id FROM payroll_transactions WHERE period_id = ? AND employee_id = ?";
                $check_stmt = $mysqli->prepare($check_sql);
                $check_stmt->bind_param("ii", $period_id, $employee['employee_id']);
                $check_stmt->execute();
                
                if ($check_stmt->get_result()->num_rows > 0) {
                    // Update existing transaction
                    $sql = "UPDATE payroll_transactions 
                            SET basic_salary = ?, allowances = ?, overtime = ?, bonuses = ?, 
                                gross_pay = ?, paye = ?, nhif = ?, nssf_tier1 = ?, nssf_tier2 = ?,
                                other_deductions = ?, total_deductions = ?, net_pay = ?, status = 'calculated'
                            WHERE period_id = ? AND employee_id = ?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("ddddddddddddii", $basic_salary, $allowances, $overtime, $bonuses, 
                                    $gross_pay, $paye, $nhif, $nssf['tier1'], $nssf['tier2'],
                                    $other_deductions, $total_deductions, $net_pay, $period_id, $employee['employee_id']);
                } else {
                    // Insert new transaction
                    $sql = "INSERT INTO payroll_transactions 
                            (period_id, employee_id, basic_salary, allowances, overtime, bonuses,
                             gross_pay, paye, nhif, nssf_tier1, nssf_tier2, other_deductions, 
                             total_deductions, net_pay, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'calculated')";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("iidddddddddddd", $period_id, $employee['employee_id'], $basic_salary, 
                                    $allowances, $overtime, $bonuses, $gross_pay, $paye, $nhif, 
                                    $nssf['tier1'], $nssf['tier2'], $other_deductions, $total_deductions, $net_pay);
                }
                
                if ($stmt->execute()) {
                    $processed_count++;
                } else {
                    $errors[] = "Error processing employee {$employee['first_name']} {$employee['last_name']}: " . $stmt->error;
                }
            } catch (Exception $e) {
                $errors[] = "Error processing employee {$employee['first_name']} {$employee['last_name']}: " . $e->getMessage();
            }
        }
        
        // Update period status
        $update_sql = "UPDATE payroll_periods SET status = 'processing' WHERE period_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $period_id);
        $update_stmt->execute();
        
        if (empty($errors)) {
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = "Payroll calculated successfully! Processed $processed_count employees.";
        } else {
            $_SESSION['alert_type'] = 'warning';
            $_SESSION['alert_message'] = "Payroll processed with some errors. Success: $processed_count, Errors: " . count($errors);
            $_SESSION['alert_details'] = $errors;
        }
        
        header("Location: payroll_process.php?period_id=$period_id");
        exit;
    }
    
    if ($_POST['action'] == 'approve_payroll') {
        $sql = "UPDATE payroll_transactions SET status = 'approved' WHERE period_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $period_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Payroll approved successfully!';
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error approving payroll: ' . $stmt->error;
        }
        
        header("Location: payroll_process.php?period_id=$period_id");
        exit;
    }
}

// Get payroll transactions for this period
$transactions_sql = "SELECT 
                        pt.*,
                        e.first_name, e.last_name, e.employee_number,
                        d.department_name,
                        j.title as position
                     FROM payroll_transactions pt
                     JOIN employees e ON pt.employee_id = e.employee_id
                     LEFT JOIN departments d ON e.department_id = d.department_id
                     LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
                     WHERE pt.period_id = ?
                     ORDER BY e.first_name, e.last_name";
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $period_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

// Calculate totals
$totals_sql = "SELECT 
                 COUNT(*) as employee_count,
                 SUM(gross_pay) as total_gross,
                 SUM(paye) as total_paye,
                 SUM(nhif) as total_nhif,
                 SUM(nssf_tier1 + nssf_tier2) as total_nssf,
                 SUM(other_deductions) as total_other_deductions,
                 SUM(total_deductions) as total_deductions,
                 SUM(net_pay) as total_net_pay
               FROM payroll_transactions 
               WHERE period_id = ?";
$totals_stmt = $mysqli->prepare($totals_sql);
$totals_stmt->bind_param("i", $period_id);
$totals_stmt->execute();
$totals = $totals_stmt->get_result()->fetch_assoc();

// Kenya tax calculation functions
function calculatePAYE($gross_pay) {
    // Kenya PAYE rates 2024
    $annual_gross = $gross_pay * 12;
    
    if ($annual_gross <= 288000) { // 24,000 per month
        return 0;
    } elseif ($annual_gross <= 388000) {
        return (($annual_gross - 288000) * 0.1) / 12;
    } elseif ($annual_gross <= 6000000) {
        return (10000 + ($annual_gross - 388000) * 0.25) / 12;
    } else {
        return (10000 + (6000000 - 388000) * 0.25 + ($annual_gross - 6000000) * 0.3) / 12;
    }
}

function calculateNHIF($gross_pay) {
    // Kenya NHIF rates 2024
    if ($gross_pay <= 5999) return 150;
    elseif ($gross_pay <= 7999) return 300;
    elseif ($gross_pay <= 11999) return 400;
    elseif ($gross_pay <= 14999) return 500;
    elseif ($gross_pay <= 19999) return 600;
    elseif ($gross_pay <= 24999) return 750;
    elseif ($gross_pay <= 29999) return 850;
    elseif ($gross_pay <= 34999) return 900;
    elseif ($gross_pay <= 39999) return 950;
    elseif ($gross_pay <= 44999) return 1000;
    elseif ($gross_pay <= 49999) return 1100;
    elseif ($gross_pay <= 59999) return 1200;
    elseif ($gross_pay <= 69999) return 1300;
    elseif ($gross_pay <= 79999) return 1400;
    elseif ($gross_pay <= 89999) return 1500;
    elseif ($gross_pay <= 99999) return 1600;
    else return 1700;
}

function calculateNSSF($gross_pay) {
    // Kenya NSSF rates 2024 (Tier I & II)
    $tier1_limit = 7000; // Lower earnings limit
    $tier2_limit = 36000; // Upper earnings limit
    
    $tier1 = min($gross_pay, $tier1_limit) * 0.06;
    $tier2 = max(0, min($gross_pay, $tier2_limit) - $tier1_limit) * 0.06;
    
    return ['tier1' => $tier1, 'tier2' => $tier2];
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-calculator mr-2"></i>
                Process Payroll - <?php echo htmlspecialchars($period['period_name']); ?>
            </h3>
            <a href="payroll_management.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Payroll
            </a>
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
            if (isset($_SESSION['alert_details'])) {
                echo '<div class="alert alert-info">';
                foreach ($_SESSION['alert_details'] as $error) {
                    echo '<div class="small">• ' . htmlspecialchars($error) . '</div>';
                }
                echo '</div>';
                unset($_SESSION['alert_details']);
            }
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Period Summary -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Period Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-primary mb-1"><?php echo $totals['employee_count'] ?? 0; ?></h4>
                            <small class="text-muted">Employees</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-success mb-1">KES <?php echo number_format($totals['total_gross'] ?? 0, 2); ?></h4>
                            <small class="text-muted">Gross Pay</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-danger mb-1">KES <?php echo number_format($totals['total_deductions'] ?? 0, 2); ?></h4>
                            <small class="text-muted">Total Deductions</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-info mb-1">KES <?php echo number_format($totals['total_net_pay'] ?? 0, 2); ?></h4>
                            <small class="text-muted">Net Pay</small>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="btn-toolbar justify-content-between">
                            <div class="btn-group">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="calculate_payroll">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-calculator mr-2"></i>Calculate Payroll
                                    </button>
                                </form>
                                
                                <?php if ($transactions_result->num_rows > 0): ?>
                                <form method="POST" class="d-inline ml-2">
                                    <input type="hidden" name="action" value="approve_payroll">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-check-circle mr-2"></i>Approve Payroll
                                    </button>
                                </form>
                                
                                <a href="payroll_payslips.php?period_id=<?php echo $period_id; ?>" class="btn btn-info ml-2">
                                    <i class="fas fa-file-invoice mr-2"></i>Generate Payslips
                                </a>
                                
                                <a href="payroll_payment.php?period_id=<?php echo $period_id; ?>" class="btn btn-warning ml-2">
                                    <i class="fas fa-money-bill-wave mr-2"></i>Process Payments
                                </a>
                                <?php endif; ?>
                            </div>
                            
                            <div class="btn-group">
                                <a href="payroll_report.php?period_id=<?php echo $period_id; ?>" class="btn btn-outline-primary" target="_blank">
                                    <i class="fas fa-file-pdf mr-2"></i>PDF Report
                                </a>
                                <a href="payroll_export.php?period_id=<?php echo $period_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-download mr-2"></i>Export Excel
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payroll Transactions -->
        <div class="card">
            <div class="card-header bg-success text-white py-2">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list mr-2"></i>
                    Employee Payroll Details
                    <span class="badge badge-light ml-2"><?php echo $transactions_result->num_rows; ?> employees</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Employee</th>
                                <th class="text-right">Basic Salary</th>
                                <th class="text-right">Allowances</th>
                                <th class="text-right">Gross Pay</th>
                                <th class="text-right">PAYE</th>
                                <th class="text-right">NHIF</th>
                                <th class="text-right">NSSF</th>
                                <th class="text-right">Net Pay</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions_result->num_rows > 0): ?>
                                <?php while ($transaction = $transactions_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($transaction['employee_number']); ?> | 
                                                <?php echo htmlspecialchars($transaction['department_name'] ?? 'No Department'); ?>
                                            </small>
                                        </td>
                                        <td class="text-right">KES <?php echo number_format($transaction['basic_salary'] ?? 0, 2); ?></td>
                                        <td class="text-right">KES <?php echo number_format($transaction['allowances'] ?? 0, 2); ?></td>
                                        <td class="text-right font-weight-bold text-success">KES <?php echo number_format($transaction['gross_pay'], 2); ?></td>
                                        <td class="text-right text-danger">KES <?php echo number_format($transaction['paye'], 2); ?></td>
                                        <td class="text-right text-danger">KES <?php echo number_format($transaction['nhif'], 2); ?></td>
                                        <td class="text-right text-danger">KES <?php echo number_format($transaction['nssf_tier1'] + $transaction['nssf_tier2'], 2); ?></td>
                                        <td class="text-right font-weight-bold text-primary">KES <?php echo number_format($transaction['net_pay'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                switch($transaction['status']) {
                                                    case 'draft': echo 'secondary'; break;
                                                    case 'calculated': echo 'info'; break;
                                                    case 'approved': echo 'success'; break;
                                                    case 'paid': echo 'primary'; break;
                                                    default: echo 'light';
                                                }
                                            ?>">
                                                <?php echo ucfirst($transaction['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="payroll_edit_transaction.php?id=<?php echo $transaction['transaction_id']; ?>" class="btn btn-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="payslip.php?transaction_id=<?php echo $transaction['transaction_id']; ?>" class="btn btn-info" title="Payslip" target="_blank">
                                                    <i class="fas fa-file-invoice"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                
                                <!-- Totals Row -->
                                <tr class="bg-light font-weight-bold">
                                    <td>TOTALS</td>
                                    <td class="text-right">KES <?php echo number_format($totals['total_gross'] - $totals['total_other_deductions'], 2); ?></td>
                                    <td class="text-right">KES <?php echo number_format($totals['total_other_deductions'], 2); ?></td>
                                    <td class="text-right text-success">KES <?php echo number_format($totals['total_gross'], 2); ?></td>
                                    <td class="text-right text-danger">KES <?php echo number_format($totals['total_paye'], 2); ?></td>
                                    <td class="text-right text-danger">KES <?php echo number_format($totals['total_nhif'], 2); ?></td>
                                    <td class="text-right text-danger">KES <?php echo number_format($totals['total_nssf'], 2); ?></td>
                                    <td class="text-right text-primary">KES <?php echo number_format($totals['total_net_pay'], 2); ?></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Payroll Data</h5>
                                        <p class="text-muted">Click "Calculate Payroll" to process employee payments for this period.</p>
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

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
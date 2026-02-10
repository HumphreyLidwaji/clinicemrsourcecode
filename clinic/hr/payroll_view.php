<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (!isset($_GET['period_id']) || empty($_GET['period_id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Payroll period ID is required!";
    header("Location: payroll_management.php");
    exit;
}

$period_id = intval($_GET['period_id']);

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

// Get payroll transactions for this period
$transactions_sql = "SELECT SQL_CALC_FOUND_ROWS pt.*, e.first_name, e.last_name, e.employee_number, e.bank_account, 
                            d.department_name, j.title as job_title
                     FROM payroll_transactions pt
                     JOIN employees e ON pt.employee_id = e.employee_id
                     LEFT JOIN departments d ON e.department_id = d.department_id
                     LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
                     WHERE pt.period_id = ?
                     ORDER BY e.first_name, e.last_name
                     LIMIT $record_from, $record_to";

$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $period_id);
$transactions_stmt->execute();
$transactions = $transactions_stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Calculate totals
$totals_sql = "SELECT 
                COUNT(*) as employee_count,
                SUM(basic_salary) as total_basic,
                SUM(total_allowances) as total_allowances,
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

// Get status counts
$status_sql = "SELECT status, COUNT(*) as count FROM payroll_transactions WHERE period_id = ? GROUP BY status";
$status_stmt = $mysqli->prepare($status_sql);
$status_stmt->bind_param("i", $period_id);
$status_stmt->execute();
$status_counts = $status_stmt->get_result();

$calculated_count = 0;
$approved_count = 0;
$paid_count = 0;

while ($status = $status_counts->fetch_assoc()) {
    switch($status['status']) {
        case 'calculated':
            $calculated_count = $status['count'];
            break;
        case 'approved':
            $approved_count = $status['count'];
            break;
        case 'paid':
            $paid_count = $status['count'];
            break;
    }
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-eye mr-2"></i>Payroll Details</h3>
        <div class="card-tools">
            <a href="payroll_management.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Payroll
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3 bg-light">
        <div class="row">
            <div class="col-md-8">
                <div class="btn-group mr-2">
                    <span class="btn btn-light border">
                        <i class="fas fa-calendar-alt text-primary mr-1"></i>
                        Period: <strong><?php echo htmlspecialchars($period['period_name']); ?></strong>
                    </span>
                    <span class="btn btn-light border">
                        <i class="fas fa-users text-primary mr-1"></i>
                        Employees: <strong><?php echo $totals['employee_count'] ?? 0; ?></strong>
                    </span>
                    <span class="btn btn-light border">
                        <i class="fas fa-money-bill-wave text-info mr-1"></i>
                        Gross Pay: <strong>KES <?php echo number_format($totals['total_gross'] ?? 0, 2); ?></strong>
                    </span>
                    <span class="btn btn-light border">
                        <i class="fas fa-hand-holding-usd text-success mr-1"></i>
                        Net Pay: <strong>KES <?php echo number_format($totals['total_net_pay'] ?? 0, 2); ?></strong>
                    </span>
                    <span class="btn btn-light border">
                        <i class="fas fa-receipt text-warning mr-1"></i>
                        Deductions: <strong>KES <?php echo number_format($totals['total_deductions'] ?? 0, 2); ?></strong>
                    </span>
                </div>
            </div>
            <div class="col-md-4 text-right">
                <div class="btn-group">
                    <a href="payroll_report.php?period_id=<?php echo $period_id; ?>" class="btn btn-warning" target="_blank">
                        <i class="fas fa-file-pdf mr-2"></i>PDF Report
                    </a>
                    <a href="payroll_export.php?period_id=<?php echo $period_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-file-excel mr-2"></i>Export Excel
                    </a>
                    <?php if ($period['status'] == 'processing'): ?>
                    <form method="POST" action="post/payroll.php" class="d-inline">
                        <input type="hidden" name="period_id" value="<?php echo $period_id; ?>">
                        <input type="hidden" name="action" value="approve_payroll">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" class="btn btn-success confirm-link" data-confirm-message="Approve this payroll? This will lock the payroll for payment.">
                            <i class="fas fa-check-circle mr-2"></i>Approve
                        </button>
                    </form>
                    <?php elseif ($period['status'] == 'closed'): ?>
                    <form method="POST" action="post/payroll.php" class="d-inline">
                        <input type="hidden" name="period_id" value="<?php echo $period_id; ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <button type="submit" class="btn btn-info confirm-link" data-confirm-message="Mark this payroll as paid?">
                            <i class="fas fa-money-check mr-2"></i>Mark Paid
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
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

    <!-- Period Information -->
    <div class="card m-3">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Period Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Period Name:</strong><br>
                    <span class="font-weight-bold text-primary"><?php echo htmlspecialchars($period['period_name']); ?></span>
                </div>
                <div class="col-md-2">
                    <strong>Start Date:</strong><br>
                    <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($period['start_date'])); ?></span>
                </div>
                <div class="col-md-2">
                    <strong>End Date:</strong><br>
                    <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($period['end_date'])); ?></span>
                </div>
                <div class="col-md-2">
                    <strong>Pay Date:</strong><br>
                    <span class="font-weight-bold text-success"><?php echo date('M j, Y', strtotime($period['pay_date'])); ?></span>
                </div>
                <div class="col-md-3">
                    <strong>Status:</strong><br>
                    <span class="badge badge-<?php 
                        switch($period['status']) {
                            case 'open': echo 'primary'; break;
                            case 'processing': echo 'warning'; break;
                            case 'closed': echo 'success'; break;
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

    <!-- Transaction Status Summary -->
    <div class="row m-3">
        <div class="col-md-3">
            <div class="info-box bg-info">
                <span class="info-box-icon"><i class="fas fa-calculator"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Calculated</span>
                    <span class="info-box-number"><?php echo $calculated_count; ?></span>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo $totals['employee_count'] ? ($calculated_count / $totals['employee_count'] * 100) : 0; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-warning">
                <span class="info-box-icon"><i class="fas fa-check-circle"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Approved</span>
                    <span class="info-box-number"><?php echo $approved_count; ?></span>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo $totals['employee_count'] ? ($approved_count / $totals['employee_count'] * 100) : 0; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-success">
                <span class="info-box-icon"><i class="fas fa-money-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Paid</span>
                    <span class="info-box-number"><?php echo $paid_count; ?></span>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo $totals['employee_count'] ? ($paid_count / $totals['employee_count'] * 100) : 0; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-primary">
                <span class="info-box-icon"><i class="fas fa-clock"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Pending</span>
                    <span class="info-box-number"><?php echo ($totals['employee_count'] ?? 0) - ($calculated_count + $approved_count + $paid_count); ?></span>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo $totals['employee_count'] ? (($totals['employee_count'] - ($calculated_count + $approved_count + $paid_count)) / $totals['employee_count'] * 100) : 0; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payroll Transactions -->
    <div class="card m-3">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-list mr-2"></i>Payroll Transactions</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th class="text-right">Basic Salary</th>
                            <th class="text-right">Allowances</th>
                            <th class="text-right">Gross Pay</th>
                            <th class="text-right">PAYE</th>
                            <th class="text-right">NHIF</th>
                            <th class="text-right">NSSF</th>
                            <th class="text-right">Other Ded.</th>
                            <th class="text-right">Total Ded.</th>
                            <th class="text-right">Net Pay</th>
                            <th>Bank Account</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        while($transaction = $transactions->fetch_assoc()): 
                            $nssf_total = $transaction['nssf_tier1'] + $transaction['nssf_tier2'];
                        ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($transaction['employee_number']); ?></small>
                            </td>
                            <td>
                                <span class="text-muted"><?php echo htmlspecialchars($transaction['department_name'] ?? 'N/A'); ?></span>
                            </td>
                            <td class="text-right">KES <?php echo number_format($transaction['basic_salary'], 2); ?></td>
                            <td class="text-right">KES <?php echo number_format($transaction['total_allowances'], 2); ?></td>
                            <td class="text-right">
                                <strong>KES <?php echo number_format($transaction['gross_pay'], 2); ?></strong>
                            </td>
                            <td class="text-right text-danger">KES <?php echo number_format($transaction['paye'], 2); ?></td>
                            <td class="text-right text-danger">KES <?php echo number_format($transaction['nhif'], 2); ?></td>
                            <td class="text-right text-danger">KES <?php echo number_format($nssf_total, 2); ?></td>
                            <td class="text-right text-danger">KES <?php echo number_format($transaction['other_deductions'], 2); ?></td>
                            <td class="text-right text-danger font-weight-bold">KES <?php echo number_format($transaction['total_deductions'], 2); ?></td>
                            <td class="text-right">
                                <strong class="text-success">KES <?php echo number_format($transaction['net_pay'], 2); ?></strong>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo htmlspecialchars($transaction['bank_account'] ?? 'N/A'); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-<?php 
                                    switch($transaction['status']) {
                                        case 'draft': echo 'secondary'; break;
                                        case 'calculated': echo 'info'; break;
                                        case 'approved': echo 'warning'; break;
                                        case 'paid': echo 'success'; break;
                                        default: echo 'light';
                                    }
                                ?>">
                                    <?php echo ucfirst($transaction['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot class="bg-dark text-white">
                        <tr>
                            <td colspan="2"><strong>TOTALS</strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_basic'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_allowances'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_gross'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_paye'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_nhif'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_nssf'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_other_deductions'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_deductions'] ?? 0, 2); ?></strong></td>
                            <td class="text-right"><strong>KES <?php echo number_format($totals['total_net_pay'] ?? 0, 2); ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <?php if ($num_rows[0] == 0): ?>
            <div class="text-center py-4">
                <i class="fas fa-calculator fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No payroll transactions found</h5>
                <p class="text-muted">Process payroll to generate transactions for this period.</p>
                <a href="payroll_process.php?period_id=<?php echo $period_id; ?>" class="btn btn-primary">
                    <i class="fas fa-calculator mr-2"></i>Process Payroll
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
</div>

<script>
$(document).ready(function() {
    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var form = $(this).closest('form');
        
        if (confirm(message)) {
            form.submit();
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + P for PDF report
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            window.open('payroll_report.php?period_id=<?php echo $period_id; ?>', '_blank');
        }
        // Ctrl + E for Excel export
        if (e.ctrlKey && e.keyCode === 69) {
            e.preventDefault();
            window.location.href = 'payroll_export.php?period_id=<?php echo $period_id; ?>';
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
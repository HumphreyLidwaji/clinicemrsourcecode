<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if transaction ID is provided
if (!isset($_GET['transaction_id']) || empty($_GET['transaction_id'])) {
    die('No transaction ID provided');
}

$transaction_id = intval($_GET['transaction_id']);

// Get transaction details with employee and period info
$sql = "SELECT 
            pt.*,
            e.*,
            d.department_name,
            j.title as position,
            pp.period_name, pp.start_date, pp.end_date, pp.pay_date
        FROM payroll_transactions pt
        JOIN employees e ON pt.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
        JOIN payroll_periods pp ON pt.period_id = pp.period_id
        WHERE pt.transaction_id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('Payslip not found');
}

$payslip = $result->fetch_assoc();

// Set PDF headers for download
if (isset($_GET['download'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="payslip_' . $payslip['employee_number'] . '_' . $payslip['period_name'] . '.pdf"');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?php echo htmlspecialchars($payslip['first_name'] . ' ' . $payslip['last_name']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
        .payslip-container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border: 2px solid #007bff; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px; }
        .company-name { font-size: 24px; font-weight: bold; color: #007bff; }
        .payslip-title { font-size: 20px; margin: 10px 0; }
        .employee-info, .payment-info { margin-bottom: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td { padding: 8px; border: 1px solid #dee2e6; }
        .info-table .label { font-weight: bold; background: #f8f9fa; width: 30%; }
        .earnings-table, .deductions-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .earnings-table th, .deductions-table th { background: #007bff; color: white; padding: 10px; text-align: left; }
        .earnings-table td, .deductions-table td { padding: 10px; border: 1px solid #dee2e6; }
        .total-row { font-weight: bold; background: #f8f9fa; }
        .summary { background: #e9ecef; padding: 15px; border-radius: 5px; margin-top: 20px; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #6c757d; border-top: 1px solid #dee2e6; padding-top: 20px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
        @media print {
            body { background: white; margin: 0; }
            .payslip-container { border: none; box-shadow: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="payslip-container">
        <!-- Header -->
        <div class="header">
            <div class="company-name">YOUR COMPANY NAME</div>
            <div class="payslip-title">PAYSLIP</div>
            <div>Period: <?php echo htmlspecialchars($payslip['period_name']); ?></div>
        </div>

        <!-- Employee Information -->
        <table class="info-table">
            <tr>
                <td class="label">Employee Name</td>
                <td><?php echo htmlspecialchars($payslip['first_name'] . ' ' . $payslip['last_name']); ?></td>
                <td class="label">Employee Number</td>
                <td><?php echo htmlspecialchars($payslip['employee_number']); ?></td>
            </tr>
            <tr>
                <td class="label">Department</td>
                <td><?php echo htmlspecialchars($payslip['department_name'] ?? 'N/A'); ?></td>
                <td class="label">Position</td>
                <td><?php echo htmlspecialchars($payslip['position'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td class="label">Pay Period</td>
                <td><?php echo date('M j, Y', strtotime($payslip['start_date'])) . ' to ' . date('M j, Y', strtotime($payslip['end_date'])); ?></td>
                <td class="label">Pay Date</td>
                <td><?php echo date('M j, Y', strtotime($payslip['pay_date'])); ?></td>
            </tr>
        </table>

        <!-- Earnings -->
        <table class="earnings-table">
            <thead>
                <tr>
                    <th colspan="2">EARNINGS</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Basic Salary</td>
                    <td class="text-right">KES <?php echo number_format($payslip['basic_salary'], 2); ?></td>
                </tr>
                <tr>
                    <td>Allowances</td>
                    <td class="text-right">KES <?php echo number_format($payslip['allowances'] ?? 0, 2); ?></td>
                </tr>
                <tr>
                    <td>Overtime</td>
                    <td class="text-right">KES <?php echo number_format($payslip['overtime'] ?? 0, 2); ?></td>
                </tr>
                <tr>
                    <td>Bonuses</td>
                    <td class="text-right">KES <?php echo number_format($payslip['bonuses'] ?? 0, 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td><strong>GROSS PAY</strong></td>
                    <td class="text-right text-success"><strong>KES <?php echo number_format($payslip['gross_pay'], 2); ?></strong></td>
                </tr>
            </tbody>
        </table>

        <!-- Deductions -->
        <table class="deductions-table">
            <thead>
                <tr>
                    <th colspan="2">DEDUCTIONS</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>PAYE Tax</td>
                    <td class="text-right text-danger">KES <?php echo number_format($payslip['paye'], 2); ?></td>
                </tr>
                <tr>
                    <td>NHIF</td>
                    <td class="text-right text-danger">KES <?php echo number_format($payslip['nhif'], 2); ?></td>
                </tr>
                <tr>
                    <td>NSSF Tier I</td>
                    <td class="text-right text-danger">KES <?php echo number_format($payslip['nssf_tier1'], 2); ?></td>
                </tr>
                <tr>
                    <td>NSSF Tier II</td>
                    <td class="text-right text-danger">KES <?php echo number_format($payslip['nssf_tier2'], 2); ?></td>
                </tr>
                <tr>
                    <td>Other Deductions</td>
                    <td class="text-right text-danger">KES <?php echo number_format($payslip['other_deductions'], 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td><strong>TOTAL DEDUCTIONS</strong></td>
                    <td class="text-right text-danger"><strong>KES <?php echo number_format($payslip['total_deductions'], 2); ?></strong></td>
                </tr>
            </tbody>
        </table>

        <!-- Net Pay Summary -->
        <div class="summary">
            <table style="width: 100%;">
                <tr>
                    <td><strong>GROSS PAY</strong></td>
                    <td class="text-right text-success"><strong>KES <?php echo number_format($payslip['gross_pay'], 2); ?></strong></td>
                </tr>
                <tr>
                    <td><strong>TOTAL DEDUCTIONS</strong></td>
                    <td class="text-right text-danger"><strong>KES <?php echo number_format($payslip['total_deductions'], 2); ?></strong></td>
                </tr>
                <tr style="border-top: 2px solid #007bff;">
                    <td><strong>NET PAY</strong></td>
                    <td class="text-right" style="font-size: 18px; color: #007bff;"><strong>KES <?php echo number_format($payslip['net_pay'], 2); ?></strong></td>
                </tr>
            </table>
        </div>

        <!-- Payment Information -->
        <?php if ($payslip['payment_method'] && $payslip['payment_date']): ?>
        <div class="payment-info">
            <table class="info-table">
                <tr>
                    <td class="label">Payment Method</td>
                    <td><?php echo ucfirst($payslip['payment_method']); ?></td>
                    <td class="label">Payment Date</td>
                    <td><?php echo date('M j, Y', strtotime($payslip['payment_date'])); ?></td>
                </tr>
                <?php if ($payslip['payment_reference']): ?>
                <tr>
                    <td class="label">Payment Reference</td>
                    <td colspan="3"><?php echo htmlspecialchars($payslip['payment_reference']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <p>This is a computer-generated payslip and does not require a signature.</p>
            <p>Generated on: <?php echo date('F j, Y g:i A'); ?></p>
        </div>

        <!-- Print/Download Buttons -->
        <div class="no-print text-center mt-4">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print mr-2"></i>Print Payslip
            </button>
            <a href="?transaction_id=<?php echo $transaction_id; ?>&download=1" class="btn btn-success">
                <i class="fas fa-download mr-2"></i>Download PDF
            </a>
            <a href="payroll_process.php?period_id=<?php echo $payslip['period_id']; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back to Payroll
            </a>
        </div>
    </div>

    <script>
        // Auto-print if requested
        <?php if (isset($_GET['print'])): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
    </script>
</body>
</html>
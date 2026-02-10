<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get invoice ID from URL
$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    flash_alert("No invoice specified", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Get invoice details
$invoice_sql = "SELECT i.*, p.patient_id, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                       p.patient_phone, p.patient_email, p.patient_mrn, 
                       i.invoice_amount - i.paid_amount as remaining_balance,
                       (SELECT COUNT(*) FROM payments WHERE payment_invoice_id = i.invoice_id) as payment_count
                FROM invoices i
                LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                WHERE i.invoice_id = ?";
$invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
mysqli_stmt_execute($invoice_stmt);
$invoice_result = mysqli_stmt_get_result($invoice_stmt);
$invoice = mysqli_fetch_assoc($invoice_result);
mysqli_stmt_close($invoice_stmt);

if (!$invoice) {
    flash_alert("Invoice not found", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Get latest payment for this invoice
$payment_sql = "SELECT p.*, u.user_name as received_by, cr.register_name
                FROM payments p
                LEFT JOIN users u ON p.payment_received_by = u.user_id
                LEFT JOIN cash_register cr ON p.register_id = cr.register_id
                WHERE p.payment_invoice_id = ? 
                ORDER BY p.payment_date DESC, p.payment_id DESC 
                LIMIT 1";
$payment_stmt = mysqli_prepare($mysqli, $payment_sql);
mysqli_stmt_bind_param($payment_stmt, 'i', $invoice_id);
mysqli_stmt_execute($payment_stmt);
$payment_result = mysqli_stmt_get_result($payment_stmt);
$payment = mysqli_fetch_assoc($payment_result);
mysqli_stmt_close($payment_stmt);

// Get all payments for this invoice
$all_payments_sql = "SELECT p.*, u.user_name as received_by
                     FROM payments p
                     LEFT JOIN users u ON p.payment_received_by = u.user_id
                     WHERE p.payment_invoice_id = ? 
                     ORDER BY p.payment_date DESC";
$all_payments_stmt = mysqli_prepare($mysqli, $all_payments_sql);
mysqli_stmt_bind_param($all_payments_stmt, 'i', $invoice_id);
mysqli_stmt_execute($all_payments_stmt);
$all_payments_result = mysqli_stmt_get_result($all_payments_stmt);
$all_payments = [];
while ($pay = mysqli_fetch_assoc($all_payments_result)) {
    $all_payments[] = $pay;
}
mysqli_stmt_close($all_payments_stmt);

// Get invoice items with payment status
$items_sql = "SELECT * FROM invoice_items WHERE item_invoice_id = ? ORDER BY item_id";
$items_stmt = mysqli_prepare($mysqli, $items_sql);
mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$invoice_items = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    $invoice_items[] = $item;
}
mysqli_stmt_close($items_stmt);

// Get item payment allocations for the latest payment
$item_allocations = [];
if ($payment) {
    $item_alloc_sql = "SELECT iip.*, ii.item_name, ii.item_description, ii.item_total
                       FROM invoice_item_payments iip
                       JOIN invoice_items ii ON iip.item_id = ii.item_id
                       WHERE iip.payment_id = ?
                       ORDER BY iip.item_payment_id";
    $item_alloc_stmt = mysqli_prepare($mysqli, $item_alloc_sql);
    mysqli_stmt_bind_param($item_alloc_stmt, 'i', $payment['payment_id']);
    mysqli_stmt_execute($item_alloc_stmt);
    $item_alloc_result = mysqli_stmt_get_result($item_alloc_stmt);
    while ($alloc = mysqli_fetch_assoc($item_alloc_result)) {
        $item_allocations[] = $alloc;
    }
    mysqli_stmt_close($item_alloc_stmt);
}

// Get company details
$company_sql = "SELECT * FROM companies WHERE company_id = 1";
$company_result = mysqli_query($mysqli, $company_sql);
$company = mysqli_fetch_assoc($company_result);

// If no payment found but we have an invoice, show invoice details
if (!$payment && $invoice) {
    $payment = [
        'payment_number' => 'N/A',
        'payment_date' => $invoice['invoice_date'],
        'payment_amount' => 0,
        'payment_method' => 'No Payment',
        'payment_status' => 'pending',
        'received_by' => 'System',
        'payment_notes' => 'Invoice created but no payment processed yet',
        'allocation_method' => 'full'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo htmlspecialchars($company['company_name'] ?? 'Healthcare Facility'); ?></title>
    <!-- Bootstrap CSS -->
    <link href="../vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .container {
                max-width: 100% !important;
                padding: 0 !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            body {
                background: white !important;
                font-size: 12pt;
            }
            .receipt-container {
                max-width: 100% !important;
            }
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .receipt-header {
            border-bottom: 3px double #333;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .receipt-footer {
            border-top: 3px double #333;
            padding-top: 20px;
            margin-top: 30px;
        }
        .amount-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
        }
        .watermark {
            position: absolute;
            opacity: 0.1;
            font-size: 120px;
            transform: rotate(-45deg);
            z-index: -1;
            top: 30%;
            left: 10%;
            color: #007bff;
            font-weight: bold;
        }
        .payment-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #28a745;
        }
        .allocation-badge {
            font-size: 0.8rem;
            margin-left: 5px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-4">
        <!-- Action Buttons -->
        <div class="row mb-4 no-print">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <a href="process_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-success">
                            <i class="fas fa-credit-card mr-2"></i>Process Another Payment
                        </a>
                        <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-primary ml-2">
                            <i class="fas fa-edit mr-2"></i>Edit Invoice
                        </a>
                    </div>
                    <div>
                        <button onclick="window.print()" class="btn btn-outline-primary">
                            <i class="fas fa-print mr-2"></i>Print Receipt
                        </button>
                        <a href="billing_dashboard.php" class="btn btn-outline-secondary ml-2">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Receipt Content -->
        <div class="receipt-container">
            <div class="card shadow-lg">
                <div class="card-body p-5">
                    <!-- Watermark -->
                    <div class="watermark no-print">
                        <?php echo htmlspecialchars($company['company_name'] ?? 'RECEIPT'); ?>
                    </div>

                    <!-- Receipt Header -->
                    <div class="receipt-header text-center">
                        <div class="row">
                            <div class="col-md-8 text-left">
                                <h1 class="display-4 font-weight-bold text-primary mb-1">
                                    <?php echo htmlspecialchars($company['company_name'] ?? 'Healthcare Facility'); ?>
                                </h1>
                                <?php if ($company['company_address']): ?>
                                    <p class="lead mb-1"><?php echo htmlspecialchars($company['company_address']); ?></p>
                                <?php endif; ?>
                                <?php if ($company['company_phone']): ?>
                                    <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($company['company_phone']); ?></p>
                                <?php endif; ?>
                                <?php if ($company['company_email']): ?>
                                    <p class="mb-0"><strong>Email:</strong> <?php echo htmlspecialchars($company['company_email']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="border p-3 bg-light rounded">
                                    <h4 class="font-weight-bold text-uppercase mb-0 text-success">PAYMENT RECEIPT</h4>
                                    <p class="mb-0 text-muted"><?php echo htmlspecialchars($payment['payment_number'] ?? 'N/A'); ?></p>
                                    <?php if ($payment['allocation_method']): ?>
                                    <span class="badge badge-info allocation-badge">
                                        <?php echo strtoupper(str_replace('_', ' ', $payment['allocation_method'])); ?> ALLOCATION
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Details -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="payment-details">
                                <h5 class="font-weight-bold text-success mb-3">
                                    <i class="fas fa-money-bill-wave mr-2"></i>Payment Information
                                </h5>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Receipt Number:</td>
                                        <td><?php echo htmlspecialchars($payment['payment_number'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Payment Date:</td>
                                        <td><?php echo date('F j, Y g:i A', strtotime($payment['payment_date'] ?? $invoice['invoice_date'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Payment Method:</td>
                                        <td>
                                            <span class="badge badge-primary text-uppercase">
                                                <?php echo htmlspecialchars($payment['payment_method'] ?? 'No Payment'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Payment Status:</td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo ($payment['payment_status'] ?? 'pending') === 'completed' ? 'success' : 
                                                     (($payment['payment_status'] ?? 'pending') === 'pending' ? 'warning' : 'danger'); 
                                            ?> text-uppercase">
                                                <?php echo htmlspecialchars($payment['payment_status'] ?? 'pending'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php if ($payment['allocation_method']): ?>
                                    <tr>
                                        <td class="font-weight-bold">Allocation Method:</td>
                                        <td>
                                            <span class="badge badge-secondary">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment['allocation_method'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($payment['register_name'] ?? false): ?>
                                    <tr>
                                        <td class="font-weight-bold">Cash Register:</td>
                                        <td><?php echo htmlspecialchars($payment['register_name']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td class="font-weight-bold">Received By:</td>
                                        <td><?php echo htmlspecialchars($payment['received_by'] ?? 'System'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="amount-box text-center">
                                <h4 class="text-muted mb-1">Amount Paid</h4>
                                <h1 class="display-4 font-weight-bold text-success">
                                    KSH <?php echo number_format($payment['payment_amount'] ?? 0, 2); ?>
                                </h1>
                                <p class="text-muted mb-0">
                                    <?php echo strtoupper($payment['payment_method'] ?? 'NO PAYMENT'); ?> PAYMENT
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Item Payment Allocation Details -->
                    <?php if (!empty($item_allocations)): ?>
                    <div class="mb-4">
                        <h5 class="font-weight-bold text-primary mb-3">
                            <i class="fas fa-list-check mr-2"></i>Payment Allocation Details
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-right">Item Total</th>
                                        <th class="text-right">Previously Paid</th>
                                        <th class="text-right">This Payment</th>
                                        <th class="text-right">New Balance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($item_allocations as $alloc): 
                                        $item = null;
                                        foreach ($invoice_items as $inv_item) {
                                            if ($inv_item['item_id'] == $alloc['item_id']) {
                                                $item = $inv_item;
                                                break;
                                            }
                                        }
                                        $new_balance = $item ? ($item['item_total'] - $item['item_amount_paid']) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($alloc['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($alloc['item_description']); ?></small>
                                        </td>
                                        <td class="text-right">KSH <?php echo number_format($alloc['item_total'], 2); ?></td>
                                        <td class="text-right text-info">KSH <?php echo number_format($item ? ($item['item_amount_paid'] - $alloc['amount_paid']) : 0, 2); ?></td>
                                        <td class="text-right font-weight-bold text-success">
                                            KSH <?php echo number_format($alloc['amount_paid'], 2); ?>
                                        </td>
                                        <td class="text-right text-danger">KSH <?php echo number_format($new_balance, 2); ?></td>
                                        <td>
                                            <?php 
                                            $item_status = $item ? $item['item_payment_status'] : 'unknown';
                                            $status_class = $item_status === 'paid' ? 'success' : 
                                                          ($item_status === 'partial' ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                <?php echo ucfirst($item_status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Patient and Invoice Information -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h5 class="font-weight-bold text-primary mb-3">
                                <i class="fas fa-user mr-2"></i>Patient Information
                            </h5>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="font-weight-bold" width="40%">Patient Name:</td>
                                    <td><?php echo htmlspecialchars($invoice['patient_name']); ?></td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">MRN:</td>
                                    <td><?php echo htmlspecialchars($invoice['patient_mrn']); ?></td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Phone:</td>
                                    <td><?php echo htmlspecialchars($invoice['patient_phone']); ?></td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Email:</td>
                                    <td><?php echo htmlspecialchars($invoice['patient_email']); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5 class="font-weight-bold text-primary mb-3">
                                <i class="fas fa-file-invoice mr-2"></i>Invoice Information
                            </h5>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="font-weight-bold" width="40%">Invoice Number:</td>
                                    <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Invoice Date:</td>
                                    <td><?php echo date('F j, Y', strtotime($invoice['invoice_date'])); ?></td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Due Date:</td>
                                    <td><?php echo date('F j, Y', strtotime($invoice['invoice_due'])); ?></td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Invoice Status:</td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $invoice['invoice_status'] === 'paid' ? 'success' : 
                                                 ($invoice['invoice_status'] === 'partially_paid' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Total Amount:</td>
                                    <td class="font-weight-bold text-dark">KSH <?php echo number_format($invoice['invoice_amount'], 2); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Invoice Items with Payment Status -->
                    <?php if (!empty($invoice_items)): ?>
                    <div class="mb-4">
                        <h5 class="font-weight-bold text-primary mb-3">
                            <i class="fas fa-list mr-2"></i>Invoice Items & Payment Status
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="bg-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Item Description</th>
                                        <th class="text-right">Quantity</th>
                                        <th class="text-right">Unit Price</th>
                                        <th class="text-right">Total Amount</th>
                                        <th class="text-right">Paid Amount</th>
                                        <th class="text-right">Due Amount</th>
                                        <th>Payment Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $subtotal = 0;
                                    $total_paid = 0;
                                    $total_due = 0;
                                    foreach ($invoice_items as $index => $item): 
                                        $subtotal += $item['item_total'];
                                        $total_paid += $item['item_amount_paid'];
                                        $item_due = $item['item_total'] - $item['item_amount_paid'];
                                        $total_due += $item_due;
                                    ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <?php if ($item['item_description']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['item_description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right"><?php echo $item['item_quantity']; ?></td>
                                        <td class="text-right">KSH <?php echo number_format($item['item_price'], 2); ?></td>
                                        <td class="text-right font-weight-bold">KSH <?php echo number_format($item['item_total'], 2); ?></td>
                                        <td class="text-right text-success">KSH <?php echo number_format($item['item_amount_paid'], 2); ?></td>
                                        <td class="text-right text-danger">KSH <?php echo number_format($item_due, 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $item['item_payment_status'] === 'paid' ? 'success' : 
                                                     ($item['item_payment_status'] === 'partial' ? 'warning' : 'danger'); 
                                            ?>">
                                                <?php echo ucfirst($item['item_payment_status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="4" class="text-right font-weight-bold">Subtotal:</td>
                                        <td class="text-right font-weight-bold">KSH <?php echo number_format($subtotal, 2); ?></td>
                                        <td class="text-right font-weight-bold text-success">KSH <?php echo number_format($total_paid, 2); ?></td>
                                        <td class="text-right font-weight-bold text-danger">KSH <?php echo number_format($total_due, 2); ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Payment Summary -->
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="bg-light p-3 rounded">
                                <h5 class="font-weight-bold text-primary mb-3">
                                    <i class="fas fa-chart-bar mr-2"></i>Payment Summary
                                </h5>
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-muted">Total Invoice</div>
                                            <div class="h4 font-weight-bold text-dark">KSH <?php echo number_format($invoice['invoice_amount'], 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-muted">Total Paid</div>
                                            <div class="h4 font-weight-bold text-success">KSH <?php echo number_format($invoice['paid_amount'], 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-muted">Balance Due</div>
                                            <div class="h4 font-weight-bold text-danger">KSH <?php echo number_format($invoice['remaining_balance'], 2); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-warning p-3 rounded text-center">
                                <h5 class="font-weight-bold mb-2">Payment Progress</h5>
                                <?php 
                                $progress = $invoice['invoice_amount'] > 0 ? ($invoice['paid_amount'] / $invoice['invoice_amount']) * 100 : 0;
                                $progress = min($progress, 100);
                                ?>
                                <div class="progress mb-2" style="height: 20px;">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?php echo $progress; ?>%" 
                                         aria-valuenow="<?php echo $progress; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        <?php echo number_format($progress, 1); ?>%
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php echo number_format($progress, 1); ?>% Paid
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <?php if (!empty($all_payments)): ?>
                    <div class="mb-4">
                        <h5 class="font-weight-bold text-primary mb-3">
                            <i class="fas fa-history mr-2"></i>Payment History
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Payment #</th>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Allocation</th>
                                        <th class="text-right">Amount</th>
                                        <th>Status</th>
                                        <th>Received By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_payments as $pay): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pay['payment_number']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($pay['payment_date'])); ?></td>
                                        <td>
                                            <span class="badge badge-secondary"><?php echo ucfirst($pay['payment_method']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-light"><?php echo ucfirst(str_replace('_', ' ', $pay['allocation_method'] ?? 'full')); ?></span>
                                        </td>
                                        <td class="text-right font-weight-bold text-success">
                                            KSH <?php echo number_format($pay['payment_amount'], 2); ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $pay['payment_status'] === 'completed' ? 'success' : 
                                                     ($pay['payment_status'] === 'pending' ? 'warning' : 'danger'); 
                                            ?>">
                                                <?php echo ucfirst($pay['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($pay['received_by']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Notes -->
                    <?php if ($payment['payment_notes'] ?? false): ?>
                    <div class="mb-4">
                        <h5 class="font-weight-bold text-primary mb-3">
                            <i class="fas fa-sticky-note mr-2"></i>Payment Notes
                        </h5>
                        <div class="bg-light p-3 rounded">
                            <?php echo nl2br(htmlspecialchars($payment['payment_notes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Receipt Footer -->
                    <div class="receipt-footer">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="font-weight-bold mb-2">Thank You For Your Payment</h6>
                                <p class="text-muted small mb-0">
                                    This receipt serves as confirmation of your payment. Please keep it for your records.
                                </p>
                            </div>
                            <div class="col-md-6 text-right">
                                <div class="border-top pt-2">
                                    <p class="mb-1">
                                        <strong>Generated On:</strong> <?php echo date('F j, Y g:i A'); ?>
                                    </p>
                                    <p class="mb-0">
                                        <strong>Receipt ID:</strong> <?php echo htmlspecialchars($payment['payment_number'] ?? 'N/A'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Print Instructions -->
                    <div class="text-center mt-4 no-print">
                        <p class="text-muted small">
                            <i class="fas fa-info-circle mr-1"></i>
                            Click the print button above to print this receipt for your records.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="../vendor/jquery/jquery.min.js"></script>
    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <script>
    // Auto-print option
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('print') === 'true') {
        window.print();
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + P to print
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
        // Escape to go back
        if (e.key === 'Escape') {
            window.history.back();
        }
    });
    </script>
</body>
</html>
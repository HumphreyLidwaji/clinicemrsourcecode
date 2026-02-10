<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current page name for redirects
$current_page = basename($_SERVER['PHP_SELF']);

// ========================
// INVOICE PAYMENT HISTORY
// ========================

$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    flash_alert("No invoice specified", 'error');
    header("Location: billing_invoices.php");
    exit;
}

// Get invoice details - UPDATED to match your table structure
$invoice_sql = "SELECT i.*, p.patient_id, 
                       CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                       p.patient_phone, p.patient_email, p.patient_mrn,
                       i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance,
                       IFNULL(i.paid_amount, 0) as paid_amount,
                       v.visit_status, v.visit_discharge_date,
                       (SELECT COUNT(*) FROM payments WHERE payment_invoice_id = i.invoice_id AND payment_status = 'completed') as payment_count
                FROM invoices i
                LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                LEFT JOIN visits v ON i.visit_id = v.visit_id
                WHERE i.invoice_id = ?";
$invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
mysqli_stmt_execute($invoice_stmt);
$invoice_result = mysqli_stmt_get_result($invoice_stmt);
$invoice = mysqli_fetch_assoc($invoice_result);
mysqli_stmt_close($invoice_stmt);

if (!$invoice) {
    flash_alert("Invoice not found", 'error');
    header("Location: billing_invoices.php");
    exit;
}

// Check if invoice is fully paid
$fully_paid = ($invoice['paid_amount'] >= $invoice['invoice_amount']);

// Get payment history
$payments_sql = "SELECT p.*, 
                        u.user_name as received_by,
                      
                        r.register_name,
                        j.entry_number as journal_entry,
                        pid.insurance_provider, pid.claim_number, pid.approval_code,
                        pmd.phone_number, pmd.reference_number as mpesa_reference,
                        pcd.card_type, pcd.card_last_four, pcd.auth_code
                 FROM payments p
                 LEFT JOIN users u ON p.payment_received_by = u.user_id
                 LEFT JOIN cash_register r ON p.register_id = r.register_id
                 LEFT JOIN journal_entries j ON p.journal_entry_id = j.entry_id
                 LEFT JOIN payment_insurance_details pid ON p.payment_id = pid.payment_id
                 LEFT JOIN payment_mpesa_details pmd ON p.payment_id = pmd.payment_id
                 LEFT JOIN payment_card_details pcd ON p.payment_id = pcd.payment_id
                 WHERE p.payment_invoice_id = ? 
                 ORDER BY p.payment_date DESC";
$payments_stmt = mysqli_prepare($mysqli, $payments_sql);
mysqli_stmt_bind_param($payments_stmt, 'i', $invoice_id);
mysqli_stmt_execute($payments_stmt);
$payments_result = mysqli_stmt_get_result($payments_stmt);
$payments = [];
$total_paid = 0;
$payment_methods = [];
$payment_dates = [];

while ($payment = mysqli_fetch_assoc($payments_result)) {
    $payments[] = $payment;
    $total_paid += $payment['payment_amount'];
    
    // Track payment methods for stats
    $method = $payment['payment_method'];
    if (!isset($payment_methods[$method])) {
        $payment_methods[$method] = 0;
    }
    $payment_methods[$method] += $payment['payment_amount'];
    
    // Track payment dates
    $date = date('Y-m-d', strtotime($payment['payment_date']));
    if (!isset($payment_dates[$date])) {
        $payment_dates[$date] = 0;
    }
    $payment_dates[$date] += $payment['payment_amount'];
}

mysqli_stmt_close($payments_stmt);

// Get invoice items with payment status - UPDATED to match your table structure
$items_sql = "SELECT ii.*, ms.service_name,
                     ii.item_total - ii.item_amount_paid as item_due,
                     ii.item_amount_paid as item_paid,
                     CASE 
                         WHEN ii.item_amount_paid >= ii.item_total THEN 'paid'
                         WHEN ii.item_amount_paid > 0 THEN 'partial' 
                         ELSE 'unpaid'
                     END as payment_status
              FROM invoice_items ii
              LEFT JOIN medical_services ms ON ii.service_id = ms.medical_service_id
              WHERE ii.item_invoice_id = ? 
              ORDER BY ii.item_id";
$items_stmt = mysqli_prepare($mysqli, $items_sql);
mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$invoice_items = [];
$total_items_due = 0;
$total_items_paid = 0;

while ($item = mysqli_fetch_assoc($items_result)) {
    $invoice_items[] = $item;
    $total_items_due += $item['item_total'];
    $total_items_paid += $item['item_paid'];
}
mysqli_stmt_close($items_stmt);

// Check if invoice can be closed
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
$can_close_invoice = false;
if (function_exists('canCloseInvoice')) {
    $can_close_invoice = canCloseInvoice($mysqli, $invoice_id) && $fully_paid;
}

// Generate PDF report if requested
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/plugins/TCPDF/tcpdf.php';
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Medical System');
    $pdf->SetAuthor('Medical System');
    $pdf->SetTitle('Payment History - Invoice #' . $invoice['invoice_number']);
    $pdf->SetSubject('Payment History Report');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Add logo and header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'PAYMENT HISTORY REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Invoice #: ' . $invoice['invoice_number'], 0, 1, 'C');
    $pdf->Cell(0, 5, 'Generated on: ' . date('F j, Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Invoice Information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Invoice Information', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $info_html = '<table border="0.5" cellpadding="4">
        <tr>
            <td width="30%"><b>Patient Name:</b></td>
            <td width="70%">' . htmlspecialchars($invoice['patient_name']) . '</td>
        </tr>
        <tr>
            <td><b>Patient MRN:</b></td>
            <td>' . htmlspecialchars($invoice['patient_mrn']) . '</td>
        </tr>
        <tr>
            <td><b>Invoice Date:</b></td>
            <td>' . date('F j, Y', strtotime($invoice['invoice_date'])) . '</td>
        </tr>
        <tr>
            <td><b>Total Amount:</b></td>
            <td>KSH ' . number_format($invoice['invoice_amount'], 2) . '</td>
        </tr>
        <tr>
            <td><b>Paid Amount:</b></td>
            <td>KSH ' . number_format($invoice['paid_amount'], 2) . '</td>
        </tr>
        <tr>
            <td><b>Remaining Balance:</b></td>
            <td>KSH ' . number_format($invoice['remaining_balance'], 2) . '</td>
        </tr>
        <tr>
            <td><b>Status:</b></td>
            <td>' . ucfirst(str_replace('_', ' ', $invoice['invoice_status'])) . '</td>
        </tr>
    </table>';
    
    $pdf->writeHTML($info_html, true, false, true, false, '');
    $pdf->Ln(10);
    
    // Payment History
    if (!empty($payments)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Payment History (' . count($payments) . ' payments)', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        
        $payments_html = '<table border="0.5" cellpadding="4">
            <thead>
                <tr style="background-color:#f2f2f2;">
                    <th width="15%"><b>Date</b></th>
                    <th width="20%"><b>Payment #</b></th>
                    <th width="15%"><b>Method</b></th>
                    <th width="20%"><b>Amount</b></th>
                    <th width="20%"><b>Received By</b></th>
                    <th width="10%"><b>Status</b></th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($payments as $payment) {
            $payments_html .= '
                <tr>
                    <td>' . date('M j, Y', strtotime($payment['payment_date'])) . '</td>
                    <td>' . htmlspecialchars($payment['payment_number']) . '</td>
                    <td>' . ucfirst($payment['payment_method']) . '</td>
                    <td>KSH ' . number_format($payment['payment_amount'], 2) . '</td>
                    <td>' . htmlspecialchars($payment['received_by']) . '</td>
                    <td>' . ucfirst($payment['payment_status']) . '</td>
                </tr>';
        }
        
        $payments_html .= '</tbody></table>';
        
        $pdf->writeHTML($payments_html, true, false, true, false, '');
    } else {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 10, 'No payments recorded for this invoice.', 0, 1);
    }
    
    $pdf->Ln(10);
    
    // Invoice Items
    if (!empty($invoice_items)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Invoice Items (' . count($invoice_items) . ' items)', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        
        $items_html = '<table border="0.5" cellpadding="4">
            <thead>
                <tr style="background-color:#f2f2f2;">
                    <th width="40%"><b>Item Description</b></th>
                    <th width="15%"><b>Qty</b></th>
                    <th width="15%"><b>Unit Price</b></th>
                    <th width="15%"><b>Total</b></th>
                    <th width="15%"><b>Paid</b></th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($invoice_items as $item) {
            $items_html .= '
                <tr>
                    <td>' . htmlspecialchars($item['item_name'] ?? $item['service_name']) . '</td>
                    <td>' . $item['item_quantity'] . '</td>
                    <td>KSH ' . number_format($item['item_price'], 2) . '</td>
                    <td>KSH ' . number_format($item['item_total'], 2) . '</td>
                    <td>KSH ' . number_format($item['item_paid'], 2) . '</td>
                </tr>';
        }
        
        $items_html .= '</tbody></table>';
        
        $pdf->writeHTML($items_html, true, false, true, false, '');
    }
    
    // Output PDF
    $pdf->Output('payment_history_invoice_' . $invoice['invoice_number'] . '.pdf', 'D');
    exit;
}

// Generate CSV report if requested
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payment_history_invoice_' . $invoice['invoice_number'] . '_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['PAYMENT HISTORY REPORT - Invoice #' . $invoice['invoice_number']]);
    fputcsv($output, ['Generated on: ' . date('F j, Y H:i:s')]);
    fputcsv($output, []);
    
    // Invoice Information
    fputcsv($output, ['INVOICE INFORMATION']);
    fputcsv($output, ['Patient Name:', $invoice['patient_name']]);
    fputcsv($output, ['Patient MRN:', $invoice['patient_mrn']]);
    fputcsv($output, ['Invoice Date:', date('F j, Y', strtotime($invoice['invoice_date']))]);
    fputcsv($output, ['Total Amount:', 'KSH ' . number_format($invoice['invoice_amount'], 2)]);
    fputcsv($output, ['Paid Amount:', 'KSH ' . number_format($invoice['paid_amount'], 2)]);
    fputcsv($output, ['Remaining Balance:', 'KSH ' . number_format($invoice['remaining_balance'], 2)]);
    fputcsv($output, ['Status:', ucfirst(str_replace('_', ' ', $invoice['invoice_status']))]);
    fputcsv($output, []);
    
    // Payment History
    fputcsv($output, ['PAYMENT HISTORY']);
    fputcsv($output, ['Date', 'Payment #', 'Method', 'Amount', 'Received By', 'Status', 'Notes']);
    
    foreach ($payments as $payment) {
        fputcsv($output, [
            date('Y-m-d', strtotime($payment['payment_date'])),
            $payment['payment_number'],
            ucfirst($payment['payment_method']),
            'KSH ' . number_format($payment['payment_amount'], 2),
            $payment['received_by'],
            ucfirst($payment['payment_status']),
            $payment['payment_notes']
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['Total Payments:', count($payments), '', 'KSH ' . number_format($total_paid, 2)]);
    fputcsv($output, []);
    
    // Invoice Items
    fputcsv($output, ['INVOICE ITEMS']);
    fputcsv($output, ['Item Description', 'Quantity', 'Unit Price', 'Total', 'Paid', 'Due', 'Status']);
    
    foreach ($invoice_items as $item) {
        fputcsv($output, [
            $item['item_name'] ?? $item['service_name'],
            $item['item_quantity'],
            'KSH ' . number_format($item['item_price'], 2),
            'KSH ' . number_format($item['item_total'], 2),
            'KSH ' . number_format($item['item_paid'], 2),
            'KSH ' . number_format($item['item_due'], 2),
            ucfirst($item['payment_status'])
        ]);
    }
    
    fclose($output);
    exit;
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-history mr-2"></i>Payment History
            </h3>
            <div class="card-tools">
                <a href="billing_invoices.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 
                                      ($_SESSION['alert_type'] == 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <?php if($invoice): ?>
        <!-- Payment History for Specific Invoice -->
        <div class="row">
            <div class="col-md-8">
                <!-- Invoice Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Invoice Information</h3>
                        <div class="card-tools">
                            <span class="badge badge-<?php 
                                echo $invoice['invoice_status'] === 'closed' ? 'secondary' : 
                                     ($invoice['invoice_status'] === 'partially_paid' ? 'warning' : 'danger'); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                                <?php if($fully_paid && $invoice['invoice_status'] !== 'closed'): ?>
                                    <i class="fas fa-check ml-1 text-success"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Patient Name</label>
                                    <div class="form-control-plaintext">
                                        <?php echo htmlspecialchars($invoice['patient_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Invoice Number</label>
                                    <div class="form-control-plaintext">
                                        <span class="badge badge-primary"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">Total Amount</label>
                                    <div class="form-control-plaintext font-weight-bold text-success">
                                        KSH <?php echo number_format($invoice['invoice_amount'], 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">Paid Amount</label>
                                    <div class="form-control-plaintext font-weight-bold text-info">
                                        KSH <?php echo number_format($invoice['paid_amount'], 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">Remaining Balance</label>
                                    <div class="form-control-plaintext font-weight-bold text-danger">
                                        KSH <?php echo number_format($invoice['remaining_balance'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Visit Status</label>
                                    <div class="form-control-plaintext">
                                        <?php if($invoice['visit_status']): ?>
                                            <span class="badge badge-<?php 
                                                echo $invoice['visit_status'] === 'discharged' ? 'success' : 
                                                     ($invoice['visit_status'] === 'admitted' ? 'primary' : 'warning'); 
                                            ?>">
                                                <?php echo ucfirst($invoice['visit_status']); ?>
                                                <?php if($invoice['visit_discharge_date']): ?>
                                                    <br><small>Discharged: <?php echo date('M j, Y', strtotime($invoice['visit_discharge_date'])); ?></small>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">No Visit Linked</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Payment Count</label>
                                    <div class="form-control-plaintext">
                                        <span class="badge badge-info"><?php echo $invoice['payment_count']; ?> payments</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export and Actions -->
                <div class="card card-warning mt-3 no-print">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-download mr-2"></i>Export Options</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="btn-group" role="group">
                                    <a href="billing_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-success">
                                        <i class="fas fa-credit-card mr-2"></i>Process Payment
                                    </a>
                                    <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-warning">
                                        <i class="fas fa-edit mr-2"></i>Edit Invoice
                                    </a>
                                    <a href="billing_invoices.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-6 text-right">
                                <div class="btn-group export-buttons" role="group">
                                    <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                                        <i class="fas fa-print mr-2"></i>Print
                                    </button>
                                    <a href="?invoice_id=<?php echo $invoice_id; ?>&export=pdf" class="btn btn-outline-danger">
                                        <i class="fas fa-file-pdf mr-2"></i>PDF
                                    </a>
                                    <a href="?invoice_id=<?php echo $invoice_id; ?>&export=csv" class="btn btn-outline-success">
                                        <i class="fas fa-file-excel mr-2"></i>CSV
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-history mr-2"></i>Payment History
                            <?php if(!empty($payments)): ?>
                                <span class="badge badge-primary ml-2"><?php echo count($payments); ?> Payments</span>
                            <?php endif; ?>
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($payments)): ?>
                            <div class="payment-timeline">
                                <?php foreach($payments as $payment): 
                                    $method_class = str_replace('_', '-', $payment['payment_method']);
                                    $status_class = strtolower($payment['payment_status']) . '-badge';
                                ?>
                                <div class="timeline-item">
                                    <div class="card payment-card mb-3">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <h5 class="card-title mb-1">
                                                        <?php echo htmlspecialchars($payment['payment_number']); ?>
                                                        <span class="badge <?php echo $status_class; ?> ml-2">
                                                            <?php echo ucfirst($payment['payment_status']); ?>
                                                        </span>
                                                    </h5>
                                                    <p class="card-text mb-2">
                                                        <i class="far fa-calendar-alt mr-1"></i>
                                                        <?php echo date('F j, Y H:i', strtotime($payment['payment_date'])); ?>
                                                    </p>
                                                    <div class="mb-2">
                                                        <span class="badge <?php echo $method_class; ?>-badge">
                                                            <?php echo ucfirst($payment['payment_method']); ?>
                                                        </span>
                                                        <?php if($payment['journal_entry']): ?>
                                                            <span class="badge badge-info ml-2">
                                                                <i class="fas fa-book mr-1"></i>
                                                                <?php echo $payment['journal_entry']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if($payment['register_name']): ?>
                                                            <span class="badge badge-secondary ml-2">
                                                                <i class="fas fa-cash-register mr-1"></i>
                                                                <?php echo htmlspecialchars($payment['register_name']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if($payment['payment_notes']): ?>
                                                        <p class="card-text">
                                                            <i class="far fa-sticky-note mr-1"></i>
                                                            <?php echo htmlspecialchars($payment['payment_notes']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4 text-right">
                                                    <h3 class="text-success mb-0">KSH <?php echo number_format($payment['payment_amount'], 2); ?></h3>
                                                    <p class="text-muted mb-2">
                                                        <small>Received by: <?php echo htmlspecialchars($payment['received_by']); ?></small>
                                                    </p>
                                                    <div class="btn-group">
                                                        <a href="payment_receipt.php?payment_id=<?php echo $payment['payment_id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                                            <i class="fas fa-receipt mr-1"></i>Receipt
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-outline-info" 
                                                                data-toggle="modal" data-target="#paymentDetailsModal<?php echo $payment['payment_id']; ?>">
                                                            <i class="fas fa-info-circle mr-1"></i>Details
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment Details Modal -->
                                    <div class="modal fade" id="paymentDetailsModal<?php echo $payment['payment_id']; ?>" tabindex="-1" role="dialog">
                                        <div class="modal-dialog modal-lg" role="document">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">
                                                        Payment Details - <?php echo htmlspecialchars($payment['payment_number']); ?>
                                                    </h5>
                                                    <button type="button" class="close" data-dismiss="modal">
                                                        <span>&times;</span>
                                                    </button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="table-responsive">
                                                        <table class="table table-bordered payment-details-table">
                                                            <tbody>
                                                                <tr>
                                                                    <th width="30%">Payment Number:</th>
                                                                    <td><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Amount:</th>
                                                                    <td class="text-success font-weight-bold">KSH <?php echo number_format($payment['payment_amount'], 2); ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Payment Method:</th>
                                                                    <td>
                                                                        <span class="badge <?php echo $method_class; ?>-badge">
                                                                            <?php echo ucfirst($payment['payment_method']); ?>
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Payment Date:</th>
                                                                    <td><?php echo date('F j, Y H:i:s', strtotime($payment['payment_date'])); ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Status:</th>
                                                                    <td>
                                                                        <span class="badge <?php echo $status_class; ?>">
                                                                            <?php echo ucfirst($payment['payment_status']); ?>
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                                <tr>
                                                                    <th>Received By:</th>
                                                                    <td><?php echo htmlspecialchars($payment['received_by']); ?></td>
                                                                </tr>
                                                                <?php if($payment['register_name']): ?>
                                                                <tr>
                                                                    <th>Cash Register:</th>
                                                                    <td><?php echo htmlspecialchars($payment['register_name']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if($payment['journal_entry']): ?>
                                                                <tr>
                                                                    <th>Journal Entry:</th>
                                                                    <td><?php echo htmlspecialchars($payment['journal_entry']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if($payment['payment_notes']): ?>
                                                                <tr>
                                                                    <th>Notes:</th>
                                                                    <td><?php echo nl2br(htmlspecialchars($payment['payment_notes'])); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                
                                                                <!-- Payment Method Specific Details -->
                                                                <?php if($payment['payment_method'] == 'insurance' && $payment['insurance_provider']): ?>
                                                                <tr class="table-warning">
                                                                    <th colspan="2" class="text-center">Insurance Details</th>
                                                                </tr>
                                                                <tr>
                                                                    <th>Insurance Provider:</th>
                                                                    <td><?php echo htmlspecialchars($payment['insurance_provider']); ?></td>
                                                                </tr>
                                                                <?php if($payment['claim_number']): ?>
                                                                <tr>
                                                                    <th>Claim Number:</th>
                                                                    <td><?php echo htmlspecialchars($payment['claim_number']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if($payment['approval_code']): ?>
                                                                <tr>
                                                                    <th>Approval Code:</th>
                                                                    <td><?php echo htmlspecialchars($payment['approval_code']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php endif; ?>
                                                                
                                                                <?php if($payment['payment_method'] == 'mpesa_stk' && $payment['phone_number']): ?>
                                                                <tr class="table-primary">
                                                                    <th colspan="2" class="text-center">M-Pesa Details</th>
                                                                </tr>
                                                                <tr>
                                                                    <th>Phone Number:</th>
                                                                    <td><?php echo htmlspecialchars($payment['phone_number']); ?></td>
                                                                </tr>
                                                                <?php if($payment['mpesa_reference']): ?>
                                                                <tr>
                                                                    <th>Transaction Reference:</th>
                                                                    <td><?php echo htmlspecialchars($payment['mpesa_reference']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php endif; ?>
                                                                
                                                                <?php if($payment['payment_method'] == 'card' && $payment['card_type']): ?>
                                                                <tr class="table-info">
                                                                    <th colspan="2" class="text-center">Card Details</th>
                                                                </tr>
                                                                <tr>
                                                                    <th>Card Type:</th>
                                                                    <td><?php echo ucfirst($payment['card_type']); ?></td>
                                                                </tr>
                                                                <?php if($payment['card_last_four']): ?>
                                                                <tr>
                                                                    <th>Last 4 Digits:</th>
                                                                    <td>**** **** **** <?php echo htmlspecialchars($payment['card_last_four']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if($payment['auth_code']): ?>
                                                                <tr>
                                                                    <th>Authorization Code:</th>
                                                                    <td><?php echo htmlspecialchars($payment['auth_code']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <a href="payment_receipt.php?payment_id=<?php echo $payment['payment_id']; ?>" 
                                                       class="btn btn-primary" target="_blank">
                                                        <i class="fas fa-receipt mr-2"></i>View Receipt
                                                    </a>
                                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-receipt fa-4x text-muted mb-3"></i>
                                <h4>No Payment History</h4>
                                <p class="text-muted">No payments have been recorded for this invoice yet.</p>
                                <a href="billing_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-success">
                                    <i class="fas fa-credit-card mr-2"></i>Process First Payment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Invoice Items -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list-alt mr-2"></i>Invoice Items & Payment Allocation
                            <span class="badge badge-secondary ml-2"><?php echo count($invoice_items); ?> Items</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($invoice_items)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Item Description</th>
                                            <th class="text-center">Quantity</th>
                                            <th class="text-right">Unit Price</th>
                                            <th class="text-right">Total Amount</th>
                                            <th class="text-right">Amount Paid</th>
                                            <th class="text-right">Amount Due</th>
                                            <th class="text-center">Payment Status</th>
                                            <th class="text-center">Payment Progress</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($invoice_items as $index => $item): 
                                            $paid_percentage = $item['item_total'] > 0 ? ($item['item_paid'] / $item['item_total'] * 100) : 0;
                                            $status_color = $item['payment_status'] == 'paid' ? 'success' : 
                                                           ($item['payment_status'] == 'partial' ? 'warning' : 'danger');
                                            $item_name = $item['item_name'] ?? $item['service_name'] ?? 'N/A';
                                        ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item_name); ?></strong>
                                                <?php if(!empty($item['item_description'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($item['item_description']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?php echo $item['item_quantity']; ?></td>
                                            <td class="text-right font-weight-bold">KSH <?php echo number_format($item['item_price'], 2); ?></td>
                                            <td class="text-right font-weight-bold text-primary">KSH <?php echo number_format($item['item_total'], 2); ?></td>
                                            <td class="text-right text-success font-weight-bold">KSH <?php echo number_format($item['item_paid'], 2); ?></td>
                                            <td class="text-right text-danger font-weight-bold">KSH <?php echo number_format($item['item_due'], 2); ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php echo $status_color; ?>">
                                                    <?php echo ucfirst($item['payment_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-<?php echo $status_color; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $paid_percentage; ?>%"
                                                         aria-valuenow="<?php echo $paid_percentage; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                        <?php echo round($paid_percentage, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="bg-light">
                                        <tr>
                                            <td colspan="4" class="text-right font-weight-bold">Totals:</td>
                                            <td class="text-right font-weight-bold text-primary">KSH <?php echo number_format($total_items_due, 2); ?></td>
                                            <td class="text-right font-weight-bold text-success">KSH <?php echo number_format($total_items_paid, 2); ?></td>
                                            <td class="text-right font-weight-bold text-danger">KSH <?php echo number_format($total_items_due - $total_items_paid, 2); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                                <h5>No Invoice Items Found</h5>
                                <p class="text-muted">This invoice doesn't have any items added yet.</p>
                                <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-warning">
                                    <i class="fas fa-edit mr-2"></i>Add Items to Invoice
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="billing_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-success">
                                <i class="fas fa-credit-card mr-2"></i>Process Payment
                            </a>
                            <a href="patient_profile.php?patient_id=<?php echo $invoice['patient_id']; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-user mr-2"></i>View Patient
                            </a>
                            <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Invoice
                            </a>
                            <?php if($invoice['visit_status'] && $invoice['visit_id']): ?>
                            <a href="patient_visit.php?patient_id=<?php echo $invoice['patient_id']; ?>&visit_id=<?php echo $invoice['visit_id']; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-hospital mr-2"></i>View Visit
                            </a>
                            <?php endif; ?>
                            <a href="billing_invoices.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Payment Summary -->
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Payment Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Invoice Amount:</span>
                                <span class="font-weight-bold text-success">KSH <?php echo number_format($invoice['invoice_amount'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Paid Amount:</span>
                                <span class="font-weight-bold text-info">KSH <?php echo number_format($invoice['paid_amount'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Remaining Balance:</span>
                                <span class="font-weight-bold text-danger">KSH <?php echo number_format($invoice['remaining_balance'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Payment Count:</span>
                                <span class="font-weight-bold"><?php echo $invoice['payment_count']; ?> payments</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Invoice Status:</span>
                                <span class="badge badge-<?php 
                                    echo $invoice['invoice_status'] === 'closed' ? 'secondary' : 
                                         ($invoice['invoice_status'] === 'partially_paid' ? 'warning' : 'danger'); 
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                                    <?php if($fully_paid && $invoice['invoice_status'] !== 'closed'): ?>
                                        <i class="fas fa-check ml-1 text-success"></i>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Visit Status:</span>
                                <span class="badge badge-<?php 
                                    echo $invoice['visit_status'] === 'discharged' ? 'success' : 
                                         ($invoice['visit_status'] === 'admitted' ? 'primary' : 'secondary'); 
                                ?>">
                                    <?php echo $invoice['visit_status'] ? ucfirst($invoice['visit_status']) : 'No Visit'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Payments -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-receipt mr-2"></i>Recent Payments</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($payments)): ?>
                            <div class="list-group list-group-flush">
                                <?php 
                                $counter = 0;
                                foreach ($payments as $payment): 
                                    if ($counter++ >= 5) break;
                                    $method_class = str_replace('_', '-', $payment['payment_method']);
                                ?>
                                <div class="list-group-item px-0 py-2 border-0">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($payment['payment_number']); ?></h6>
                                        <small class="text-success font-weight-bold">
                                            KSH <?php echo number_format($payment['payment_amount'], 2); ?>
                                        </small>
                                    </div>
                                    <p class="mb-1 small">
                                        <span class="badge <?php echo $method_class; ?>-badge"><?php echo ucfirst($payment['payment_method']); ?></span>
                                        <span class="badge badge-success ml-1">Completed</span>
                                    </p>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    </small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($payments) > 5): ?>
                            <div class="text-center mt-2">
                                <button class="btn btn-sm btn-outline-warning" onclick="$('.timeline-item').toggle();">
                                    <i class="fas fa-list mr-1"></i>View All <?php echo count($payments); ?> Payments
                                </button>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-receipt fa-2x text-muted mb-2"></i>
                                <h6>No Payments Yet</h6>
                                <p class="text-muted small">This invoice has no recorded payments.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Invoice Closure Status -->
                <?php if($invoice['invoice_status'] != 'closed' && $fully_paid): ?>
                <div class="card card-danger mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-lock mr-2"></i>Close Invoice</h3>
                    </div>
                    <div class="card-body">
                        <?php if($can_close_invoice): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Ready to Close:</strong>
                                <ul class="mt-2 mb-0">
                                    <li>✓ Invoice is fully paid</li>
                                    <li>✓ Visit is discharged</li>
                                    <li>✓ All billing items are discharged</li>
                                    <li>✓ No pending adjustments</li>
                                </ul>
                            </div>
                            <a href="billing_payment.php?invoice_id=<?php echo $invoice_id; ?>#close-invoice" class="btn btn-danger btn-block">
                                <i class="fas fa-lock mr-2"></i>Close Invoice
                            </a>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Invoice cannot be closed yet</strong>
                                <ul class="mt-2 mb-0">
                                    <li><strong>Invoice:</strong> 
                                        <span class="badge badge-<?php echo $fully_paid ? 'success' : 'danger'; ?>">
                                            <?php echo $fully_paid ? '✓ Fully Paid' : '✗ Not Fully Paid'; ?>
                                        </span>
                                    </li>
                                    <li><strong>Visit:</strong> 
                                        <span class="badge badge-<?php echo ($invoice['visit_status'] == 'discharged' && $invoice['visit_discharge_date']) ? 'success' : 'danger'; ?>">
                                            <?php echo ($invoice['visit_status'] == 'discharged' && $invoice['visit_discharge_date']) ? '✓ Discharged' : '✗ Not Discharged'; ?>
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Select Invoice to View History -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Select Invoice</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoice #</th>
                                        <th>Patient</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">Paid</th>
                                        <th class="text-right">Balance</th>
                                        <th>Status</th>
                                        <th>Payments</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $invoices = mysqli_query($mysqli, "
                                        SELECT i.*, 
                                               CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                                               i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance,
                                               (SELECT COUNT(*) FROM payments WHERE payment_invoice_id = i.invoice_id) as payment_count
                                        FROM invoices i
                                        LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                                        WHERE i.invoice_status != 'deleted'
                                        ORDER BY i.invoice_date DESC
                                        LIMIT 20
                                    ");
                                    
                                    while($inv = mysqli_fetch_assoc($invoices)): 
                                        $status_class = $inv['invoice_status'] === 'closed' ? 'secondary' : 
                                                       ($inv['invoice_status'] === 'partially_paid' ? 'warning' : 'danger');
                                    ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($inv['invoice_date'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($inv['patient_name']); ?></td>
                                        <td class="text-right font-weight-bold">KSH <?php echo number_format($inv['invoice_amount'], 2); ?></td>
                                        <td class="text-right text-success">KSH <?php echo number_format($inv['paid_amount'], 2); ?></td>
                                        <td class="text-right text-danger font-weight-bold">KSH <?php echo number_format($inv['remaining_balance'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $inv['invoice_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo $inv['payment_count']; ?> payments</span>
                                        </td>
                                        <td class="text-center">
                                            <a href="<?php echo $current_page; ?>?invoice_id=<?php echo $inv['invoice_id']; ?>" 
                                               class="btn btn-sm btn-info">
                                                <i class="fas fa-history mr-1"></i>View History
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .payment-method-badge {
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
    .cash-badge { background-color: #28a745; color: white; }
    .mpesa-badge { background-color: #6f42c1; color: white; }
    .card-badge { background-color: #17a2b8; color: white; }
    .insurance-badge { background-color: #ffc107; color: black; }
    .check-badge { background-color: #007bff; color: white; }
    .bank-transfer-badge { background-color: #20c997; color: white; }
    .credit-badge { background-color: #fd7e14; color: white; }
    .nhif-badge { background-color: #e83e8c; color: white; }
    
    .status-badge {
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
    .completed-badge { background-color: #28a745; color: white; }
    .pending-badge { background-color: #ffc107; color: black; }
    .failed-badge { background-color: #dc3545; color: white; }
    .refunded-badge { background-color: #6c757d; color: white; }
    
    .payment-card {
        border-left: 4px solid #007bff;
        transition: transform 0.2s;
    }
    .payment-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    .payment-timeline {
        position: relative;
        padding-left: 30px;
    }
    .payment-timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: #dee2e6;
    }
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -23px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: #007bff;
        border: 2px solid white;
        box-shadow: 0 0 0 2px #007bff;
    }
    
    .payment-details-table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    
    .export-buttons .btn {
        min-width: 120px;
    }
    
    @media print {
        .no-print {
            display: none !important;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
        .table {
            font-size: 12px !important;
        }
    }
</style>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Print functionality
    window.printReport = function() {
        window.print();
    };
    
    // Filter payments by method
    window.filterPayments = function(method) {
        if (method === 'all') {
            $('.timeline-item').show();
        } else {
            $('.timeline-item').hide();
            $('.timeline-item .' + method + '-badge').closest('.timeline-item').show();
        }
    };
    
    // Search payments
    $('#searchPayments').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('.timeline-item').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(searchTerm));
        });
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
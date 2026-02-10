<?php
// Start output buffering
if (ob_get_level()) ob_end_clean();
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get invoice ID
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if ($invoice_id == 0) {
    ob_clean();
    die('Invalid invoice ID');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

try {
    // Get hospital details
    $hospital_stmt = $mysqli->prepare("
        SELECT company_name, company_address, company_city, company_state, 
               company_zip, company_country, company_phone, company_email,
               company_tax_id
        FROM companies 
        LIMIT 1
    ");
    $hospital_stmt->execute();
    $hospital_result = $hospital_stmt->get_result();
    $hospital = $hospital_result->fetch_assoc();
    $hospital_stmt->close();

    // Fetch invoice details
    $invoice_sql = "SELECT i.*, p.patient_last_name, p.patient_first_name, p.patient_phone, 
                           p.patient_email, p.patient_dob, p.patient_gender,
                           v.visit_date, v.visit_type,
                           d.user_name as doctor_name
                    FROM invoices i 
                    LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                    LEFT JOIN visits v ON i.visit_id = v.visit_id
                    LEFT JOIN users d ON v.visit_doctor_id = d.user_id
                    WHERE i.invoice_id = ?
                    LIMIT 1";
    
    $invoice_stmt = $mysqli->prepare($invoice_sql);
    $invoice_stmt->bind_param("i", $invoice_id);
    $invoice_stmt->execute();
    $invoice_result = $invoice_stmt->get_result();
    $invoice = $invoice_result->fetch_assoc();
    $invoice_stmt->close();

    // Fetch invoice items
    $items_sql = "SELECT * FROM invoice_items 
                  WHERE item_invoice_id = ? 
                  ORDER BY item_order ASC";
    
    $items_stmt = $mysqli->prepare($items_sql);
    $items_stmt->bind_param("i", $invoice_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    $invoice_items = [];
    $subtotal = 0;
    $total_tax = 0;

    while ($item = $items_result->fetch_assoc()) {
        $invoice_items[] = $item;
        $subtotal += $item['item_price'] * $item['item_quantity'];
        $total_tax += $item['item_tax'];
    }
    $items_stmt->close();

    // Calculate totals
    $total_amount = $subtotal + $total_tax;

    // Get detailed payments
    $payments_sql = "SELECT p.*, 
                            pm.payment_method_name,
                            ib.reference_number as bank_ref,
                            ic.card_type,
                            ick.check_number,
                            im.phone_number,
                            ii.insurance_provider
                     FROM payments p
                     LEFT JOIN payment_methods pm ON p.payment_method = pm.payment_method_name
                     LEFT JOIN payment_bank_details ib ON p.payment_id = ib.payment_id
                     LEFT JOIN payment_card_details ic ON p.payment_id = ic.payment_id
                     LEFT JOIN payment_check_details ick ON p.payment_id = ick.payment_id
                     LEFT JOIN payment_mpesa_details im ON p.payment_id = im.payment_id
                     LEFT JOIN payment_insurance_details ii ON p.payment_id = ii.payment_id
                     WHERE p.payment_invoice_id = ? AND p.payment_status = 'completed'";
    
    $payments_stmt = $mysqli->prepare($payments_sql);
    $payments_stmt->bind_param("i", $invoice_id);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->get_result();
    
    $cash_total = 0;
    $insurance_total = 0;
    $card_total = 0;
    $mpesa_total = 0;
    $bank_total = 0;
    $check_total = 0;
    $total_paid = 0;

    while ($payment = $payments_result->fetch_assoc()) {
        $total_paid += $payment['payment_amount'];
        
        $method = strtolower($payment['payment_method'] ?? '');
        if (strpos($method, 'cash') !== false) {
            $cash_total += $payment['payment_amount'];
        } elseif (strpos($method, 'insurance') !== false || !empty($payment['insurance_provider'])) {
            $insurance_total += $payment['payment_amount'];
        } elseif (strpos($method, 'card') !== false || !empty($payment['card_type'])) {
            $card_total += $payment['payment_amount'];
        } elseif (strpos($method, 'mpesa') !== false || !empty($payment['phone_number'])) {
            $mpesa_total += $payment['payment_amount'];
        } elseif (strpos($method, 'bank') !== false || !empty($payment['bank_ref'])) {
            $bank_total += $payment['payment_amount'];
        } elseif (strpos($method, 'check') !== false || !empty($payment['check_number'])) {
            $check_total += $payment['payment_amount'];
        }
    }
    $payments_stmt->close();

    $balance = $total_amount - $total_paid;

    // Clean buffer and set headers
    ob_clean();
    header("Content-type: application/octet-stream");
    header("Content-Disposition: attachment; filename=Medical_Invoice_" . $invoice['invoice_prefix'] . $invoice['invoice_number'] . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    // Build Excel content
    $columnHeader = '';
    $setData = '';

    // Hospital Header
    $setData .= "MEDICAL INVOICE" . "\t" . "\t" . "\t" . "\t" . "\n";
    $setData .= $hospital['company_name'] . "\t" . "\t" . "\t" . "\t" . "\n";
    
    $hospital_address = $hospital['company_address'] . ', ' . $hospital['company_city'] . ', ' . $hospital['company_state'] . ' ' . $hospital['company_zip'];
    $setData .= $hospital_address . "\t" . "\t" . "\t" . "\t" . "\n";
    
    $contact_info = 'Phone: ' . $hospital['company_phone'];
    if (!empty($hospital['company_email'])) {
        $contact_info .= ' | Email: ' . $hospital['company_email'];
    }
    $setData .= $contact_info . "\t" . "\t" . "\t" . "\t" . "\n";
    $setData .= "\n";

    // Invoice Information
    $setData .= "INVOICE INFORMATION" . "\t" . "\t" . "\t" . "\t" . "\n";
    $setData .= "Invoice Number" . "\t" . "Invoice Date" . "\t" . "Due Date" . "\t" . "Status" . "\t" . "\n";
    $setData .= $invoice['invoice_prefix'] . $invoice['invoice_number'] . "\t" . 
                date('m/d/Y', strtotime($invoice['invoice_date'])) . "\t" . 
                date('m/d/Y', strtotime($invoice['invoice_due'])) . "\t" . 
                ucfirst($invoice['invoice_status']) . "\t" . "\n";
    $setData .= "\n";

    // Patient Information
    $setData .= "PATIENT INFORMATION" . "\t" . "\t" . "\t" . "\t" . "\n";
    $setData .= "Patient Name" . "\t" . "Phone" . "\t" . "Email" . "\t" . "Insurance ID" . "\t" . "\n";
    $setData .= $invoice['patient_first_name'] . ' ' . $invoice['patient_last_name'] . "\t" . 
                ($invoice['patient_phone'] ?? 'N/A') . "\t" . 
                ($invoice['patient_email'] ?? 'N/A') . "\t" . 
                ($invoice['patient_insurance_id'] ?? 'Self-pay') . "\t" . "\n";
    $setData .= "\n";

    // Medical Services Header
    $setData .= "MEDICAL SERVICES & CHARGES" . "\t" . "\t" . "\t" . "\t" . "\n";
    $setData .= "Service Description" . "\t" . "Procedure Code" . "\t" . "Quantity" . "\t" . "Unit Price" . "\t" . "Amount" . "\t" . "\n";

    // Services Data
    foreach ($invoice_items as $item) {
        $line_total = $item['item_price'] * $item['item_quantity'];
        $setData .= $item['item_name'] . "\t" . 
                   ($item['item_code'] ?? 'N/A') . "\t" . 
                   $item['item_quantity'] . "\t" . 
                   '$' . number_format($item['item_price'], 2) . "\t" . 
                   '$' . number_format($line_total, 2) . "\t" . "\n";
    }
    $setData .= "\n";

    // Financial Summary
    $setData .= "FINANCIAL SUMMARY" . "\t" . "\t" . "\t" . "\t" . "\n";
    $setData .= "Description" . "\t" . "Amount" . "\t" . "\t" . "\t" . "\n";
    $setData .= "Services Subtotal" . "\t" . '$' . number_format($subtotal, 2) . "\t" . "\t" . "\t" . "\n";
    
    if ($total_tax > 0) {
        $setData .= "Tax" . "\t" . '$' . number_format($total_tax, 2) . "\t" . "\t" . "\t" . "\n";
    }
    
    $setData .= "Total Amount" . "\t" . '$' . number_format($total_amount, 2) . "\t" . "\t" . "\t" . "\n";
    
    // Payment Breakdown
    if ($insurance_total > 0) {
        $setData .= "Insurance Payments" . "\t" . '-$' . number_format($insurance_total, 2) . "\t" . "\t" . "\t" . "\n";
    }
    
    if ($cash_total > 0) {
        $setData .= "Cash Payments" . "\t" . '-$' . number_format($cash_total, 2) . "\t" . "\t" . "\t" . "\n";
    }
    
    if ($card_total > 0) {
        $setData .= "Card Payments" . "\t" . '-$' . number_format($card_total, 2) . "\t" . "\t" . "\t" . "\n";
    }
    
    if ($total_paid > 0) {
        $setData .= "Total Payments" . "\t" . '-$' . number_format($total_paid, 2) . "\t" . "\t" . "\t" . "\n";
    }
    
    $setData .= "BALANCE DUE" . "\t" . '$' . number_format($balance, 2) . "\t" . "\t" . "\t" . "\n";
    $setData .= "\n";

    // Footer
    $setData .= "Generated on: " . date('F j, Y \a\t g:i A') . "\t" . "\t" . "\t" . "\t" . "\n";
    $setData .= $hospital['company_name'] . " - Medical Billing Department" . "\t" . "\t" . "\t" . "\t" . "\n";

    echo $setData;
    exit;

} catch (Exception $e) {
    ob_clean();
    die('Export Error: ' . $e->getMessage());
}
?>
<?php
// Start output buffering at the VERY beginning - no whitespace before this!
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if order ID is provided before any output
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // Clean buffer and show error
    ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    die("Purchase order ID is required.");
}

$order_id = intval($_GET['id']);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/plugins/TCPDF/tcpdf.php';

try {
    // Get company details
    $company_stmt = $mysqli->prepare("
        SELECT company_name, company_address, company_city, company_state, 
               company_zip, company_country, company_phone_country_code, 
               company_phone, company_email, company_website, company_logo,
               company_tax_id
        FROM companies 
        LIMIT 1
    ");
    
    if (!$company_stmt) {
        throw new Exception("Company database error: " . $mysqli->error);
    }
    
    if (!$company_stmt->execute()) {
        throw new Exception("Company execute failed: " . $company_stmt->error);
    }
    
    $company_result = $company_stmt->get_result();
    $company = $company_result->fetch_assoc();
    $company_stmt->close();

    // Fetch purchase order details
    $order_sql = "SELECT po.*, s.supplier_name, s.supplier_contact, s.supplier_phone, 
                         s.supplier_email, s.supplier_address, u.user_name as created_by_name
                  FROM purchase_orders po
                  LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
                  LEFT JOIN users u ON po.created_by = u.user_id
                  WHERE po.order_id = ?";
    $order_stmt = $mysqli->prepare($order_sql);
    
    if (!$order_stmt) {
        throw new Exception("Order prepare failed: " . $mysqli->error);
    }
    
    $order_stmt->bind_param("i", $order_id);
    
    if (!$order_stmt->execute()) {
        throw new Exception("Order execute failed: " . $order_stmt->error);
    }
    
    $order_result = $order_stmt->get_result();

    if ($order_result->num_rows === 0) {
        ob_end_clean();
        header('Content-Type: text/html; charset=UTF-8');
        die("Purchase order not found.");
    }

    $order = $order_result->fetch_assoc();
    $order_stmt->close();

    // Fetch order items
    $items_sql = "SELECT poi.*, ii.item_code, ii.item_unit_measure
                  FROM purchase_order_items poi
                  LEFT JOIN inventory_items ii ON poi.item_id = ii.item_id
                  WHERE poi.order_id = ?
                  ORDER BY poi.item_name";
    $items_stmt = $mysqli->prepare($items_sql);
    
    if (!$items_stmt) {
        throw new Exception("Items prepare failed: " . $mysqli->error);
    }
    
    $items_stmt->bind_param("i", $order_id);
    
    if (!$items_stmt->execute()) {
        throw new Exception("Items execute failed: " . $items_stmt->error);
    }
    
    $items_result = $items_stmt->get_result();
    $order_items = [];
    $order_total = 0;

    while ($item = $items_result->fetch_assoc()) {
        $order_items[] = $item;
        $order_total += $item['total_cost'];
    }
    $items_stmt->close();

    // Clean any output buffers before creating PDF
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Create PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company['company_name'] ?? 'Your Company');
    $pdf->SetTitle('PO-' . $order['order_number']);
    $pdf->SetSubject('Purchase Order');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);

    // Add page
    $pdf->AddPage();

    // Build company address
    $company_address = $company['company_name'] . "\n";
    if (!empty($company['company_address'])) {
        $company_address .= $company['company_address'] . "\n";
    }
    $company_city_state = [];
    if (!empty($company['company_city'])) $company_city_state[] = $company['company_city'];
    if (!empty($company['company_state'])) $company_city_state[] = $company['company_state'];
    if (!empty($company['company_zip'])) $company_city_state[] = $company['company_zip'];
    if (!empty($company_city_state)) {
        $company_address .= implode(', ', $company_city_state) . "\n";
    }
    if (!empty($company['company_phone'])) {
        $company_address .= "Phone: " . $company['company_phone'] . "\n";
    }
    if (!empty($company['company_email'])) {
        $company_address .= "Email: " . $company['company_email'];
    }

    // Build supplier address
    $supplier_address = $order['supplier_name'] . "\n";
    if (!empty($order['supplier_address'])) {
        $supplier_address .= $order['supplier_address'] . "\n";
    }
    if (!empty($order['supplier_phone'])) {
        $supplier_address .= "Phone: " . $order['supplier_phone'] . "\n";
    }
    if (!empty($order['supplier_email'])) {
        $supplier_address .= "Email: " . $order['supplier_email'] . "\n";
    }
    if (!empty($order['supplier_contact'])) {
        $supplier_address .= "Contact: " . $order['supplier_contact'];
    }

    // Content using TCPDF methods instead of HTML
    
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, $company['company_name'] ?? 'Your Company Name', 0, 1, 'C');
    $pdf->Cell(0, 6, $company['company_address'] ?? '123 Business Street', 0, 1, 'C');
    
    $company_location = [];
    if (!empty($company['company_city'])) $company_location[] = $company['company_city'];
    if (!empty($company['company_state'])) $company_location[] = $company['company_state'];
    if (!empty($company['company_zip'])) $company_location[] = $company['company_zip'];
    $pdf->Cell(0, 6, implode(', ', $company_location) ?: 'City, State 12345', 0, 1, 'C');
    
    $contact_info = [];
    if (!empty($company['company_phone'])) $contact_info[] = 'Phone: ' . $company['company_phone'];
    if (!empty($company['company_email'])) $contact_info[] = 'Email: ' . $company['company_email'];
    $pdf->Cell(0, 6, implode(' | ', $contact_info) ?: 'Phone: (555) 123-4567 | Email: info@company.com', 0, 1, 'C');
   // Title
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(0, 128, 0); // Green color for GRN
    $pdf->Cell(0, 10, 'PURCHASE ORDER', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);

    // Order Information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Order Information', 0, 1);
    $pdf->SetFont('helvetica', '', 10);

    $order_info = [
        ['Order Number:', $order['order_number']],
        ['Order Date:', date('F j, Y', strtotime($order['order_date']))],
        ['Expected Delivery:', $order['expected_delivery_date'] ? date('F j, Y', strtotime($order['expected_delivery_date'])) : 'Not specified'],
        ['Order Status:', ucfirst($order['order_status'])],
        ['Prepared By:', $order['created_by_name']]
    ];

    foreach ($order_info as $info) {
        $pdf->Cell(50, 6, $info[0], 0, 0);
        $pdf->Cell(0, 6, $info[1], 0, 1);
    }
    $pdf->Ln(8);

    // From/To sections
    $col_width = 85;
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell($col_width, 8, 'From:', 0, 0);
    $pdf->Cell(10, 8, '', 0, 0);
    $pdf->Cell($col_width, 8, 'To:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);

    $pdf->MultiCell($col_width, 6, $company_address, 0, 'L', false, 0);
    $pdf->Cell(10, 6, '', 0, 0);
    $pdf->MultiCell($col_width, 6, $supplier_address, 0, 'L', false, 1);
    $pdf->Ln(10);

    // Items Table
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('helvetica', 'B', 10);

    // Column widths
    $col1 = 80;  // Item Description
    $col2 = 20;  // Qty
    $col3 = 25;  // Unit Price
    $col4 = 25;  // Total
    $col5 = 20;  // Code

    $pdf->Cell($col1, 8, 'Item Description', 1, 0, 'C', true);
    $pdf->Cell($col2, 8, 'Qty', 1, 0, 'C', true);
    $pdf->Cell($col5, 8, 'Code', 1, 0, 'C', true);
    $pdf->Cell($col3, 8, 'Unit Price', 1, 0, 'C', true);
    $pdf->Cell($col4, 8, 'Line Total', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);

    // Items
    foreach ($order_items as $item) {
        $description = $item['item_name'];
        if (!empty($item['item_description'])) {
            $description .= "\n" . $item['item_description'];
        }
        
        // Calculate height needed
        $desc_height = $pdf->getStringHeight($col1, $description, false, true, '', 1) + 1;
        $row_height = $desc_height;
        
        // Item description
        $pdf->MultiCell($col1, $row_height, $description, 1, 'L', false, 0);
        
        // Quantity
        $pdf->MultiCell($col2, $row_height, $item['quantity_ordered'], 1, 'C', false, 0);
        
        // Item Code
        $pdf->MultiCell($col5, $row_height, $item['item_code'] ?? '', 1, 'C', false, 0);
        
        // Unit Price
        $pdf->MultiCell($col3, $row_height, '$' . number_format($item['unit_cost'], 2), 1, 'R', false, 0);
        
        // Line Total
        $pdf->MultiCell($col4, $row_height, '$' . number_format($item['total_cost'], 2), 1, 'R', false, 1);
    }

    // Total
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell($col1 + $col2 + $col5 + $col3, 8, 'Total Amount:', 1, 0, 'R', true);
    $pdf->Cell($col4, 8, '$' . number_format($order_total, 2), 1, 1, 'R', true);

    // Notes
    if (!empty($order['notes'])) {
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Order Notes:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, $order['notes'], 0, 'L');
    }

    // Signature area
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', '', 10);
    $signature_width = 80;
    $pdf->Cell($signature_width, 6, '_________________________', 0, 0, 'C');
    $pdf->Cell(20, 6, '', 0, 0);
    $pdf->Cell($signature_width, 6, '_________________________', 0, 1, 'C');
    $pdf->Cell($signature_width, 6, 'Authorized Signature', 0, 0, 'C');
    $pdf->Cell(20, 6, '', 0, 0);
    $pdf->Cell($signature_width, 6, 'Date', 0, 1, 'C');

    // Footer
    $pdf->SetY(-20);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 6, 'Generated on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');
    $pdf->Cell(0, 6, 'Purchase Order ' . $order['order_number'], 0, 1, 'C');

    // Output PDF - this should be the only output
    $pdf->Output('purchase_order_' . $order['order_number'] . '.pdf', 'I');

} catch (Exception $e) {
    // Clean any buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Show error message
    header('Content-Type: text/html; charset=UTF-8');
    echo "Error generating PDF: " . $e->getMessage();
    exit;
}

// Close connection
if (isset($mysqli)) {
    $mysqli->close();
}
exit;
?>
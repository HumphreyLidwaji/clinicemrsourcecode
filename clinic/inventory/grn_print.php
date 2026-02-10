<?php
// Start output buffering at the VERY beginning
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if GRN ID is provided before any output
if (!isset($_GET['id']) || empty($_GET['id'])) {
    ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    die("GRN ID is required.");
}

$grn_id = intval($_GET['id']);

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

    // Fetch GRN details
    $grn_sql = "SELECT grn.*, po.order_number, po.order_date, po.expected_delivery_date,
                       po.order_status, po.total_amount as order_total,
                       s.supplier_name, s.supplier_contact, s.supplier_phone, 
                       s.supplier_email, s.supplier_address,
                       u.user_name as created_by_name
                FROM goods_received_notes grn
                LEFT JOIN purchase_orders po ON grn.order_id = po.order_id
                LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN users u ON grn.created_by = u.user_id
                WHERE grn.grn_id = ?";
    $grn_stmt = $mysqli->prepare($grn_sql);
    
    if (!$grn_stmt) {
        throw new Exception("GRN prepare failed: " . $mysqli->error);
    }
    
    $grn_stmt->bind_param("i", $grn_id);
    
    if (!$grn_stmt->execute()) {
        throw new Exception("GRN execute failed: " . $grn_stmt->error);
    }
    
    $grn_result = $grn_stmt->get_result();

    if ($grn_result->num_rows === 0) {
        ob_end_clean();
        header('Content-Type: text/html; charset=UTF-8');
        die("GRN not found.");
    }

    $grn = $grn_result->fetch_assoc();
    $grn_stmt->close();

    // Fetch GRN items
    $items_sql = "SELECT gi.*, ii.item_name, ii.item_code, ii.item_unit_measure,
                         poi.quantity_ordered, poi.unit_cost,
                         (gi.quantity_received * poi.unit_cost) as line_total
                  FROM grn_items gi
                  LEFT JOIN inventory_items ii ON gi.item_id = ii.item_id
                  LEFT JOIN purchase_order_items poi ON gi.item_id = poi.item_id AND poi.order_id = ?
                  WHERE gi.grn_id = ?
                  ORDER BY ii.item_name";
    $items_stmt = $mysqli->prepare($items_sql);
    
    if (!$items_stmt) {
        throw new Exception("Items prepare failed: " . $mysqli->error);
    }
    
    $items_stmt->bind_param("ii", $grn['order_id'], $grn_id);
    
    if (!$items_stmt->execute()) {
        throw new Exception("Items execute failed: " . $items_stmt->error);
    }
    
    $items_result = $items_stmt->get_result();
    $grn_items = [];
    $total_value = 0;
    $total_quantity = 0;

    while ($item = $items_result->fetch_assoc()) {
        $grn_items[] = $item;
        $total_value += $item['line_total'];
        $total_quantity += $item['quantity_received'];
    }
    $items_stmt->close();

    // Check if receipt was on time
    $is_ontime = true;
    if ($grn['expected_delivery_date'] && $grn['receipt_date']) {
        $delivery_date = new DateTime($grn['expected_delivery_date']);
        $receipt_date = new DateTime($grn['receipt_date']);
        if ($receipt_date > $delivery_date) {
            $is_ontime = false;
        }
    }

    // Clean any output buffers before creating PDF
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Create PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($company['company_name'] ?? 'Your Company');
    $pdf->SetTitle('GRN-' . $grn['grn_number']);
    $pdf->SetSubject('Goods Received Note');

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 25);

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
    $supplier_address = $grn['supplier_name'] . "\n";
    if (!empty($grn['supplier_address'])) {
        $supplier_address .= $grn['supplier_address'] . "\n";
    }
    if (!empty($grn['supplier_phone'])) {
        $supplier_address .= "Phone: " . $grn['supplier_phone'] . "\n";
    }
    if (!empty($grn['supplier_email'])) {
        $supplier_address .= "Email: " . $grn['supplier_email'] . "\n";
    }
    if (!empty($grn['supplier_contact'])) {
        $supplier_address .= "Contact: " . $grn['supplier_contact'];
    }

    // Company Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, $company['company_name'] ?? 'YOUR COMPANY NAME', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
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
    $pdf->Ln(10);

    // Title
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(0, 128, 0); // Green color for GRN
    $pdf->Cell(0, 10, 'GOODS RECEIVED NOTE', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);

    // GRN Information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'GRN Information', 0, 1);
    $pdf->SetFont('helvetica', '', 10);

    $grn_info = [
        ['GRN Number:', $grn['grn_number']],
        ['Purchase Order:', $grn['order_number']],
        ['Receipt Date:', date('F j, Y', strtotime($grn['receipt_date']))],
        ['Received By:', $grn['received_by']],
        ['Delivery Status:', $is_ontime ? 'On Time' : 'Delayed']
    ];

    foreach ($grn_info as $info) {
        $pdf->Cell(50, 6, $info[0], 0, 0);
        $pdf->Cell(0, 6, $info[1], 0, 1);
    }
    $pdf->Ln(8);

    // Two-column layout for From/To
    $col_width = 85;
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell($col_width, 8, 'From (Supplier):', 0, 0);
    $pdf->Cell(10, 8, '', 0, 0); // Spacer
    $pdf->Cell($col_width, 8, 'To (Company):', 0, 1);
    $pdf->SetFont('helvetica', '', 10);

    // Supplier Address (From)
    $pdf->MultiCell($col_width, 6, $supplier_address, 0, 'L', false, 0);
    $pdf->Cell(10, 6, '', 0, 0); // Spacer

    // Company Address (To)
    $pdf->MultiCell($col_width, 6, $company_address, 0, 'L', false, 1);
    $pdf->Ln(10);

    // Received Items Table Header
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('helvetica', 'B', 10);

    // Column widths
    $col1 = 70;  // Item Description
    $col2 = 20;  // Ordered Qty
    $col3 = 20;  // Received Qty
    $col4 = 20;  // Balance
    $col5 = 25;  // Unit Price
    $col6 = 25;  // Line Total

    $pdf->Cell($col1, 8, 'Item Description', 1, 0, 'C', true);
    $pdf->Cell($col2, 8, 'Ordered', 1, 0, 'C', true);
    $pdf->Cell($col3, 8, 'Received', 1, 0, 'C', true);
    $pdf->Cell($col4, 8, 'Balance', 1, 0, 'C', true);
    $pdf->Cell($col5, 8, 'Unit Price', 1, 0, 'C', true);
    $pdf->Cell($col6, 8, 'Line Total', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);

    // Items
    foreach ($grn_items as $item) {
        $description = $item['item_name'];
        if (!empty($item['item_code'])) {
            $description .= " (" . $item['item_code'] . ")";
        }
        if (!empty($item['item_unit_measure'])) {
            $description .= "\nUnit: " . $item['item_unit_measure'];
        }
        
        // Calculate height needed
        $desc_height = $pdf->getStringHeight($col1, $description, false, true, '', 1) + 1;
        $row_height = $desc_height;
        
        $balance = $item['quantity_ordered'] - $item['quantity_received'];
        
        // Item description
        $pdf->MultiCell($col1, $row_height, $description, 1, 'L', false, 0);
        
        // Ordered Quantity
        $pdf->MultiCell($col2, $row_height, number_format($item['quantity_ordered']), 1, 'C', false, 0);
        
        // Received Quantity
        $pdf->MultiCell($col3, $row_height, number_format($item['quantity_received']), 1, 'C', false, 0);
        
        // Balance
        $pdf->MultiCell($col4, $row_height, number_format($balance), 1, 'C', false, 0);
        
        // Unit Price
        $pdf->MultiCell($col5, $row_height, '$' . number_format($item['unit_cost'], 2), 1, 'R', false, 0);
        
        // Line Total
        $pdf->MultiCell($col6, $row_height, '$' . number_format($item['line_total'], 2), 1, 'R', false, 1);
    }

    // Summary Section
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', '', 10);

    $summary_width = 40;
    $value_width = 40;

    $pdf->Cell(0, 6, '', 0, 1); // Spacer
    $pdf->Cell(120, 6, '', 0, 0); // Move to right side
    $pdf->Cell($summary_width, 6, 'Total Items Received:', 0, 0, 'R');
    $pdf->Cell($value_width, 6, number_format($total_quantity), 0, 1, 'R');

    $pdf->Cell(120, 6, '', 0, 0);
    $pdf->Cell($summary_width, 6, 'Total Value:', 0, 0, 'R');
    $pdf->Cell($value_width, 6, '$' . number_format($total_value, 2), 0, 1, 'R');

    if ($grn['order_total']) {
        $pdf->Cell(120, 6, '', 0, 0);
        $pdf->Cell($summary_width, 6, 'Order Total:', 0, 0, 'R');
        $pdf->Cell($value_width, 6, '$' . number_format($grn['order_total'], 2), 0, 1, 'R');

        $remaining = $grn['order_total'] - $total_value;
        $pdf->Cell(120, 6, '', 0, 0);
        $pdf->Cell($summary_width, 6, 'Remaining Balance:', 0, 0, 'R');
        $pdf->Cell($value_width, 6, '$' . number_format($remaining, 2), 0, 1, 'R');
    }

    // Notes Section
    if (!empty($grn['notes'])) {
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'GRN Notes:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, $grn['notes'], 0, 'L');
    }

    // Terms and Conditions
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Receipt Confirmation:', 0, 1);
    $pdf->SetFont('helvetica', '', 9);

    $terms = [
        '1. All items received in good condition unless noted above',
        '2. Quantities verified against delivery documentation',
        '3. Quality inspection completed satisfactorily',
        '4. Items match purchase order specifications',
        '5. Packaging intact and undamaged',
        '6. Received by authorized personnel only'
    ];

    foreach ($terms as $term) {
        $pdf->Cell(0, 5, $term, 0, 1);
    }

    // Signature Section
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', '', 10);

    $signature_width = 80;
    $pdf->Cell($signature_width, 6, '_________________________', 0, 0, 'C');
    $pdf->Cell(20, 6, '', 0, 0);
    $pdf->Cell($signature_width, 6, '_________________________', 0, 1, 'C');

    $pdf->Cell($signature_width, 6, "Receiver's Signature", 0, 0, 'C');
    $pdf->Cell(20, 6, '', 0, 0);
    $pdf->Cell($signature_width, 6, 'Date', 0, 1, 'C');

    $pdf->Ln(5);
    $pdf->Cell($signature_width, 6, 'Name: ' . $grn['received_by'], 0, 0, 'C');
    $pdf->Cell(20, 6, '', 0, 0);
    $pdf->Cell($signature_width, 6, 'Date: ' . date('M j, Y', strtotime($grn['receipt_date'])), 0, 1, 'C');

    // Footer
    $pdf->SetY(-25);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 6, 'Generated on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');
    $pdf->Cell(0, 6, 'GRN ' . $grn['grn_number'] . ' | Purchase Order ' . $grn['order_number'] . ' | Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 1, 'C');

    // Output PDF - this should be the only output
    $pdf->Output('grn_' . $grn['grn_number'] . '.pdf', 'I');

} catch (Exception $e) {
    // Clean any buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Show error message
    header('Content-Type: text/html; charset=UTF-8');
    echo "Error generating GRN PDF: " . $e->getMessage();
    exit;
}

// Close connection
if (isset($mysqli)) {
    $mysqli->close();
}
exit;
?>
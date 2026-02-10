<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transfer ID from URL
$transfer_id = intval($_GET['id'] ?? 0);

if (!$transfer_id) {
    die("Invalid transfer ID.");
}

// Get transfer details
$transfer_sql = "SELECT t.*, 
                        u.user_name as requested_by_name,
                        fl.location_name as from_location_name,
                        fl.location_type as from_location_type,
                        tl.location_name as to_location_name,
                        tl.location_type as to_location_type
         
                 FROM inventory_transfers t
                 LEFT JOIN users u ON t.requested_by = u.user_id
                 LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                 LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                 WHERE t.transfer_id = ?";
$transfer_stmt = $mysqli->prepare($transfer_sql);
$transfer_stmt->bind_param("i", $transfer_id);
$transfer_stmt->execute();
$transfer_result = $transfer_stmt->get_result();

if ($transfer_result->num_rows === 0) {
    die("Transfer not found.");
}

$transfer = $transfer_result->fetch_assoc();
$transfer_stmt->close();

// Get transfer items
$items_sql = "SELECT ti.*, i.item_name, i.item_code, i.item_unit_measure,
                     i.item_quantity as current_stock, i.item_low_stock_alert
              FROM inventory_transfer_items ti
              JOIN inventory_items i ON ti.item_id = i.item_id
              WHERE ti.transfer_id = ?
              ORDER BY i.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $transfer_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$transfer_items = [];
$total_items = 0;
$total_quantity = 0;

while ($item = $items_result->fetch_assoc()) {
    $transfer_items[] = $item;
    $total_items++;
    $total_quantity += $item['quantity'];
}
$items_stmt->close();

// Determine status display
switch($transfer['transfer_status']) {
    case 'pending':
        $status_display = 'PENDING';
        $status_class = 'warning';
        break;
    case 'in_transit':
        $status_display = 'IN TRANSIT';
        $status_class = 'info';
        break;
    case 'completed':
        $status_display = 'COMPLETED';
        $status_class = 'success';
        break;
    case 'cancelled':
        $status_display = 'CANCELLED';
        $status_class = 'danger';
        break;
    default:
        $status_display = strtoupper($transfer['transfer_status']);
        $status_class = 'secondary';
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer #<?php echo htmlspecialchars($transfer['transfer_number']); ?> - Print</title>
    <style>
        @page {
            margin: 0.5in;
            size: letter portrait;
        }
        
        * {
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        
        body {
            margin: 0;
            padding: 0;
            color: #333;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .container {
            max-width: 8.5in;
            margin: 0 auto;
            padding: 10px;
        }
        
        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .company-info {
            flex: 1;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .company-details {
            font-size: 11px;
            color: #666;
        }
        
        .document-title {
            text-align: right;
            flex: 1;
        }
        
        .document-title h1 {
            font-size: 28px;
            color: #2c3e50;
            margin: 0 0 10px 0;
        }
        
        .document-title .badge {
            display: inline-block;
            padding: 5px 15px;
            background-color: #<?php echo $status_class == 'warning' ? 'ffc107' : ($status_class == 'info' ? '17a2b8' : ($status_class == 'success' ? '28a745' : ($status_class == 'danger' ? 'dc3545' : '6c757d'))); ?>;
            color: #<?php echo $status_class == 'warning' ? '000' : 'fff'; ?>;
            border-radius: 4px;
            font-weight: bold;
            font-size: 14px;
        }
        
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-box {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            background-color: #f9f9f9;
        }
        
        .info-box h3 {
            font-size: 14px;
            color: #2c3e50;
            margin-top: 0;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .info-label {
            font-weight: bold;
            color: #555;
        }
        
        .info-value {
            color: #333;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .items-table th {
            background-color: #2c3e50;
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 11px;
            border: 1px solid #2c3e50;
        }
        
        .items-table td {
            padding: 8px;
            border: 1px solid #ddd;
            font-size: 11px;
        }
        
        .items-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .items-table tr:hover {
            background-color: #f5f5f5;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .bold {
            font-weight: bold;
        }
        
        .totals-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-box {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            background-color: #f8f9fa;
        }
        
        .summary-box h3 {
            font-size: 14px;
            color: #2c3e50;
            margin-top: 0;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #eee;
        }
        
        .summary-row.total {
            border-bottom: 2px solid #2c3e50;
            font-weight: bold;
            font-size: 14px;
        }
        
        .signature-section {
            margin-top: 50px;
            page-break-inside: avoid;
        }
        
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 20px;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            padding-top: 10px;
            text-align: center;
        }
        
        .signature-label {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .signature-date {
            font-size: 11px;
            color: #666;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            font-size: 10px;
            color: #666;
            text-align: center;
        }
        
        .notes-section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .notes-box {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            background-color: #f9f9f9;
        }
        
        .notes-label {
            font-weight: bold;
            color: #555;
            margin-bottom: 10px;
        }
        
        .notes-content {
            white-space: pre-wrap;
            line-height: 1.6;
        }
        
        .print-meta {
            font-size: 10px;
            color: #999;
            text-align: center;
            margin-bottom: 20px;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            
            body {
                font-size: 11px;
            }
            
            .container {
                padding: 0;
            }
            
            .info-section {
                page-break-inside: avoid;
            }
            
            .items-table {
                page-break-inside: auto;
            }
            
            .items-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            .signature-section {
                page-break-inside: avoid;
            }
        }
        
        .print-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #ddd;
        }
        
        .print-controls button {
            display: block;
            width: 100%;
            margin-bottom: 10px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .print-controls button:hover {
            background: #0056b3;
        }
        
        .print-controls button:last-child {
            margin-bottom: 0;
            background: #6c757d;
        }
        
        .print-controls button:last-child:hover {
            background: #545b62;
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <button onclick="window.print()">
            <i class="fas fa-print"></i> Print Document
        </button>
        <button onclick="window.close()">
            <i class="fas fa-times"></i> Close Window
        </button>
    </div>
    
    <div class="container">
        <div class="print-meta">
            Document ID: TRANSFER-<?php echo strtoupper(uniqid()); ?> | Generated on: <?php echo date('Y-m-d H:i:s'); ?>
        </div>
        
        <div class="header">
            <div class="company-info">
                <div class="company-name">
                    <?php echo htmlspecialchars($company_info['company_name'] ?? 'INVENTORY SYSTEM'); ?>
                </div>
                <div class="company-details">
                    <?php if (!empty($company_info['company_address'])): ?>
                        <?php echo htmlspecialchars($company_info['company_address']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($company_info['company_phone'])): ?>
                        Tel: <?php echo htmlspecialchars($company_info['company_phone']); ?> | 
                    <?php endif; ?>
                    <?php if (!empty($company_info['company_email'])): ?>
                        Email: <?php echo htmlspecialchars($company_info['company_email']); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="document-title">
                <h1>INVENTORY TRANSFER</h1>
                <div class="badge">
                    <?php echo $status_display; ?>
                </div>
                <div style="margin-top: 10px; font-size: 14px;">
                    #<?php echo htmlspecialchars($transfer['transfer_number']); ?>
                </div>
            </div>
        </div>
        
        <div class="info-section">
            <div class="info-box">
                <h3>TRANSFER INFORMATION</h3>
                <div class="info-row">
                    <span class="info-label">Transfer Number:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['transfer_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="info-value"><?php echo $status_display; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Transfer Date:</span>
                    <span class="info-value">
                        <?php if ($transfer['transfer_date']): ?>
                            <?php echo date('F j, Y H:i', strtotime($transfer['transfer_date'])); ?>
                        <?php else: ?>
                            Not set
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Requested By:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['requested_by_name']); ?></span>
                </div>
            </div>
            
            <div class="info-box">
                <h3>SOURCE LOCATION</h3>
                <div class="info-row">
                    <span class="info-label">Type:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['from_location_type']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['from_location_name']); ?></span>
                </div>
                <?php if (!empty($transfer['from_location_address'])): ?>
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['from_location_address']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transfer['from_location_phone'])): ?>
                <div class="info-row">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['from_location_phone']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <h3>DESTINATION LOCATION</h3>
                <div class="info-row">
                    <span class="info-label">Type:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['to_location_type']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['to_location_name']); ?></span>
                </div>
                <?php if (!empty($transfer['to_location_address'])): ?>
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['to_location_address']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transfer['to_location_phone'])): ?>
                <div class="info-row">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transfer['to_location_phone']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($transfer['notes'])): ?>
        <div class="notes-section">
            <div class="notes-box">
                <div class="notes-label">TRANSFER NOTES:</div>
                <div class="notes-content"><?php echo nl2br(htmlspecialchars($transfer['notes'])); ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 25%;">ITEM DESCRIPTION</th>
                    <th style="width: 15%;">ITEM CODE</th>
                    <th style="width: 10%;" class="text-center">UNIT</th>
                    <th style="width: 15%;" class="text-center">QUANTITY</th>
                    <th style="width: 15%;" class="text-center">SENT</th>
                    <th style="width: 15%;" class="text-center">RECEIVED</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transfer_items as $index => $item): ?>
                <tr>
                    <td class="text-center"><?php echo $index + 1; ?></td>
                    <td>
                        <div class="bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                        <?php if (!empty($item['notes'])): ?>
                        <div style="font-size: 10px; color: #666; margin-top: 2px;">
                            <i>Note: <?php echo truncate(htmlspecialchars($item['notes']), 50); ?></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($item['item_unit_measure']); ?></td>
                    <td class="text-center bold"><?php echo number_format($item['quantity'], 2); ?></td>
                    <td class="text-center">
                        <span class="<?php echo $item['quantity_sent'] == $item['quantity'] ? 'text-success bold' : ($item['quantity_sent'] > 0 ? 'text-warning' : 'text-muted'); ?>">
                            <?php echo number_format($item['quantity_sent'], 2); ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="<?php echo $item['quantity_received'] == $item['quantity'] ? 'text-success bold' : ($item['quantity_received'] > 0 ? 'text-warning' : 'text-muted'); ?>">
                            <?php echo number_format($item['quantity_received'], 2); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($transfer_items)): ?>
                <tr>
                    <td colspan="7" class="text-center" style="padding: 30px;">
                        <em>No items in this transfer</em>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="totals-section">
            <div class="summary-box">
                <h3>TRANSFER SUMMARY</h3>
                <div class="summary-row">
                    <span>Total Items:</span>
                    <span class="bold"><?php echo $total_items; ?></span>
                </div>
                <div class="summary-row">
                    <span>Total Quantity:</span>
                    <span class="bold"><?php echo number_format($total_quantity, 2); ?> units</span>
                </div>
                <div class="summary-row">
                    <span>Created:</span>
                    <span><?php echo date('F j, Y', strtotime($transfer['transfer_date'])); ?></span>
                </div>
                <div class="summary-row">
                    <span>Last Updated:</span>
                    <span>
                        <?php if ($transfer['updated_at']): ?>
                            <?php echo date('F j, Y H:i', strtotime($transfer['updated_at'])); ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            
            <div class="summary-box">
                <h3>ITEM STATUS BREAKDOWN</h3>
                <?php
                // Calculate item status
                $fully_received = 0;
                $in_transit = 0;
                $partially_sent = 0;
                $pending = 0;
                
                foreach ($transfer_items as $item) {
                    if ($item['quantity_received'] == $item['quantity']) {
                        $fully_received++;
                    } elseif ($item['quantity_sent'] == $item['quantity']) {
                        $in_transit++;
                    } elseif ($item['quantity_sent'] > 0) {
                        $partially_sent++;
                    } else {
                        $pending++;
                    }
                }
                ?>
                <div class="summary-row">
                    <span>Fully Received:</span>
                    <span class="bold text-success"><?php echo $fully_received; ?> items</span>
                </div>
                <div class="summary-row">
                    <span>In Transit:</span>
                    <span class="bold text-info"><?php echo $in_transit; ?> items</span>
                </div>
                <div class="summary-row">
                    <span>Partially Sent:</span>
                    <span class="bold text-warning"><?php echo $partially_sent; ?> items</span>
                </div>
                <div class="summary-row">
                    <span>Pending:</span>
                    <span class="bold text-muted"><?php echo $pending; ?> items</span>
                </div>
            </div>
        </div>
        
        <div class="signature-section">
            <div class="signature-grid">
                <div class="signature-line">
                    <div class="signature-label">PREPARED BY</div>
                    <div style="margin-top: 20px;">_________________________</div>
                    <div class="signature-date">Signature & Date</div>
                </div>
                
                <div class="signature-line">
                    <div class="signature-label">RECEIVED BY</div>
                    <div style="margin-top: 20px;">_________________________</div>
                    <div class="signature-date">Signature & Date</div>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>
                This is an official document generated by the Inventory Management System.<br>
                Document: Transfer #<?php echo htmlspecialchars($transfer['transfer_number']); ?> | 
                Page 1 of 1 | 
                Printed on <?php echo date('F j, Y H:i:s'); ?>
            </p>
            <p>
                <em>Keep this document for your records. For any questions, contact the inventory department.</em>
            </p>
        </div>
    </div>
    
    <script>
    // Auto-print on page load if requested
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('autoprint') === '1') {
        window.print();
    }
    
    // Close window after printing
    window.onafterprint = function() {
        if (urlParams.get('autoprint') === '1') {
            setTimeout(function() {
                window.close();
            }, 500);
        }
    };
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + P to print
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
        // Escape to close
        if (e.key === 'Escape') {
            window.close();
        }
    });
    
    // Function to truncate text
    function truncate(text, length) {
        if (text.length <= length) return text;
        return text.substr(0, length) + '...';
    }
    </script>
</body>
</html>
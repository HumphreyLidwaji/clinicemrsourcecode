<?php
// Add these cases to your existing post.php switch statement

case 'create_bulk_invoice':
    $patient_id = intval($_POST['patient_id']);
    $orders = json_decode($_POST['orders'], true);
    $created_by = intval($_SESSION['user_id']);
    
    try {
        $mysqli->begin_transaction();
        
        // Generate invoice number
        $invoice_number = generateInvoiceNumber($mysqli);
        
        // Calculate total amount
        $total_amount = 0;
        $invoice_items = [];
        
        foreach ($orders as $order) {
            $order_type = sanitizeInput($order['order_type']);
            $order_id = intval($order['order_id']);
            
            // Get order details and amount based on type
            $order_data = getOrderDetails($mysqli, $order_type, $order_id);
            $total_amount += $order_data['amount'];
            $invoice_items[] = $order_data;
        }
        
        // Create invoice
        $invoice_sql = "INSERT INTO invoices 
                       (invoice_number, invoice_status, invoice_type, invoice_date, 
                        invoice_due, invoice_amount, invoice_currency_code, 
                        invoice_client_id, created_by)
                       VALUES (?, 'draft', 'patient_self_pay', CURDATE(), 
                               DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, 'KSH',
                               ?, ?)";
        
        $stmt = $mysqli->prepare($invoice_sql);
        $stmt->bind_param('siii', $invoice_number, $total_amount, $patient_id, $created_by);
        $stmt->execute();
        $invoice_id = $mysqli->insert_id;
        
        // Add invoice items
        foreach ($invoice_items as $item) {
            $item_sql = "INSERT INTO invoice_items 
                        (item_name, item_description, item_quantity, item_price, 
                         item_subtotal, item_total, item_invoice_id, service_id)
                        VALUES (?, ?, 1, ?, ?, ?, ?, ?)";
            
            $stmt = $mysqli->prepare($item_sql);
            $stmt->bind_param('ssdddii', 
                $item['name'], 
                $item['description'], 
                $item['amount'], 
                $item['amount'], 
                $item['amount'], 
                $invoice_id, 
                $item['service_id']
            );
            $stmt->execute();
        }
        
        // Update billing queue
        foreach ($orders as $order) {
            $update_sql = "UPDATE billing_queue 
                          SET status = 'billed', billed_at = NOW(), invoice_id = ?
                          WHERE order_type = ? AND order_id = ?";
            
            $stmt = $mysqli->prepare($update_sql);
            $stmt->bind_param('isi', $invoice_id, $order['order_type'], $order['order_id']);
            $stmt->execute();
        }
        
        $mysqli->commit();
        
        echo json_encode([
            'success' => true,
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'message' => 'Invoice created successfully'
        ]);
        
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Error creating invoice: ' . $e->getMessage()
        ]);
    }
    break;

case 'create_single_invoice':
    // Similar logic but for single order
    break;

case 'cancel_billing_order':
    $billing_queue_id = intval($_POST['billing_queue_id']);
    
    $sql = "UPDATE billing_queue SET status = 'cancelled' WHERE billing_queue_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $billing_queue_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Billing order cancelled']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error cancelling billing order']);
    }
    break;

// Helper functions
function generateInvoiceNumber($mysqli) {
    $prefix = "INV";
    $year = date('Y');
    $month = date('m');
    
    $last_invoice = $mysqli->query(
        "SELECT invoice_number FROM invoices 
         WHERE YEAR(invoice_date) = YEAR(CURDATE())
         ORDER BY invoice_id DESC LIMIT 1"
    )->fetch_assoc();
    
    $sequence = $last_invoice ? intval(substr($last_invoice['invoice_number'], -4)) + 1 : 1;
    
    return $prefix . $year . $month . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

function getOrderDetails($mysqli, $order_type, $order_id) {
    switch ($order_type) {
        case 'lab':
            $sql = "SELECT lt.test_name as name, 'Laboratory Test' as description, 
                           COALESCE(sp.price, 0) as amount, lt.test_id as service_id
                    FROM lab_order_tests lot
                    JOIN lab_tests lt ON lot.test_id = lt.test_id
                    LEFT JOIN service_prices sp ON sp.service_id = lt.test_id AND sp.service_type = 'lab_test'
                    WHERE lot.lab_order_id = ?";
            break;
        case 'radiology':
            $sql = "SELECT im.study_name as name, 'Radiology Study' as description, 
                           COALESCE(sp.price, 0) as amount, im.imaging_id as service_id
                    FROM radiology_order_studies ros
                    JOIN imaging_studies im ON ros.imaging_id = im.imaging_id
                    LEFT JOIN service_prices sp ON sp.service_id = im.imaging_id AND sp.service_type = 'radiology'
                    WHERE ros.radiology_order_id = ?";
            break;
        // Add cases for service and prescription orders
        default:
            return ['name' => 'Service', 'description' => 'Medical Service', 'amount' => 0, 'service_id' => 0];
    }
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc() ?? ['name' => 'Unknown', 'description' => 'Service', 'amount' => 0, 'service_id' => 0];
}
?>
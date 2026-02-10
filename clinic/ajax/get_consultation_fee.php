<?php
  $inc_all_path = __DIR__ . '/../includes/db_connect.php';

try {
    // Validate parameters
    if (!isset($_GET['department_id']) || !isset($_GET['visit_type'])) {
        sendJsonResponse([
            'success' => false, 
            'message' => 'Department ID and Visit Type are required'
        ]);
    }
    
    $department_id = intval($_GET['department_id']);
    $visit_type = trim($_GET['visit_type']);
    
    // First, try to find a department-specific service for this visit type
    // Removed service_type condition to work with existing table
    $sql = "SELECT service_fee, service_name, service_description 
            FROM services 
            WHERE visit_type = ? 
            AND service_department_id = ? 
            AND service_archived_at IS NULL 
            ORDER BY service_fee ASC 
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("si", $visit_type, $department_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Found department-specific consultation fee
        $service = $result->fetch_assoc();
        $fee = floatval($service['service_fee']);
        $service_name = $service['service_name'];
        
        $stmt->close();
        
        sendJsonResponse([
            'success' => true,
            'fee' => number_format($fee, 2),
            'service_name' => $service_name,
            'fee_type' => 'department_specific',
            'department_id' => $department_id,
            'visit_type' => $visit_type
        ]);
    }
    
    $stmt->close();
    
    // If no department-specific fee found, try general consultation for this visit type
    $sql = "SELECT service_fee, service_name 
            FROM services 
            WHERE visit_type = ? 
            AND service_department_id IS NULL 
            AND service_archived_at IS NULL 
            ORDER BY service_fee ASC 
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $visit_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Found general consultation fee for this visit type
        $service = $result->fetch_assoc();
        $fee = floatval($service['service_fee']);
        $service_name = $service['service_name'];
        
        $stmt->close();
        
        sendJsonResponse([
            'success' => true,
            'fee' => number_format($fee, 2),
            'service_name' => $service_name,
            'fee_type' => 'general',
            'department_id' => $department_id,
            'visit_type' => $visit_type
        ]);
    }
    
    $stmt->close();
    
    // If no specific fee found, use default fees as fallback
    $default_fees = [
        'OPD' => 50.00,
        'IPD' => 100.00,
        'ER' => 150.00,
        'CHECKUP' => 75.00,
        'FOLLOWUP' => 40.00,
        'CONSULTATION' => 60.00
    ];
    
    $fee = $default_fees[$visit_type] ?? 50.00;
    
    sendJsonResponse([
        'success' => true,
        'fee' => number_format($fee, 2),
        'service_name' => $visit_type . ' Consultation',
        'fee_type' => 'default',
        'department_id' => $department_id,
        'visit_type' => $visit_type
    ]);
    
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}


$mysqli->close();
?>
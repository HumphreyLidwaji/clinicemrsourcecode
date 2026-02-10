<?php
// get_wards.php
error_reporting(0); // Turn off error reporting for production

// Define absolute path to includes
$doc_root = $_SERVER['DOCUMENT_ROOT'];
$inc_path = $doc_root . '/includes/inc_all.php';

// Check if file exists
if (!file_exists($inc_path)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Configuration file not found']);
    exit;
}

// Include the configuration file
require_once $inc_path;

// Set headers first to ensure JSON response
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Start output buffering
ob_start();

try {
    // Query to get all wards
    $sql = "SELECT ward_id, ward_name, ward_capacity, ward_type, ward_status 
            FROM wards 
            WHERE ward_archived_at IS NULL 
            ORDER BY ward_name ASC";
    
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Database query preparation failed: ' . $mysqli->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $wards = [];
    while ($row = $result->fetch_assoc()) {
        $wards[] = $row;
    }
    
    $response = [
        'success' => true,
        'wards' => $wards,
        'count' => count($wards)
    ];
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'wards' => []
    ];
}

// Clean any output that might have been generated
ob_end_clean();

// Output JSON response
echo json_encode($response);
exit;
?>
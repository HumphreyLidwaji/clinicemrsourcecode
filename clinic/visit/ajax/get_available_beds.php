<?php
// get_available_beds.php
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
    if (!isset($_GET['ward_id']) || empty($_GET['ward_id'])) {
        throw new Exception('Ward ID is required');
    }
    
    $ward_id = intval($_GET['ward_id']);
    
    if ($ward_id <= 0) {
        throw new Exception('Invalid ward ID');
    }
    
    // Query to get beds for this ward
    $sql = "SELECT bed_id, bed_number, bed_type, bed_occupied, bed_status, bed_rate
            FROM beds 
            WHERE bed_ward_id = ? 
            AND bed_archived_at IS NULL
            ORDER BY bed_number ASC";
    
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Database query preparation failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param("i", $ward_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $beds = [];
    while ($row = $result->fetch_assoc()) {
        $beds[] = $row;
    }
    
    $response = [
        'success' => true,
        'beds' => $beds,
        'count' => count($beds),
        'ward_id' => $ward_id
    ];
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'beds' => []
    ];
}

// Clean any output that might have been generated
ob_end_clean();

// Output JSON response
echo json_encode($response);
exit;
?>
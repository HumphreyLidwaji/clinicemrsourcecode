<?php
// ajax/get_available_beds.php

// Start output buffering to catch any stray output
ob_start();

// Enable error reporting but log to file instead of output
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set JSON header
header('Content-Type: application/json');

try {
    // Check if ward_id is provided
    if (!isset($_GET['ward_id']) || empty($_GET['ward_id'])) {
        throw new Exception('Ward ID is required');
    }
    
    $ward_id = intval($_GET['ward_id']);
    
    // Include database connection - adjust path as needed
    $inc_all_path = __DIR__ . '/../includes/db_connect.php';
    
    if (!file_exists($inc_all_path)) {
        throw new Exception('Database configuration file not found');
    }
    
    // Include the file
    require_once $inc_all_path;
    
    // Check database connection
    if (!$mysqli || mysqli_connect_error()) {
        throw new Exception('Database connection failed');
    }
    
    // Simple query to get beds
    $sql = "SELECT bed_id, bed_number, bed_type, bed_occupied 
            FROM beds 
            WHERE bed_ward_id = ? 
            ORDER BY bed_number ASC 
            LIMIT 10"; // Limit for testing
    
    $stmt = mysqli_prepare($mysqli, $sql);
    
    if (!$stmt) {
        throw new Exception('Database query preparation failed');
    }
    
    mysqli_stmt_bind_param($stmt, "i", $ward_id);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Database query execution failed');
    }
    
    $result = mysqli_stmt_get_result($stmt);
    $beds = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $beds[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    
    // Clear any output that might have been generated during processing
    ob_clean();
    
    // Send successful response
    echo json_encode([
        'success' => true,
        'beds' => $beds,
        'count' => count($beds),
        'debug' => [
            'ward_id' => $ward_id,
            'beds_found' => count($beds)
        ]
    ]);
    
} catch (Exception $e) {
    // Clean any output and send error
    ob_clean();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'beds' => []
    ]);
}

// Ensure no other output is sent
exit;
?>
<?php
// ajax/search_services.php - Fixed version with debugging
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json');

try {
    // FIXED: Actually include the database connection file
    require_once __DIR__ . '/../includes/db_connect.php';
    
    // Check if $mysqli is set and valid
    if (!isset($mysqli) || !$mysqli) {
        throw new Exception("Database connection not established");
    }

    $searchTerm = $_GET['q'] ?? '';
    $page = $_GET['page'] ?? 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    error_log("Search term: '$searchTerm', Page: $page");

    // First, let's check if we can connect to the services table
    $testQuery = $mysqli->query("SELECT 1 FROM services LIMIT 1");
    if (!$testQuery) {
        throw new Exception("Services table doesn't exist or can't be accessed: " . $mysqli->error);
    }

    // Build query - simplified without joins first
    if (!empty($searchTerm)) {
        $sql = "SELECT service_id, service_name, service_fee, service_description, 
                       service_duration, visit_type, service_department_id
                FROM services 
                WHERE service_archived_at IS NULL 
                AND (service_name LIKE ? OR service_description LIKE ?)
                ORDER BY service_name ASC 
                LIMIT ? OFFSET ?";

        $stmt = $mysqli->prepare($sql);
        $searchTermLike = "%$searchTerm%";
        $stmt->bind_param("ssii", $searchTermLike, $searchTermLike, $limit, $offset);
    } else {
        // If no search term, return all services
        $sql = "SELECT service_id, service_name, service_fee, service_description, 
                       service_duration, visit_type, service_department_id
                FROM services 
                WHERE service_archived_at IS NULL 
                ORDER BY service_name ASC 
                LIMIT ? OFFSET ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $limit, $offset);
    }

    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $mysqli->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $services = [];
    while ($row = $result->fetch_assoc()) {
        // Get department name if department_id exists
        $department_name = 'General';
        if (!empty($row['service_department_id'])) {
            $deptQuery = $mysqli->prepare("SELECT department_name FROM departments WHERE department_id = ?");
            $deptQuery->bind_param("i", $row['service_department_id']);
            $deptQuery->execute();
            $deptResult = $deptQuery->get_result();
            if ($deptRow = $deptResult->fetch_assoc()) {
                $department_name = $deptRow['department_name'];
            }
            $deptQuery->close();
        }

        $services[] = [
            'id' => $row['service_id'],
            'text' => $row['service_name'] . ' - ' . number_format($row['service_fee'], 2),
            'service_name' => $row['service_name'],
            'service_fee' => floatval($row['service_fee']),
            'service_description' => $row['service_description'],
            'service_duration' => $row['service_duration'],
            'visit_type' => $row['visit_type'],
            'department_name' => $department_name
        ];
    }

    $stmt->close();

    error_log("Found " . count($services) . " services for search: '$searchTerm'");

    // Get total count for pagination
    if (!empty($searchTerm)) {
        $countSql = "SELECT COUNT(*) as total 
                     FROM services 
                     WHERE service_archived_at IS NULL 
                     AND (service_name LIKE ? OR service_description LIKE ?)";
        
        $countStmt = $mysqli->prepare($countSql);
        $countStmt->bind_param("ss", $searchTermLike, $searchTermLike);
    } else {
        $countSql = "SELECT COUNT(*) as total 
                     FROM services 
                     WHERE service_archived_at IS NULL";
        
        $countStmt = $mysqli->prepare($countSql);
    }

    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Format response for Select2
    $response = [
        'results' => $services,
        'pagination' => [
            'more' => ($offset + $limit) < $totalCount
        ]
    ];

    // Clear output buffer and send response
    ob_clean();
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in search_services.php: " . $e->getMessage());
    
    // Clear output buffer and return empty results
    ob_clean();
    echo json_encode([
        'results' => [],
        'pagination' => ['more' => false],
        'error' => $e->getMessage() // Remove this in production
    ]);
}

// Close connection if it exists
if (isset($mysqli) && $mysqli) {
    $mysqli->close();
}

exit;
?>
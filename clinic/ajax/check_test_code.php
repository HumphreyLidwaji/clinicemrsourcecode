<?php
require_once "../includes/inc_all.php";

if (isset($_POST['test_code'])) {
    $test_code = trim($_POST['test_code']);
    
    $stmt = $mysqli->prepare("SELECT test_id FROM lab_tests WHERE test_code = ?");
    $stmt->bind_param("s", $test_code);
    $stmt->execute();
    $stmt->store_result();
    
    header('Content-Type: application/json');
    echo json_encode(['exists' => $stmt->num_rows > 0]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
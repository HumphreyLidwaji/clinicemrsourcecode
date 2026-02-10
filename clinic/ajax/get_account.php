<?php
require_once "../includes/inc_all.php";

if (isset($_GET['id'])) {
    $account_id = intval($_GET['id']);
    $account = $mysqli->query("
        SELECT a.*, at.type_name, at.type_class 
        FROM accounts a 
        LEFT JOIN account_types at ON a.account_type = at.type_id 
        WHERE a.account_id = $account_id
    ")->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode($account);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
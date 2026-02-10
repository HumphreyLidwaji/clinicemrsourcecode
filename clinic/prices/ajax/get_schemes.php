<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

header('Content-Type: application/json');

$company_id = intval($_GET['company_id'] ?? 0);

if ($company_id > 0) {
    $sql = "SELECT scheme_id, scheme_name 
            FROM insurance_schemes 
            WHERE insurance_company_id = ? 
            AND is_active = 1 
            ORDER BY scheme_name";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $schemes = [];
    while ($row = $result->fetch_assoc()) {
        $schemes[] = [
            'scheme_id' => $row['scheme_id'],
            'scheme_name' => $row['scheme_name']
        ];
    }
    
    echo json_encode($schemes);
} else {
    echo json_encode([]);
}
?>
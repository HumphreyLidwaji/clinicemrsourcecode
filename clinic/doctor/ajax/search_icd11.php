<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$searchTerm = $_GET['q'] ?? '';

if (strlen($searchTerm) < 2) {
    echo json_encode(['error' => 'Search term too short']);
    exit;
}

$sql = "SELECT icd_code, title, description 
        FROM icd11_codes 
        WHERE icd_code LIKE ? OR title LIKE ? OR description LIKE ?
        LIMIT 50";
        
$searchTermLike = "%" . $searchTerm . "%";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("sss", $searchTermLike, $searchTermLike, $searchTermLike);
$stmt->execute();
$result = $stmt->get_result();

$codes = [];
while ($row = $result->fetch_assoc()) {
    $codes[] = [
        'icd_code' => $row['icd_code'],
        'title' => $row['title'],
        'description' => $row['description']
    ];
}

header('Content-Type: application/json');
echo json_encode($codes);
?>
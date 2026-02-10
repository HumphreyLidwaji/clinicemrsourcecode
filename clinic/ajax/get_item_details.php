<?php
require_once "../includes/inc_all.php";

header('Content-Type: application/json');

if (!isset($_GET['item_id'])) {
    echo json_encode(['success' => false, 'message' => 'Item ID required']);
    exit;
}

$item_id = intval($_GET['item_id']);

$sql = "SELECT i.item_unit_measure, c.category_name
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
        WHERE i.item_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $item = $result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'unit_measure' => $item['item_unit_measure'],
        'category' => $item['category_name']
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Item not found']);
}

$stmt->close();
?>
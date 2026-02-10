<?php
require_once "../includes/inc_all.php";

if (isset($_GET['template_id'])) {
    $template_id = intval($_GET['template_id']);
    
    $sql = "SELECT * FROM lab_result_templates WHERE template_id = ? AND is_active = 1";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Template not found']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No template ID provided']);
}
?>
<?php
require_once "../includes/inc_all.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    $template_name = sanitizeInput($_POST['template_name']);
    $test_id = intval($_POST['test_id']);
    $result_value = sanitizeInput($_POST['result_value']);
    $result_unit = sanitizeInput($_POST['result_unit']);
    $abnormal_flag = sanitizeInput($_POST['abnormal_flag']);
    $result_notes = sanitizeInput($_POST['result_notes']);
    $instrument_used = sanitizeInput($_POST['instrument_used']);
    $reagent_lot = sanitizeInput($_POST['reagent_lot']);
    
    // Check if template name already exists
    $check_sql = "SELECT template_id FROM lab_result_templates WHERE template_name = ? AND test_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("si", $template_name, $test_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Template name already exists for this test']);
        exit;
    }
    
    // Get category_id from test
    $category_sql = "SELECT category_id FROM lab_tests WHERE test_id = ?";
    $category_stmt = $mysqli->prepare($category_sql);
    $category_stmt->bind_param("i", $test_id);
    $category_stmt->execute();
    $category_result = $category_stmt->get_result()->fetch_assoc();
    $category_id = $category_result['category_id'];
    
    // Insert template
    $insert_sql = "INSERT INTO lab_result_templates SET 
                  template_name = ?,
                  test_id = ?,
                  category_id = ?,
                  result_value = ?,
                  result_unit = ?,
                  abnormal_flag = ?,
                  result_notes = ?,
                  instrument_used = ?,
                  reagent_lot = ?,
                  created_by = ?,
                  created_at = NOW()";
    
    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param(
        "siissssssi",
        $template_name,
        $test_id,
        $category_id,
        $result_value,
        $result_unit,
        $abnormal_flag,
        $result_notes,
        $instrument_used,
        $reagent_lot,
        $session_user_id
    );
    
    if ($insert_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Template saved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving template: ' . $mysqli->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
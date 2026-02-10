<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = intval($_POST['patient_id'] ?? 0);
    
    if ($patient_id <= 0) {
        echo json_encode(['has_active_visit' => false]);
        exit;
    }
    
    // Check if patient has an active visit
    $sql = "SELECT visit_id, visit_number, visit_type, visit_status 
            FROM visits 
            WHERE visit_patient_id = ? 
            AND is_active = 1
            AND visit_status IN ('Active', 'Admitted', 'In Progress')";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $visit = $result->fetch_assoc();
        echo json_encode([
            'has_active_visit' => true,
            'visit_number' => $visit['visit_number'],
            'visit_type' => $visit['visit_type'],
            'visit_status' => $visit['visit_status']
        ]);
    } else {
        echo json_encode(['has_active_visit' => false]);
    }
}
?>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$patient_id = intval($_GET['patient_id'] ?? 0);

if ($patient_id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT sc.case_id, sc.case_number, sc.planned_procedure, sc.pre_op_diagnosis,
               sc.surgery_date, sc.surgery_start_time, sc.case_status,
               u.user_name as surgeon_name,
               t.theatre_number, t.theatre_name
        FROM surgical_cases sc
        LEFT JOIN users u ON sc.primary_surgeon_id = u.user_id
        LEFT JOIN theatres t ON sc.theater_id = t.theatre_id
        WHERE sc.patient_id = ?
        AND sc.case_status IN ('scheduled', 'referred', 'in_or')
        AND (sc.surgery_date >= CURDATE() OR sc.surgery_date IS NULL)
        ORDER BY sc.surgery_date, sc.surgery_start_time";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

$cases = [];
while ($row = $result->fetch_assoc()) {
    $cases[] = $row;
}

echo json_encode($cases);
?>
<?php
require_once '../includes/inc_all.php';

if ($_POST) {
    $report_type = sanitizeInput($_POST['report_type']);
    $report_period = sanitizeInput($_POST['report_period']);
    $facility_code = sanitizeInput($_POST['facility_code']);
    
    // Insert into MOH report logs
    $sql = "INSERT INTO moh_report_logs (report_type, report_period, facility_code, generated_by, status, submission_date) 
            VALUES (?, ?, ?, ?, 'submitted', NOW())";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('sssi', $report_type, $report_period, $facility_code, $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
}
?>
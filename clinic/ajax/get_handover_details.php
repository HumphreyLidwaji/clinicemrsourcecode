<?php
require_once "../includes/db_connect.php";

if (isset($_GET['handover_id'])) {
    $handover_id = intval($_GET['handover_id']);
    
    $sql = "SELECT nh.*, u1.user_name as from_nurse_name, u2.user_name as to_nurse_name
            FROM nursing_handovers nh
            JOIN users u1 ON nh.from_nurse_id = u1.user_id
            JOIN users u2 ON nh.to_nurse_id = u2.user_id
            WHERE nh.handover_id = ?";
    
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, "i", $handover_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $handover = mysqli_fetch_assoc($result);
    
    if ($handover) {
        echo json_encode([
            'success' => true,
            'handover' => $handover
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Handover not found'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No handover ID provided'
    ]);
}
?>
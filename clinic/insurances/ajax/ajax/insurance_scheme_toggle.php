<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (isset($_POST['id'])) {
    $scheme_id = intval($_POST['id']);
    
    // Get current status
    $scheme_sql = mysqli_query($mysqli, "SELECT is_active FROM insurance_schemes WHERE scheme_id = $scheme_id");
    if (mysqli_num_rows($scheme_sql) > 0) {
        $scheme = mysqli_fetch_assoc($scheme_sql);
        $new_status = $scheme['is_active'] ? 0 : 1;
        
        $update_sql = mysqli_query($mysqli, "UPDATE insurance_schemes SET is_active = $new_status WHERE scheme_id = $scheme_id");
        
        echo json_encode(['success' => true, 'new_status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Scheme not found']);
    }
}
?>
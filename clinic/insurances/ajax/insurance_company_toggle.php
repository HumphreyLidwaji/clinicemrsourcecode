<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (isset($_POST['id'])) {
    $company_id = intval($_POST['id']);
    
    // Get current status
    $company_sql = mysqli_query($mysqli, "SELECT is_active FROM insurance_companies WHERE insurance_company_id = $company_id");
    if (mysqli_num_rows($company_sql) > 0) {
        $company = mysqli_fetch_assoc($company_sql);
        $new_status = $company['is_active'] ? 0 : 1;
        
        $update_sql = mysqli_query($mysqli, "UPDATE insurance_companies SET is_active = $new_status WHERE insurance_company_id = $company_id");
        
        echo json_encode(['success' => true, 'new_status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Company not found']);
    }
}
?>
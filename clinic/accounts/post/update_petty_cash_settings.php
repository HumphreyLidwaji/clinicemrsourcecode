<?php
require_once '../includes/inc_all.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $max_amount = floatval($_POST['max_transaction_amount']);
    $threshold = floatval($_POST['replenish_threshold']);
    $default_category = intval($_POST['default_category']);

    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: ../petty_cash.php");
        exit;
    }

    // Update settings in database
    $settings = [
        'petty_cash_max_amount' => $max_amount,
        'petty_cash_replenish_threshold' => $threshold,
        'petty_cash_default_category' => $default_category
    ];

    foreach ($settings as $key => $value) {
        $update_sql = "INSERT INTO system_settings (setting_key, setting_value, updated_by) 
                      VALUES (?, ?, ?) 
                      ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?";
        $stmt = $mysqli->prepare($update_sql);
        $stmt->bind_param("ssisi", $key, $value, $session_user_id, $value, $session_user_id);
        $stmt->execute();
    }

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Petty cash settings updated successfully!";
    header("Location: ../petty_cash.php");
    exit;
}
?>
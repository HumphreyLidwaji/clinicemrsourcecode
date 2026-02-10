<?php
// logout.php
session_start();

// Log logout safely
if (!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . "/config.php";
        require_once __DIR__ . "/functions.php";
        require_once __DIR__ . "/includes/audit_functions.php";

        // Existing logging
        if (function_exists('logAction')) {
            logAction("Login", "Logout", "User logged out", 0, $_SESSION['user_id']);
        }
        
        // AUDIT LOGGING - LOGOUT
        if (function_exists('audit_log') && isset($mysqli)) {
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'LOGOUT',
                'module'      => 'AUTHENTICATION',
                'table_name'  => 'users',
                'entity_type' => 'user',
                'record_id'   => $_SESSION['user_id'],
                'description' => "User {$_SESSION['user_name']}  ({$_SESSION['user_email'] }) logged out",
                'status'      => 'SUCCESS'
            ]);
        }
    } catch (Throwable $e) {
        // Fail silently – logout must continue
        error_log("Logout error: " . $e->getMessage());
    }
}

// Destroy session properly
$_SESSION = [];
session_unset();
session_destroy();

// Prevent cache issues
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

// Redirect
header("Location: login.php");
exit;
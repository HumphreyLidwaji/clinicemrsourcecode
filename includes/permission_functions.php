<?php
// includes/permission_functions.php

function enforcePermission($permission) {
    if (!SimplePermission::can($permission)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = "You don't have permission to access this resource.";
        header("Location: /clinic/dashboard.php");
        exit;
    }
}

function requireAnyPermission($permissions) {
    if (!SimplePermission::any($permissions)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = "You don't have permission to access this resource.";
        header("Location: /clinic/dashboard.php");
        exit;
    }
}

function requireAllPermissions($permissions) {
    if (!SimplePermission::all($permissions)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = "You don't have all required permissions to access this resource.";
        header("Location: /clinic/dashboard.php");
        exit;
    }
}
?>
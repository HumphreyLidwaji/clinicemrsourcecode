<?php

// Check if the app is set up
if (file_exists("config.php")) {
    require_once "config.php";

    // Check if setup is enabled
    if (!isset($config_enable_setup) || $config_enable_setup == 1) {
        header("Location: /setup");
        exit();
    }

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check if user is logged in and valid
    if (isset($_SESSION['logged']) && $_SESSION['logged'] === true && isset($_SESSION['user_id'])) {
        
        // Optional: Validate user still exists and is active
        //require_once "functions.php";
        $mysqli = connectToDatabase();
        
        $user_id = intval($_SESSION['user_id']);
        $sql = "SELECT user_status FROM users WHERE user_id = $user_id ";
        $result = mysqli_query($mysqli, $sql);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);
            if ($user['user_status'] == 1) {
                // Valid active user - redirect to dashboard
                header("Location: /clinic/dashboard.php");
                exit();
            }
        }
        
        // If user validation fails, clear invalid session
        session_unset();
        session_destroy();
    }

    // Not logged in or invalid session
    header("Location: login.php");
    exit();

} else {
    // Config doesn't exist
    header("Location: /setup");
    exit();
}
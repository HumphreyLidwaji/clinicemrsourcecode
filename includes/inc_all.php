<?php
// Start output buffering at the VERY beginning
if (ob_get_level()) ob_end_clean();
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Configuration & core
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

// Page setup
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/page_title.php';

// Layout UI
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/top_nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';


// Wrapper & alerts
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_wrapper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_alert_feedback.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';


require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/side_nav.php';
//permisson
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permission_functions.php';




// Check if user is logged in
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
    header("Location: /login.php");
    exit;
}



// Set session variables
$session_user_id = intval($_SESSION['user_id']);
$session_name = sanitizeInput($_SESSION['user_name']);
$session_email = sanitizeInput($_SESSION['user_email']);
$session_user_role = intval($_SESSION['user_role_id']);
$session_is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// Load user permissions
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/Permission.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permission_functions.php';
SimplePermission::init($session_user_id);

// Load company settings
$sql_settings = mysqli_query($mysqli, "SELECT * FROM settings LEFT JOIN companies ON settings.company_id = companies.company_id WHERE settings.company_id = 1");
$row = mysqli_fetch_array($sql_settings);

$company_name = $row['company_name'];
$company_logo = $row['company_logo'];
$config_theme = $row['config_theme'];
//$config_base_url = $row['config_base_url'];

// Check if user is active
$sql_user = mysqli_query($mysqli, "SELECT user_status FROM users WHERE user_id = $session_user_id");
$user_row = mysqli_fetch_array($sql_user);

if ($user_row['user_status'] != 1) {
    session_unset();
    session_destroy();
    header("Location: /login.php");
    exit;
}
?>


<?php

$session_ip = sanitizeInput(getIP());
$session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT']);
$session_user_id = intval($_SESSION['user_id']);

// Check if user is logged in
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
    session_unset();
    session_destroy();
    header("Location: login.php?expired=1");
    exit;
}

// First, let's get the user's role ID separately to avoid complex joins
$role_sql = mysqli_query(
    $mysqli,
    "SELECT urp.role_id 
     FROM user_role_permissions urp 
     WHERE urp.user_id = $session_user_id AND urp.is_active = 1"
);

$role_row = mysqli_fetch_assoc($role_sql);
$user_role_id = $role_row['role_id'] ?? 0;

// Now get user details with permissions
$sql = mysqli_query(
    $mysqli,
    "SELECT u.*, us.*, ur.role_name, ur.role_description,
            GROUP_CONCAT(DISTINCT p.permission_name) as user_permissions,
            EXISTS(
                SELECT 1 FROM role_permissions rp 
                JOIN permissions p2 ON rp.permission_id = p2.permission_id 
                WHERE rp.role_id = $user_role_id AND p2.permission_name = '*' AND rp.permission_value = 1
            ) as role_is_admin
     FROM users u
     LEFT JOIN user_settings us ON u.user_id = us.user_id
     LEFT JOIN user_roles ur ON ur.role_id = $user_role_id
     LEFT JOIN role_permissions rp ON ur.role_id = rp.role_id AND rp.permission_value = 1
     LEFT JOIN permissions p ON rp.permission_id = p.permission_id
     WHERE u.user_id = $session_user_id
     GROUP BY u.user_id"
);

if (!$sql || mysqli_num_rows($sql) === 0) {
    // User not found or invalid session
    session_unset();
    session_destroy();
    header("Location: login.php?expired=1");
    exit;
}

$row = mysqli_fetch_array($sql);

$session_name = sanitizeInput($row['user_name']);
$session_email = $row['user_email'];
$session_avatar = $row['user_avatar'];
$session_token = $row['user_token'];
$session_user_type = intval($row['user_type']);
$session_user_role = $user_role_id; // Use the role ID we got separately
$session_user_role_display = sanitizeInput($row['role_name']);
$session_is_admin = isset($row['role_is_admin']) && $row['role_is_admin'] == 1;
$session_user_config_force_mfa = intval($row['user_config_force_mfa']);
$user_config_records_per_page = intval($row['user_config_records_per_page']);
$user_config_theme_dark = intval($row['user_config_theme_dark']);

// Check user type - removed the redirect since this might be causing issues
// if ($session_user_type !== 1) {
//     session_unset();
//     session_destroy();
//     redirect("/client/login.php");
// }

/* Load user client permissions
$client_access_array = [];
if (!$session_is_admin) {
    $user_client_access_sql = "SELECT client_id FROM user_client_permissions WHERE user_id = $session_user_id";
    $user_client_access_result = mysqli_query($mysqli, $user_client_access_sql);
    
    if ($user_client_access_result) {
        while ($client_row = mysqli_fetch_assoc($user_client_access_result)) {
            $client_access_array[] = intval($client_row['client_id']);
        }
    }
}

$client_access_string = implode(',', $client_access_array);
$access_permission_query = "";
if ($client_access_string && !$session_is_admin) {
    $access_permission_query = "AND clients.client_id IN ($client_access_string)";
}
*/
// Store permissions in session for easy access
if (!empty($row['user_permissions'])) {
    $_SESSION['user_permissions'] = explode(',', $row['user_permissions']);
} else {
    $_SESSION['user_permissions'] = [];
}

// If user has wildcard permission, add it to session
if ($session_is_admin) {
    $_SESSION['user_permissions'][] = '*';
}
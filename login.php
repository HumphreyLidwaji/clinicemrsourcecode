<?php
// ============================
// DEBUG (disable in production)
// ============================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================
// Bootstrap
// ============================
ob_start();
session_start();

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/includes/audit_functions.php"; // Added audit functions

// ============================
// Redirect if already logged in
// ============================
if (!empty($_SESSION['logged']) && $_SESSION['logged'] === true) {
    header("Location: /clinic/dashboard.php");
    exit;
}

// ============================
// Handle login POST
// ============================
$error_message = null;

if (isset($_POST['login'])) {

    $email    = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // ----------------------------
    // Fetch user
    // ----------------------------
    $stmt = $mysqli->prepare("
        SELECT user_id, user_name, user_password
        FROM users
        WHERE user_email = ?
          AND user_status = 1
          AND user_archived_at IS NULL
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();
    $stmt->close();

    // ----------------------------
    // Validate credentials
    // ----------------------------
    if ($user && password_verify($password, $user['user_password'])) {

        $user_id   = (int)$user['user_id'];
        $user_name = sanitizeInput($user['user_name']);

        // ----------------------------
        // Get role
        // ----------------------------
        $role_id   = 0;
        $role_name = 'No Role';

        $role_stmt = $mysqli->prepare("
            SELECT ur.role_id, ur.role_name
            FROM user_role_permissions urp
            JOIN user_roles ur ON urp.role_id = ur.role_id
            WHERE urp.user_id = ?
              AND urp.is_active = 1
            LIMIT 1
        ");
        $role_stmt->bind_param("i", $user_id);
        $role_stmt->execute();
        $role_result = $role_stmt->get_result();

        if ($role = $role_result->fetch_assoc()) {
            $role_id   = (int)$role['role_id'];
            $role_name = $role['role_name'];
        }
        $role_stmt->close();

        // ----------------------------
        // Admin permission check
        // ----------------------------
        $is_admin = false;

        if ($role_id > 0) {
            $admin_stmt = $mysqli->prepare("
                SELECT 1
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.permission_id
                WHERE rp.role_id = ?
                  AND p.permission_name = '*'
                  AND rp.permission_value = 1
                LIMIT 1
            ");
            $admin_stmt->bind_param("i", $role_id);
            $admin_stmt->execute();
            $admin_stmt->store_result();

            if ($admin_stmt->num_rows > 0) {
                $is_admin = true;
            }
            $admin_stmt->close();
        }

        // ----------------------------
        // Session setup
        // ----------------------------
        session_regenerate_id(true);

        $_SESSION = [
            'logged'          => true,
            'user_id'         => $user_id,
            'user_name'       => $user_name,
            'user_email'      => $email,
            'user_role_id'    => $role_id,
            'user_role_name'  => $role_name,
            'is_admin'        => $is_admin,
            'csrf_token'      => bin2hex(random_bytes(32))
        ];

        // ----------------------------
        // Update last login
        // ----------------------------
        $update_stmt = $mysqli->prepare("
            UPDATE users SET user_last_login = NOW() WHERE user_id = ?
        ");
        $update_stmt->bind_param("i", $user_id);
        $update_stmt->execute();
        $update_stmt->close();

        // ----------------------------
        // Log success (NON-BLOCKING)
        // ----------------------------
        try {
            logAction("Login", "Success", "$user_name logged in", 0, $user_id);
        } catch (Throwable $e) {
            error_log("Login log failed: " . $e->getMessage());
        }

        // ----------------------------
        // AUDIT LOGGING - SUCCESSFUL LOGIN
        // ----------------------------
        try {
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $role_name,
                'action'      => 'LOGIN',
                'module'      => 'AUTHENTICATION',
                'table_name'  => 'users',
                'entity_type' => 'user',
                'record_id'   => $user_id,
                'description' => "User {$user_name} ({$email}) logged in successfully",
                'status'      => 'SUCCESS'
            ]);
        } catch (Throwable $e) {
            error_log("Audit log failed for successful login: " . $e->getMessage());
        }

        // ----------------------------
        // Redirect
        // ----------------------------
        ob_end_clean();
        header("Location: /clinic/dashboard.php");
        exit;
    }

    // ----------------------------
    // Failed login
    // ----------------------------
    $error_message = "Incorrect email or password.";

    try {
        logAction("Login", "Failed", "Failed login attempt: $email");
    } catch (Throwable $e) {
        error_log("Failed login log error: " . $e->getMessage());
    }

    // ----------------------------
    // AUDIT LOGGING - FAILED LOGIN
    // ----------------------------
    try {
        $failed_user_id = $user['user_id'] ?? null;
        $failed_role = $user ? 'UNKNOWN' : 'NOT_FOUND';
        
        audit_log($mysqli, [
            'user_id'     => $failed_user_id,
            'user_role'   => $failed_role,
            'action'      => 'LOGIN',
            'module'      => 'AUTHENTICATION',
            'table_name'  => 'users',
            'entity_type' => 'user',
            'record_id'   => $failed_user_id,
            'description' => "Failed login attempt for email: {$email}",
            'status'      => 'FAILED'
        ]);
    } catch (Throwable $e) {
        error_log("Audit log failed for failed login: " . $e->getMessage());
    }
}

// ============================
// Load system settings
// ============================
$sql = "
    SELECT *
    FROM settings
    LEFT JOIN companies ON settings.company_id = companies.company_id
    WHERE settings.company_id = 1
    LIMIT 1
";
$settings = $mysqli->query($sql)->fetch_assoc();

$company_name = $settings['company_name'] ?? 'ClinicEMR';
$company_logo = $settings['company_logo'] ?? null;
$config_login_message = nullable_htmlentities($settings['config_login_message'] ?? '');
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $company_name; ?> | Login</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="plugins/adminlte/css/adminlte.min.css">
    <style>
    body {
        min-height: 100vh;
        margin: 0;
        background: 
            linear-gradient(rgba(0, 60, 80, 0.65), rgba(0, 60, 80, 0.65)),
            url("uploads/assets/clinic-bg.jpg") no-repeat center center fixed;
        background-size: cover;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: "Source Sans Pro", sans-serif;
    }

    .login-box {
        width: 420px;
    }

    .login-logo img {
        max-height: 90px;
        margin-bottom: 10px;
    }

    .card {
        border-radius: 12px;
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(6px);
        background: rgba(255, 255, 255, 0.95);
    }

    .login-card-body {
        padding: 2.2rem;
    }

    .login-box-msg {
        font-size: 1.1rem;
        margin-bottom: 1.5rem;
        color: #555;
    }

    .form-control {
        height: 48px;
        font-size: 1rem;
    }

    .input-group-text {
        background: #f4f6f9;
    }

    .btn-primary {
        height: 48px;
        font-size: 1.05rem;
        font-weight: 600;
    }

    @media (max-width: 576px) {
        .login-box {
            width: 92%;
        }
    }
</style>


</head>
<body class="hold-transition login-page">

<div class="login-box">
    <div class="login-logo text-center text-white">
        <?php if (!empty($company_logo)) { ?>
            <img alt="<?= $company_name ?> logo" src="uploads/settings/<?php echo $company_logo; ?>" class="img-fluid" style="max-height: 80px;">
        <?php } else { ?>
            <b>ClinicEMR</b>System
        <?php } ?>
    </div>

    <div class="card">
        <div class="card-body login-card-body">

            <?php if (!empty($config_login_message)) { ?>
                <p class="login-box-msg"><?php echo nl2br($config_login_message); ?></p>
            <?php } else { ?>
                <p class="login-box-msg">Sign in to start your session</p>
            <?php } ?>

            <?php if (!empty($error_message)) { ?>
                <div class="alert alert-danger">
                    <i class="icon fas fa-ban"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php } ?>

            <form method="post" autocomplete="on">
                <div class="input-group mb-3">
                    <input type="email" class="form-control" placeholder="Email" 
                           name="email" required autofocus
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-envelope"></span></div>
                    </div>
                </div>

                <div class="input-group mb-3">
                    <input type="password" class="form-control" placeholder="Password" name="password" required>
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-lock"></span></div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <button type="submit" name="login" class="btn btn-primary btn-block">
                            <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                        </button>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
// Prevent form resubmission on refresh
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

</body>
</html>

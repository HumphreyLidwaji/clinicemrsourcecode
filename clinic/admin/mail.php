<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Define APP_VERSION if not already defined
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (isset($_POST['edit_mail_settings'])) {
        validateCSRFToken($csrf_token);

        // Mail settings - use null coalescing to prevent undefined index warnings
        $config_mail_host = sanitizeInput($_POST['config_mail_host'] ?? '');
        $config_mail_port = intval($_POST['config_mail_port'] ?? 587);
        $config_mail_username = sanitizeInput($_POST['config_mail_username'] ?? '');
        $config_mail_password = sanitizeInput($_POST['config_mail_password'] ?? '');
        $config_mail_from_email = sanitizeInput(filter_var($_POST['config_mail_from_email'] ?? '', FILTER_VALIDATE_EMAIL));
        $config_mail_from_name = sanitizeInput($_POST['config_mail_from_name'] ?? '');

        // SMS settings
        $config_sms_provider = sanitizeInput($_POST['config_sms_provider'] ?? '');
        $config_sms_api_key = sanitizeInput($_POST['config_sms_api_key'] ?? '');
        $config_sms_sender_id = sanitizeInput($_POST['config_sms_sender_id'] ?? '');
        $config_sms_username = sanitizeInput($_POST['config_sms_username'] ?? '');
        $config_sms_password = sanitizeInput($_POST['config_sms_password'] ?? '');

        $query = "UPDATE settings SET 
            config_mail_host = '$config_mail_host',
            config_mail_port = $config_mail_port,
            config_mail_username = '$config_mail_username',
            config_mail_password = '$config_mail_password',
            config_mail_from_email = '$config_mail_from_email',
            config_mail_from_name = '$config_mail_from_name',
            config_sms_provider = '$config_sms_provider',
            config_sms_api_key = '$config_sms_api_key',
            config_sms_sender_id = '$config_sms_sender_id',
            config_sms_username = '$config_sms_username',
            config_sms_password = '$config_sms_password'
            WHERE company_id = 1";

        if (mysqli_query($mysqli, $query)) {
            logAction("Settings", "Edit", "$session_name updated mail and SMS settings");
            flash_alert("Mail and SMS settings updated successfully");
        } else {
            // If there's an error, it might be because columns don't exist
            if (mysqli_errno($mysqli) == 1054) { // Unknown column error
                flash_alert("Database columns missing. Please run the SQL update script first.", 'error');
            } else {
                flash_alert("Error updating settings: " . mysqli_error($mysqli), 'error');
            }
        }
        
        header("Location: mail.php");
        exit;

    } elseif (isset($_POST['test_mail'])) {
        validateCSRFToken($csrf_token);

        $test_email = sanitizeInput($_POST['test_email'] ?? '');
        $subject = "Test Email from Clinic EMR";
        $body = "This is a test email from your Clinic EMR system. If you received this, your email settings are working correctly.";

        // Use the posted values or fall back to database values
        $from_email = $_POST['config_mail_from_email'] ?? $config_mail_from_email ?? '';
        $from_name = $_POST['config_mail_from_name'] ?? $config_mail_from_name ?? 'Clinic EMR';

        $data = [
            [
                'from' => $from_email,
                'from_name' => $from_name,
                'recipient' => $test_email,
                'subject' => $subject,
                'body' => $body
            ]
        ];
        
        $mail = addToMailQueue($mysqli, $data);

        if ($mail === true) {
            flash_alert("Test email sent successfully!");
        } else {
            flash_alert("Failed to send test email", 'error');
        }
        
        header("Location: mail.php");
        exit;

    } elseif (isset($_POST['test_sms'])) {
        validateCSRFToken($csrf_token);

        $test_phone = sanitizeInput($_POST['test_phone'] ?? '');
        $message = "Test SMS from Clinic EMR. Settings are working correctly.";

        $sms_result = sendSMS($test_phone, $message);

        if ($sms_result === true) {
            flash_alert("Test SMS sent successfully!");
        } else {
            flash_alert("Failed to send test SMS: " . $sms_result, 'error');
        }
        
        header("Location: mail.php");
        exit;
    }
}

// Get current settings with proper error handling
$sql = mysqli_query($mysqli, "SELECT * FROM settings WHERE company_id = 1");
if ($sql && mysqli_num_rows($sql) > 0) {
    $row = mysqli_fetch_array($sql);
    
    // Mail settings with null coalescing and proper defaults
    $config_mail_host = $row['config_mail_host'] ?? '';
    $config_mail_port = isset($row['config_mail_port']) ? intval($row['config_mail_port']) : 587;
    $config_mail_username = $row['config_mail_username'] ?? '';
    $config_mail_password = $row['config_mail_password'] ?? '';
    $config_mail_from_email = $row['config_mail_from_email'] ?? '';
    $config_mail_from_name = $row['config_mail_from_name'] ?? 'Clinic EMR';

    // SMS settings with null coalescing
    $config_sms_provider = $row['config_sms_provider'] ?? '';
    $config_sms_api_key = $row['config_sms_api_key'] ?? '';
    $config_sms_sender_id = $row['config_sms_sender_id'] ?? 'CLINIC';
    $config_sms_username = $row['config_sms_username'] ?? '';
    $config_sms_password = $row['config_sms_password'] ?? '';
} else {
    // Initialize empty values if no settings found
    $config_mail_host = '';
    $config_mail_port = 587;
    $config_mail_username = '';
    $config_mail_password = '';
    $config_mail_from_email = '';
    $config_mail_from_name = 'Clinic EMR';
    $config_sms_provider = '';
    $config_sms_api_key = '';
    $config_sms_sender_id = 'CLINIC';
    $config_sms_username = '';
    $config_sms_password = '';
}
?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-envelope mr-2"></i>Email Settings</h3>
    </div>
    <div class="card-body">
        <form action="" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>SMTP Host</label>
                        <input type="text" class="form-control" name="config_mail_host" 
                               placeholder="smtp.gmail.com or your mail server" 
                               value="<?php echo nullable_htmlentities($config_mail_host); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>SMTP Port</label>
                        <input type="number" class="form-control" name="config_mail_port" 
                               value="<?php echo intval($config_mail_port); ?>" placeholder="587">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" class="form-control" name="config_mail_username" 
                               placeholder="Your email address" 
                               value="<?php echo nullable_htmlentities($config_mail_username); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" class="form-control" name="config_mail_password" 
                               value="<?php echo nullable_htmlentities($config_mail_password); ?>" 
                               autocomplete="new-password">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>From Email</label>
                        <input type="email" class="form-control" name="config_mail_from_email" 
                               placeholder="noreply@yourclinic.co.ke" 
                               value="<?php echo nullable_htmlentities($config_mail_from_email); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>From Name</label>
                        <input type="text" class="form-control" name="config_mail_from_name" 
                               placeholder="Clinic Name" 
                               value="<?php echo nullable_htmlentities($config_mail_from_name); ?>">
                    </div>
                </div>
            </div>

            <hr>
            <button type="submit" name="edit_mail_settings" class="btn btn-primary">
                <i class="fas fa-save mr-2"></i>Save Email Settings
            </button>
        </form>

        <?php if (!empty($config_mail_host)) { ?>
        <hr>
        <h5>Test Email</h5>
        <form action="" method="post" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
            <div class="input-group w-100">
                <input type="email" class="form-control" name="test_email" 
                       placeholder="Enter email to test" required>
                <div class="input-group-append">
                    <button type="submit" name="test_mail" class="btn btn-success">
                        <i class="fas fa-paper-plane mr-2"></i>Send Test
                    </button>
                </div>
            </div>
        </form>
        <?php } ?>
    </div>
</div>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-sms mr-2"></i>SMS Settings (Kenya Providers)</h3>
    </div>
    <div class="card-body">
        <form action="" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>SMS Provider</label>
                <select class="form-control" name="config_sms_provider">
                    <option value="">-- Select Provider --</option>
                    <option value="africastalking" <?php if($config_sms_provider == 'africastalking') echo 'selected'; ?>>Africa's Talking</option>
                    <option value="safaricom" <?php if($config_sms_provider == 'safaricom') echo 'selected'; ?>>Safaricom Bonga</option>
                    <option value="at_sms" <?php if($config_sms_provider == 'at_sms') echo 'selected'; ?>>AT SMS</option>
                    <option value="custom" <?php if($config_sms_provider == 'custom') echo 'selected'; ?>>Custom API</option>
                </select>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>API Key</label>
                        <input type="text" class="form-control" name="config_sms_api_key" 
                               value="<?php echo nullable_htmlentities($config_sms_api_key); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Sender ID</label>
                        <input type="text" class="form-control" name="config_sms_sender_id" 
                               value="<?php echo nullable_htmlentities($config_sms_sender_id); ?>" 
                               placeholder="CLINIC (max 11 chars)">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" class="form-control" name="config_sms_username" 
                               value="<?php echo nullable_htmlentities($config_sms_username); ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Password/Secret</label>
                        <input type="password" class="form-control" name="config_sms_password" 
                               value="<?php echo nullable_htmlentities($config_sms_password); ?>">
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <small>
                    <strong>Popular Kenya SMS Providers:</strong><br>
                    • <strong>Africa's Talking:</strong> Use API Key & Username from dashboard<br>
                    • <strong>Safaricom Bonga:</strong> Use provided credentials<br>
                    • <strong>AT SMS:</strong> API credentials from account<br>
                    • Sender ID must be approved by provider
                </small>
            </div>

            <hr>
            <button type="submit" name="edit_mail_settings" class="btn btn-primary">
                <i class="fas fa-save mr-2"></i>Save SMS Settings
            </button>
        </form>

        <?php if (!empty($config_sms_provider)) { ?>
        <hr>
        <h5>Test SMS</h5>
        <form action="" method="post" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
            <div class="input-group w-100">
                <input type="text" class="form-control" name="test_phone" 
                       placeholder="2547XXXXXXXX" pattern="2547[0-9]{8}" required
                       title="Enter phone in format 2547XXXXXXXX">
                <div class="input-group-append">
                    <button type="submit" name="test_sms" class="btn btn-success">
                        <i class="fas fa-sms mr-2"></i>Send Test SMS
                    </button>
                </div>
            </div>
            <small class="form-text text-muted">Format: 2547XXXXXXXX (e.g., 254712345678)</small>
        </form>
        <?php } ?>
    </div>
</div>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
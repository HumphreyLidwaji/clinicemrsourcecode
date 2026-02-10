<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Only allow users to view their own profile
if (!isset($session_user_id)) {
    header("Location: /login.php");
    exit;
}

$user_id = $session_user_id;

// Get current user details
$user_sql = "SELECT * FROM users WHERE user_id = ?";
$user_stmt = $mysqli->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "User not found.";
    header("Location: /dashboard.php");
    exit;
}

$user = $user_result->fetch_assoc();

// Handle profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: user_details.php");
        exit;
    }

    // Determine which form was submitted
    if (isset($_POST['update_profile'])) {
        // Update profile information
        $user_name = sanitizeInput($_POST['user_name']);
        $user_email = sanitizeInput($_POST['user_email']);
        
        // Validate inputs
        if (empty($user_name) || empty($user_email)) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please fill in all required fields.";
            header("Location: user_details.php");
            exit;
        }
        
        if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please enter a valid email address.";
            header("Location: user_details.php");
            exit;
        }
        
        // Check if email already exists (excluding current user)
        $email_check_sql = "SELECT user_id FROM users WHERE user_email = ? AND user_id != ?";
        $email_check_stmt = $mysqli->prepare($email_check_sql);
        $email_check_stmt->bind_param("si", $user_email, $user_id);
        $email_check_stmt->execute();
        $email_check_result = $email_check_stmt->get_result();
        
        if ($email_check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "This email address is already registered.";
            header("Location: user_details.php");
            exit;
        }
        
        // Handle avatar upload
        $avatar_path = $user['user_avatar'];
        if (isset($_FILES['user_avatar']) && $_FILES['user_avatar']['error'] === UPLOAD_ERR_OK) {
            $avatar_upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/avatars/';
            
            // Create directory if it doesn't exist
            if (!is_dir($avatar_upload_dir)) {
                mkdir($avatar_upload_dir, 0755, true);
            }
            
            $avatar_file = $_FILES['user_avatar'];
            $file_extension = strtolower(pathinfo($avatar_file['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Generate unique filename
                $new_filename = 'avatar_' . $user_id . '_' . time() . '.' . $file_extension;
                $upload_path = $avatar_upload_dir . $new_filename;
                
                if (move_uploaded_file($avatar_file['tmp_name'], $upload_path)) {
                    $avatar_path = '/uploads/avatars/' . $new_filename;
                    
                    // Delete old avatar if it exists and is not the default
                    if ($user['user_avatar'] && !str_contains($user['user_avatar'], 'default')) {
                        $old_avatar_path = $_SERVER['DOCUMENT_ROOT'] . $user['user_avatar'];
                        if (file_exists($old_avatar_path)) {
                            unlink($old_avatar_path);
                        }
                    }
                }
            }
        }
        
        // Update user profile
        $update_sql = "UPDATE users SET user_name = ?, user_email = ?, user_avatar = ?, user_updated_at = NOW() WHERE user_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sssi", $user_name, $user_email, $avatar_path, $user_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Profile updated successfully!";
            
            // Update session user name if changed
            $_SESSION['user_name'] = $user_name;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating profile: " . $mysqli->error;
        }
        
    } elseif (isset($_POST['change_password'])) {
        // Change password
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please fill in all password fields.";
            header("Location: user_details.php");
            exit;
        }
        
        if ($new_password !== $confirm_password) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "New passwords do not match.";
            header("Location: user_details.php");
            exit;
        }
        
        if (strlen($new_password) < 8) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "New password must be at least 8 characters long.";
            header("Location: user_details.php");
            exit;
        }
        
        // Verify current password
        if (!password_verify($current_password, $user['user_password'])) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Current password is incorrect.";
            header("Location: user_details.php");
            exit;
        }
        
        // Hash new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password
        $password_sql = "UPDATE users SET user_password = ?, user_updated_at = NOW() WHERE user_id = ?";
        $password_stmt = $mysqli->prepare($password_sql);
        $password_stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($password_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Password changed successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error changing password: " . $mysqli->error;
        }
    }
    
    header("Location: user_details.php");
    exit;
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-user mr-2"></i>My Profile
            </h3>
            <div class="card-tools">
                <a href="/clinic/dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <div class="row">
            <!-- Profile Information -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-edit mr-2 text-primary"></i>Profile Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <!-- Avatar -->
                            <div class="form-group text-center mb-4">
                                <div class="d-flex flex-column align-items-center">
                                    <?php if ($user['user_avatar']): ?>
                                        <img src="<?php echo htmlspecialchars($user['user_avatar']); ?>" 
                                             class="rounded-circle mb-3" 
                                             style="width: 120px; height: 120px; object-fit: cover;" 
                                             alt="<?php echo htmlspecialchars($user['user_name']); ?>">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mb-3" 
                                             style="width: 120px; height: 120px; font-size: 36px;">
                                            <?php echo strtoupper(substr($user['user_name'], 0, 2)); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="custom-file" style="max-width: 200px;">
                                        <input type="file" class="custom-file-input" id="user_avatar" name="user_avatar" accept="image/*">
                                        <label class="custom-file-label" for="user_avatar">Choose avatar</label>
                                    </div>
                                    <small class="form-text text-muted mt-1">JPG, PNG or GIF. Max 2MB.</small>
                                </div>
                            </div>

                            <!-- Name -->
                            <div class="form-group">
                                <label for="user_name">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="user_name" name="user_name" 
                                       value="<?php echo htmlspecialchars($user['user_name']); ?>" required>
                            </div>

                            <!-- Email -->
                            <div class="form-group">
                                <label for="user_email">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="user_email" name="user_email" 
                                       value="<?php echo htmlspecialchars($user['user_email']); ?>" required>
                            </div>

                            <!-- Read-only Information -->
                            <div class="form-group">
                                <label>User ID</label>
                                <input type="text" class="form-control" value="<?php echo $user['user_id']; ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label>Account Created</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo date('F j, Y g:i A', strtotime($user['user_created_at'])); ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label>Last Updated</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo $user['user_updated_at'] ? date('F j, Y g:i A', strtotime($user['user_updated_at'])) : 'Never'; ?>" readonly>
                            </div>

                            <button type="submit" class="btn btn-success btn-block">
                                <i class="fas fa-save mr-2"></i>Update Profile
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-key mr-2 text-warning"></i>Change Password
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="change_password" value="1">
                            
                            <!-- Current Password -->
                            <div class="form-group">
                                <label for="current_password">Current Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>

                            <!-- New Password -->
                            <div class="form-group">
                                <label for="new_password">New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       minlength="8" required>
                                <small class="form-text text-muted">Minimum 8 characters</small>
                            </div>

                            <!-- Confirm Password -->
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       minlength="8" required>
                            </div>

                            <button type="submit" class="btn btn-warning btn-block">
                                <i class="fas fa-lock mr-2"></i>Change Password
                            </button>
                        </form>

                        <!-- Account Status -->
                        <hr>
                        <div class="mt-4">
                            <h6 class="text-muted mb-3">Account Status</h6>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span>Status:</span>
                                <?php if ($user['user_status'] == 1): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Disabled</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span>Authentication:</span>
                                <span class="badge badge-info"><?php echo ucfirst($user['user_auth_method']); ?></span>
                            </div>
                            <?php if (!is_null($user['user_archived_at'])): ?>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Archived:</span>
                                    <span class="badge badge-secondary">Yes</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update file input label
$(document).ready(function() {
    $('#user_avatar').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').text(fileName || 'Choose avatar');
    });

    // Password confirmation validation
    $('#new_password, #confirm_password').on('keyup', function() {
        const newPassword = $('#new_password').val();
        const confirmPassword = $('#confirm_password').val();
        
        if (newPassword && confirmPassword) {
            if (newPassword !== confirmPassword) {
                $('#confirm_password').addClass('is-invalid');
            } else {
                $('#confirm_password').removeClass('is-invalid');
            }
        }
    });

    // Form submission handling
    $('form').on('submit', function() {
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
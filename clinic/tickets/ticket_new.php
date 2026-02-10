<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: ticket_new.php");
        exit;
    }

    $ticket_title = sanitizeInput($_POST['ticket_title']);
    $ticket_description = sanitizeInput($_POST['ticket_description']);
    $ticket_priority = sanitizeInput($_POST['ticket_priority']);
    $ticket_category = sanitizeInput($_POST['ticket_category']);
    $assigned_to = intval($_POST['assigned_to'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? $session_user_id);
    
    // Validate required fields
    if (empty($ticket_title) || empty($ticket_description) || empty($ticket_priority) || empty($ticket_category)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Title, description, priority, and category are required.";
        header("Location: ticket_new.php");
        exit;
    }
    
    $mysqli->begin_transaction();
    
    try {
        // Generate ticket number
        $ticket_number = 'TKT-' . date('Ymd') . '-' . strtoupper(uniqid());
        
        // Insert new ticket
        $insert_sql = "INSERT INTO tickets SET 
                      ticket_number = ?,
                      ticket_title = ?,
                      ticket_description = ?,
                      ticket_priority = ?,
                      ticket_category = ?,
                      user_id = ?,
                      assigned_to = ?,
                      created_by = ?,
                      created_at = NOW(),
                      ticket_status = 'open'";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("sssssiii", 
            $ticket_number,
            $ticket_title,
            $ticket_description,
            $ticket_priority,
            $ticket_category,
            $user_id,
            $assigned_to,
            $session_user_id
        );
        
        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to create ticket: " . $insert_stmt->error);
        }
        
        $ticket_id = $mysqli->insert_id;
        $insert_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Tickets',
                  log_action = 'Create',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Created new support ticket: " . $ticket_number . " - " . $ticket_title;
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $ticket_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Ticket #$ticket_number created successfully!";
        header("Location: ticket_view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: ticket_new.php");
        exit;
    }
}

// Get IT support users (users with ticket permissions)
$support_users_sql = "SELECT user_id, user_name, user_email FROM users";
$support_users_result = $mysqli->query($support_users_sql);
$support_users = [];
while ($user = $support_users_result->fetch_assoc()) {
    $support_users[] = $user;
}

// Get all users for ticket creation
$all_users_sql = "SELECT user_id, user_name, user_email FROM users WHERE user_status = 'active' ORDER BY user_name";
$all_users_result = $mysqli->query($all_users_sql);
$all_users = [];
while ($user = $all_users_result->fetch_assoc()) {
    $all_users[] = $user;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-plus-circle mr-2"></i>
                Create New Support Ticket
            </h3>
            <div class="card-tools">
                <a href="tickets.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Tickets
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <div class="form-group">
                        <label>Ticket Title <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="ticket_title" placeholder="Brief description of the issue" required autofocus>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Priority <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="ticket_priority" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Category <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="ticket_category" required>
                            <option value="Hardware">Hardware</option>
                            <option value="Software">Software</option>
                            <option value="Network">Network</option>
                            <option value="Email">Email</option>
                            <option value="Account">Account Access</option>
                            <option value="Security">Security</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>User</label>
                        <select class="form-control select2" name="user_id">
                            <option value="<?php echo $session_user_id; ?>">Myself</option>
                            <?php foreach ($all_users as $user): ?>
                                <?php if ($user['user_id'] != $session_user_id): ?>
                                    <option value="<?php echo $user['user_id']; ?>">
                                        <?php echo htmlspecialchars($user['user_name']); ?> (<?php echo htmlspecialchars($user['user_email']); ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Leave as "Myself" to create ticket for yourself</small>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description <strong class="text-danger">*</strong></label>
                <textarea class="form-control" name="ticket_description" rows="8" placeholder="Detailed description of the issue, steps to reproduce, error messages, etc." required></textarea>
            </div>
            
            <div class="form-group">
                <label>Assign To (Optional)</label>
                <select class="form-control select2" name="assigned_to">
                    <option value="0">Unassigned</option>
                    <?php foreach ($support_users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>">
                            <?php echo htmlspecialchars($user['user_name']); ?> (<?php echo htmlspecialchars($user['user_email']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus-circle mr-2"></i>Create Ticket
                </button>
                <a href="tickets.php" class="btn btn-secondary btn-lg">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
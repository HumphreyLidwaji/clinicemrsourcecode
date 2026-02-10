<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!SimplePermission::any('tickets', 'assign')) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "You don't have permission to assign tickets.";
    header("Location: access_denied.php");
    exit;
}

// Get ticket ID from URL
$ticket_id = intval($_GET['id'] ?? 0);

if (!$ticket_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid ticket ID.";
    header("Location: tickets.php");
    exit;
}

// Get ticket details
$ticket_sql = "SELECT t.*, u.user_name, u.user_email 
               FROM tickets t
               LEFT JOIN users u ON t.user_id = u.user_id
               WHERE t.ticket_id = ?";
$ticket_stmt = $mysqli->prepare($ticket_sql);
$ticket_stmt->bind_param("i", $ticket_id);
$ticket_stmt->execute();
$ticket_result = $ticket_stmt->get_result();

if ($ticket_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Ticket not found.";
    header("Location: tickets.php");
    exit;
}

$ticket = $ticket_result->fetch_assoc();
$ticket_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: ticket_assign.php?id=" . $ticket_id);
        exit;
    }

    $assigned_to = intval($_POST['assigned_to']);
    
    $mysqli->begin_transaction();
    
    try {
        // Get ticket info for logging
        $ticket_sql = "SELECT ticket_number FROM tickets WHERE ticket_id = ?";
        $ticket_stmt = $mysqli->prepare($ticket_sql);
        $ticket_stmt->bind_param("i", $ticket_id);
        $ticket_stmt->execute();
        $ticket_result = $ticket_stmt->get_result();
        
        if ($ticket_result->num_rows === 0) {
            throw new Exception("Ticket not found.");
        }
        
        $ticket_info = $ticket_result->fetch_assoc();
        $ticket_stmt->close();
        
        // Get assignee name
        $user_sql = "SELECT user_name FROM users WHERE user_id = ?";
        $user_stmt = $mysqli->prepare($user_sql);
        $user_stmt->bind_param("i", $assigned_to);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result->fetch_assoc();
        $user_stmt->close();
        
        // Update assignment
        $update_sql = "UPDATE tickets SET 
                      assigned_to = ?,
                      updated_by = ?,
                      updated_at = NOW()
                      WHERE ticket_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("iii", $assigned_to, $session_user_id, $ticket_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to assign ticket: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Tickets',
                  log_action = 'Assign',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Assigned ticket " . $ticket_info['ticket_number'] . " to " . $user['user_name'];
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $ticket_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Ticket assigned successfully!";
        header("Location: ticket_view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: ticket_assign.php?id=" . $ticket_id);
        exit;
    }
}

// Get IT support users (users with ticket permissions)
$support_users_sql = "SELECT user_id, user_name, user_email FROM users ";
$support_users_result = $mysqli->query($support_users_sql);
$support_users = [];
while ($user = $support_users_result->fetch_assoc()) {
    $support_users[] = $user;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-user-plus mr-2"></i>
                Assign Ticket: <?php echo htmlspecialchars($ticket['ticket_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="ticket_view.php?id=<?php echo $ticket_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Ticket
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

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5>Ticket Information</h5>
                        <p><strong>Ticket #:</strong> <?php echo htmlspecialchars($ticket['ticket_number']); ?></p>
                        <p><strong>Title:</strong> <?php echo htmlspecialchars($ticket['ticket_title']); ?></p>
                        <p><strong>Created By:</strong> <?php echo htmlspecialchars($ticket['user_name']); ?></p>
                        <p><strong>Current Assignee:</strong> 
                            <?php 
                            if ($ticket['assigned_to']) {
                                // Get current assignee name
                                $current_sql = "SELECT user_name FROM users WHERE user_id = ?";
                                $current_stmt = $mysqli->prepare($current_sql);
                                $current_stmt->bind_param("i", $ticket['assigned_to']);
                                $current_stmt->execute();
                                $current_result = $current_stmt->get_result();
                                $current = $current_result->fetch_assoc();
                                $current_stmt->close();
                                echo htmlspecialchars($current['user_name'] ?? 'Unknown');
                            } else {
                                echo '<span class="text-muted">Unassigned</span>';
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-group">
                <label>Assign To</label>
                <select class="form-control select2" name="assigned_to" required>
                    <option value="0">Unassigned</option>
                    <?php foreach ($support_users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>" 
                                <?php echo $ticket['assigned_to'] == $user['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['user_name']); ?> (<?php echo htmlspecialchars($user['user_email']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-user-plus mr-2"></i>Assign Ticket
                </button>
                <a href="ticket_view.php?id=<?php echo $ticket_id; ?>" class="btn btn-secondary btn-lg">
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
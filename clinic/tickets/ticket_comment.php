<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';


// Get ticket ID from URL or POST
$ticket_id = intval($_GET['id'] ?? $_POST['ticket_id'] ?? 0);

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
        header("Location: ticket_comment.php?id=" . $ticket_id);
        exit;
    }

    $comment_text = sanitizeInput($_POST['comment_text']);
    $is_internal = isset($_POST['is_internal']) ? 1 : 0;
    
    if (empty($comment_text)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Comment text is required.";
        header("Location: ticket_comment.php?id=" . $ticket_id);
        exit;
    }
    
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
        
        // Add comment
        $comment_sql = "INSERT INTO ticket_comments SET
                       ticket_id = ?,
                       comment_text = ?,
                       commented_by = ?,
                       is_internal = ?,
                       created_at = NOW()";
        
        $comment_stmt = $mysqli->prepare($comment_sql);
        $comment_stmt->bind_param("isii", $ticket_id, $comment_text, $session_user_id, $is_internal);
        
        if (!$comment_stmt->execute()) {
            throw new Exception("Failed to add comment: " . $comment_stmt->error);
        }
        $comment_stmt->close();
        
        // Update ticket last activity
        $update_sql = "UPDATE tickets SET last_activity = NOW() WHERE ticket_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $ticket_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Tickets',
                  log_action = 'Comment',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Added comment to ticket: " . $ticket_info['ticket_number'];
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $ticket_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Comment added successfully!";
        header("Location: ticket_view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: ticket_comment.php?id=" . $ticket_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-comment mr-2"></i>
                Add Comment to Ticket: <?php echo htmlspecialchars($ticket['ticket_number']); ?>
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
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <h5>Ticket Information</h5>
                        <p><strong>Ticket #:</strong> <?php echo htmlspecialchars($ticket['ticket_number']); ?></p>
                        <p><strong>Title:</strong> <?php echo htmlspecialchars($ticket['ticket_title']); ?></p>
                        <p><strong>Created By:</strong> <?php echo htmlspecialchars($ticket['user_name']); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="badge badge-<?php 
                                switch ($ticket['ticket_status']) {
                                    case 'open': echo 'warning'; break;
                                    case 'in_progress': echo 'info'; break;
                                    case 'on_hold': echo 'secondary'; break;
                                    case 'closed': echo 'success'; break;
                                    default: echo 'secondary';
                                }
                            ?>">
                                <?php echo ucwords(str_replace('_', ' ', $ticket['ticket_status'])); ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
            
            <div class="form-group">
                <label>Comment <strong class="text-danger">*</strong></label>
                <textarea class="form-control" name="comment_text" rows="6" placeholder="Enter your comment here..." required autofocus></textarea>
            </div>
            
            <div class="form-check mb-4">
                <input type="checkbox" class="form-check-input" name="is_internal" id="is_internal">
                <label class="form-check-label" for="is_internal">
                    Internal Comment (not visible to user)
                </label>
                <small class="form-text text-muted">
                    Internal comments are only visible to IT support staff and administrators.
                </small>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-comment mr-2"></i>Add Comment
                </button>
                <a href="ticket_view.php?id=<?php echo $ticket_id; ?>" class="btn btn-secondary btn-lg">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
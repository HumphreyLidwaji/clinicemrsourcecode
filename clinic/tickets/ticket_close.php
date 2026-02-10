<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



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

// Check if ticket is already closed
if ($ticket['ticket_status'] == 'closed') {
    $_SESSION['alert_type'] = "warning";
    $_SESSION['alert_message'] = "This ticket is already closed.";
    header("Location: ticket_view.php?id=" . $ticket_id);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: ticket_close.php?id=" . $ticket_id);
        exit;
    }

    $resolution = sanitizeInput($_POST['resolution']);
    
    if (empty($resolution)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Resolution is required.";
        header("Location: ticket_close.php?id=" . $ticket_id);
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
        
        // Close ticket
        $update_sql = "UPDATE tickets SET 
                      ticket_status = 'closed',
                      resolution = ?,
                      closed_by = ?,
                      closed_at = NOW(),
                      updated_by = ?,
                      updated_at = NOW()
                      WHERE ticket_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("siii", $resolution, $session_user_id, $session_user_id, $ticket_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to close ticket: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Tickets',
                  log_action = 'Close',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Closed ticket: " . $ticket_info['ticket_number'];
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $ticket_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Ticket closed successfully!";
        header("Location: ticket_view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: ticket_close.php?id=" . $ticket_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-check-circle mr-2"></i>
                Close Ticket: <?php echo htmlspecialchars($ticket['ticket_number']); ?>
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
                        <p><strong>Days Open:</strong> 
                            <?php 
                            $days_open = floor((time() - strtotime($ticket['created_at'])) / (60 * 60 * 24));
                            echo $days_open . ' day' . ($days_open != 1 ? 's' : '');
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="alert alert-warning">
                <h5><i class="fas fa-exclamation-triangle mr-2"></i>Warning</h5>
                <p>Closing this ticket will mark it as resolved. Please provide a detailed resolution below.</p>
            </div>
            
            <div class="form-group">
                <label>Resolution <strong class="text-danger">*</strong></label>
                <textarea class="form-control" name="resolution" rows="8" placeholder="Describe how the issue was resolved, steps taken, final outcome, etc..." required autofocus></textarea>
                <small class="form-text text-muted">
                    This resolution will be visible to the user who created the ticket.
                </small>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-check-circle mr-2"></i>Close Ticket
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
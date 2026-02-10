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
$ticket_sql = "SELECT * FROM tickets WHERE ticket_id = ?";
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
        header("Location: ticket_edit.php?id=" . $ticket_id);
        exit;
    }

    $ticket_title = sanitizeInput($_POST['ticket_title']);
    $ticket_description = sanitizeInput($_POST['ticket_description']);
    $ticket_priority = sanitizeInput($_POST['ticket_priority']);
    $ticket_category = sanitizeInput($_POST['ticket_category']);
    $ticket_status = sanitizeInput($_POST['ticket_status']);
    
    // Validate required fields
    if (empty($ticket_title) || empty($ticket_description) || empty($ticket_priority) || empty($ticket_category)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Title, description, priority, and category are required.";
        header("Location: ticket_edit.php?id=" . $ticket_id);
        exit;
    }
    
    $mysqli->begin_transaction();
    
    try {
        // Get current ticket info for logging
        $current_sql = "SELECT ticket_number, ticket_title FROM tickets WHERE ticket_id = ?";
        $current_stmt = $mysqli->prepare($current_sql);
        $current_stmt->bind_param("i", $ticket_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        
        if ($current_result->num_rows === 0) {
            throw new Exception("Ticket not found.");
        }
        
        $current_ticket = $current_result->fetch_assoc();
        $current_stmt->close();
        
        // Update ticket
        $update_sql = "UPDATE tickets SET 
                      ticket_title = ?,
                      ticket_description = ?,
                      ticket_priority = ?,
                      ticket_category = ?,
                      ticket_status = ?,
                      updated_by = ?,
                      updated_at = NOW()
                      WHERE ticket_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sssssii", 
            $ticket_title,
            $ticket_description,
            $ticket_priority,
            $ticket_category,
            $ticket_status,
            $session_user_id,
            $ticket_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update ticket: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Tickets',
                  log_action = 'Update',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Updated ticket: " . $current_ticket['ticket_number'] . " - " . $ticket_title;
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $ticket_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Ticket updated successfully!";
        header("Location: ticket_view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: ticket_edit.php?id=" . $ticket_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-edit mr-2"></i>
                Edit Ticket: <?php echo htmlspecialchars($ticket['ticket_number']); ?>
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

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <div class="form-group">
                        <label>Ticket Title <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="ticket_title" 
                               value="<?php echo htmlspecialchars($ticket['ticket_title']); ?>" required autofocus>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Priority <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="ticket_priority" required>
                            <option value="low" <?php echo $ticket['ticket_priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $ticket['ticket_priority'] == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $ticket['ticket_priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $ticket['ticket_priority'] == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Category <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="ticket_category" required>
                            <option value="Hardware" <?php echo $ticket['ticket_category'] == 'Hardware' ? 'selected' : ''; ?>>Hardware</option>
                            <option value="Software" <?php echo $ticket['ticket_category'] == 'Software' ? 'selected' : ''; ?>>Software</option>
                            <option value="Network" <?php echo $ticket['ticket_category'] == 'Network' ? 'selected' : ''; ?>>Network</option>
                            <option value="Email" <?php echo $ticket['ticket_category'] == 'Email' ? 'selected' : ''; ?>>Email</option>
                            <option value="Account" <?php echo $ticket['ticket_category'] == 'Account' ? 'selected' : ''; ?>>Account Access</option>
                            <option value="Security" <?php echo $ticket['ticket_category'] == 'Security' ? 'selected' : ''; ?>>Security</option>
                            <option value="Other" <?php echo $ticket['ticket_category'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Status <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="ticket_status" required>
                            <option value="open" <?php echo $ticket['ticket_status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $ticket['ticket_status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="on_hold" <?php echo $ticket['ticket_status'] == 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                            <option value="closed" <?php echo $ticket['ticket_status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description <strong class="text-danger">*</strong></label>
                <textarea class="form-control" name="ticket_description" rows="8" required><?php echo htmlspecialchars($ticket['ticket_description']); ?></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save mr-2"></i>Update Ticket
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
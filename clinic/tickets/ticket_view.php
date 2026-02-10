<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current user session info
$session_user_id = $_SESSION['user_id'] ?? 0;
$session_user_name = $_SESSION['user_name'] ?? 'User';
$session_user_email = $_SESSION['user_email'] ?? '';

// Get ticket ID from URL
$ticket_id = intval($_GET['id'] ?? 0);

if (!$ticket_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid ticket ID.";
    header("Location: tickets.php");
    exit;
}

// Get ticket details
$ticket_sql = "SELECT t.*, 
                      u.user_name, u.user_email,
                      a.user_name as assigned_name, a.user_email as assigned_email,
                      c.user_name as created_by_name,
                      cl.user_name as closed_by_name
               FROM tickets t
               LEFT JOIN users u ON t.user_id = u.user_id
               LEFT JOIN users a ON t.assigned_to = a.user_id
               LEFT JOIN users c ON t.created_by = c.user_id
               LEFT JOIN users cl ON t.closed_by = cl.user_id
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

// Get ticket comments/replies (from both ticket_comments and ticket_replies tables)
$comments_sql = "SELECT 
                    tc.comment_id,
                    tc.ticket_id,
                    tc.commented_by as user_id,
                    tc.comment_text,
                    tc.is_internal,
                    tc.created_at,
                    tc.updated_at,
                    u.user_name,
                    u.user_email,
                    'comment' as type
                 FROM ticket_comments tc
                 LEFT JOIN users u ON tc.commented_by = u.user_id
                 WHERE tc.ticket_id = ?
                 
                 UNION ALL
                 
                 SELECT 
                    tr.reply_id as comment_id,
                    tr.ticket_id,
                    tr.user_id,
                    tr.reply_text,
                    tr.is_internal,
                    tr.created_at,
                    NULL as updated_at,
                    COALESCE(u.user_name, '') as user_name,
                    COALESCE(u.user_email, '') as user_email,
                    'reply' as type
                 FROM ticket_replies tr
                 LEFT JOIN users u ON tr.user_id = u.user_id
                 WHERE tr.ticket_id = ?
                 
                 ORDER BY created_at DESC";

// Alternative solution if you still get collation errors:
$comments_sql = "SELECT 
                    tc.comment_id,
                    tc.ticket_id,
                    tc.commented_by as user_id,
                    tc.comment_text,
                    tc.is_internal,
                    tc.created_at,
                    tc.updated_at,
                    u.user_name COLLATE utf8mb4_unicode_ci as user_name,
                    u.user_email COLLATE utf8mb4_unicode_ci as user_email,
                    'comment' as type
                 FROM ticket_comments tc
                 LEFT JOIN users u ON tc.commented_by = u.user_id
                 WHERE tc.ticket_id = ?
                 
                 UNION ALL
                 
                 SELECT 
                    tr.reply_id as comment_id,
                    tr.ticket_id,
                    tr.user_id,
                    tr.reply_text,
                    tr.is_internal,
                    tr.created_at,
                    NULL as updated_at,
                    COALESCE(u.user_name, '') COLLATE utf8mb4_unicode_ci as user_name,
                    COALESCE(u.user_email, '') COLLATE utf8mb4_unicode_ci as user_email,
                    'reply' as type
                 FROM ticket_replies tr
                 LEFT JOIN users u ON tr.user_id = u.user_id
                 WHERE tr.ticket_id = ?
                 
                 ORDER BY created_at DESC";

// Alternative: Fetch comments and replies separately
$comments_sql1 = "SELECT 
                    tc.comment_id,
                    tc.ticket_id,
                    tc.commented_by as user_id,
                    tc.comment_text,
                    tc.is_internal,
                    tc.created_at,
                    tc.updated_at,
                    u.user_name,
                    u.user_email,
                    'comment' as type
                 FROM ticket_comments tc
                 LEFT JOIN users u ON tc.commented_by = u.user_id
                 WHERE tc.ticket_id = ?";

$comments_sql2 = "SELECT 
                    tr.reply_id as comment_id,
                    tr.ticket_id,
                    tr.user_id,
                    tr.reply_text as comment_text,
                    tr.is_internal,
                    tr.created_at,
                    NULL as updated_at,
                    u.user_name,
                    u.user_email,
                    'reply' as type
                 FROM ticket_replies tr
                 LEFT JOIN users u ON tr.user_id = u.user_id
                 WHERE tr.ticket_id = ?";

$comments = [];

// Fetch comments
$stmt1 = $mysqli->prepare($comments_sql1);
$stmt1->bind_param("i", $ticket_id);
$stmt1->execute();
$result1 = $stmt1->get_result();
while ($comment = $result1->fetch_assoc()) {
    $comments[] = $comment;
}
$stmt1->close();

// Fetch replies
$stmt2 = $mysqli->prepare($comments_sql2);
$stmt2->bind_param("i", $ticket_id);
$stmt2->execute();
$result2 = $stmt2->get_result();
while ($reply = $result2->fetch_assoc()) {
    $comments[] = $reply;
}
$stmt2->close();

// Sort all comments by creation date
usort($comments, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
// Determine priority styling
switch ($ticket['ticket_priority']) {
    case 'low':
        $priority_class = 'info';
        $priority_icon = 'arrow-down';
        break;
    case 'medium':
        $priority_class = 'warning';
        $priority_icon = 'minus';
        break;
    case 'high':
        $priority_class = 'danger';
        $priority_icon = 'arrow-up';
        break;
    case 'urgent':
        $priority_class = 'danger';
        $priority_icon = 'exclamation-triangle';
        break;
    default:
        $priority_class = 'secondary';
        $priority_icon = 'circle';
}

// Determine status styling
switch ($ticket['ticket_status']) {
    case 'open':
        $status_class = 'warning';
        $status_icon = 'clock';
        break;
    case 'in_progress':
        $status_class = 'info';
        $status_icon = 'cog';
        break;
    case 'on_hold':
        $status_class = 'secondary';
        $status_icon = 'pause';
        break;
    case 'closed':
        $status_class = 'success';
        $status_icon = 'check-circle';
        break;
    default:
        $status_class = 'secondary';
        $status_icon = 'circle';
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-ticket-alt mr-2"></i>
                Ticket Details: <?php echo htmlspecialchars($ticket['ticket_number']); ?>
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

        <div class="row">
            <div class="col-md-8">
                <!-- Ticket Information -->
                <div class="card card-primary mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>Ticket Information
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">Ticket #</dt>
                                    <dd class="col-sm-8">
                                        <strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Status</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge badge-<?php echo $status_class; ?> p-2">
                                            <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                                            <?php echo ucwords(str_replace('_', ' ', $ticket['ticket_status'])); ?>
                                        </span>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Priority</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge badge-<?php echo $priority_class; ?>">
                                            <i class="fas fa-<?php echo $priority_icon; ?> mr-1"></i>
                                            <?php echo ucfirst($ticket['ticket_priority']); ?>
                                        </span>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Category</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars($ticket['ticket_category']); ?></span>
                                    </dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">Created</dt>
                                    <dd class="col-sm-8">
                                        <?php echo date('M j, Y H:i', strtotime($ticket['created_at'])); ?>
                                        <br>
                                        <small class="text-muted">by <?php echo htmlspecialchars($ticket['created_by_name']); ?></small>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Updated</dt>
                                    <dd class="col-sm-8">
                                        <?php if ($ticket['updated_at']): ?>
                                            <?php echo date('M j, Y H:i', strtotime($ticket['updated_at'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </dd>
                                    
                                    <?php if ($ticket['ticket_status'] == 'closed'): ?>
                                        <dt class="col-sm-4">Closed</dt>
                                        <dd class="col-sm-8">
                                            <?php if ($ticket['closed_at']): ?>
                                                <?php echo date('M j, Y H:i', strtotime($ticket['closed_at'])); ?>
                                                <br>
                                                <small class="text-muted">by <?php echo htmlspecialchars($ticket['closed_by_name']); ?></small>
                                            <?php endif; ?>
                                        </dd>
                                    <?php endif; ?>
                                </dl>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5><i class="fas fa-user mr-2"></i>Created By</h5>
                                <div class="media">
                                    <div class="media-body">
                                        <h6 class="mt-0"><?php echo htmlspecialchars($ticket['user_name']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($ticket['user_email']); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5><i class="fas fa-user-tie mr-2"></i>Assigned To</h5>
                                <?php if ($ticket['assigned_name']): ?>
                                    <div class="media">
                                        <div class="media-body">
                                            <h6 class="mt-0"><?php echo htmlspecialchars($ticket['assigned_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($ticket['assigned_email']); ?></small>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted"><em>Unassigned</em></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ticket Description -->
                <div class="card card-success mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-file-alt mr-2"></i>Description
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="ticket-description">
                            <?php echo nl2br(htmlspecialchars($ticket['ticket_description'])); ?>
                        </div>
                    </div>
                </div>

                <?php if ($ticket['ticket_status'] == 'closed' && $ticket['resolution']): ?>
                    <!-- Resolution -->
                    <div class="card card-warning mb-4">
                        <div class="card-header">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-check-circle mr-2"></i>Resolution
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="ticket-resolution">
                                <?php echo nl2br(htmlspecialchars($ticket['resolution'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Comments/Replies Section -->
                <div class="card card-info" id="commentsSection">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-comments mr-2"></i>Conversation (<?php echo count($comments); ?>)
                            </h4>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="toggleCommentsFilter()">
                                <i class="fas fa-filter mr-1"></i>Filter
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Comments Filter -->
                        <div class="mb-3 p-3 border rounded" id="commentsFilter" style="display: none;">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group mb-2">
                                        <label class="small">Show:</label>
                                        <select class="form-control form-control-sm" id="filterType" onchange="filterComments()">
                                            <option value="all">All Comments & Replies</option>
                                            <option value="comments">Comments Only</option>
                                            <option value="replies">Replies Only</option>
                                            <option value="internal">Internal Notes</option>
                                            <option value="public">Public Only</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-2">
                                        <label class="small">User:</label>
                                        <select class="form-control form-control-sm" id="filterUser" onchange="filterComments()">
                                            <option value="all">All Users</option>
                                            <?php
                                            $unique_users = [];
                                            foreach ($comments as $comment) {
                                                if ($comment['user_name'] && !in_array($comment['user_name'], $unique_users)) {
                                                    $unique_users[] = $comment['user_name'];
                                                }
                                            }
                                            sort($unique_users);
                                            foreach ($unique_users as $user): ?>
                                                <option value="<?php echo htmlspecialchars($user); ?>">
                                                    <?php echo htmlspecialchars($user); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-2">
                                        <label class="small">Sort:</label>
                                        <select class="form-control form-control-sm" id="filterSort" onchange="filterComments()">
                                            <option value="newest">Newest First</option>
                                            <option value="oldest">Oldest First</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($comments)): ?>
                            <div class="comments-list" id="commentsList">
                                <?php foreach ($comments as $comment): ?>
                                    <div class="media mb-3 comment-item" 
                                         data-type="<?php echo $comment['type']; ?>"
                                         data-user="<?php echo htmlspecialchars($comment['user_name']); ?>"
                                         data-internal="<?php echo $comment['is_internal'] ? '1' : '0'; ?>"
                                         data-date="<?php echo strtotime($comment['created_at']); ?>">
                                        <div class="media-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mt-0 mb-1">
                                                        <?php if ($comment['type'] == 'comment'): ?>
                                                            <i class="fas fa-comment text-info mr-1" title="Comment"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-reply text-success mr-1" title="Reply"></i>
                                                        <?php endif; ?>
                                                        <?php echo htmlspecialchars($comment['user_name']); ?>
                                                        <?php if ($comment['is_internal']): ?>
                                                            <span class="badge badge-info ml-2">Internal</span>
                                                        <?php endif; ?>
                                                        <?php if ($comment['user_id'] == $ticket['assigned_to']): ?>
                                                            <span class="badge badge-primary ml-2">Assignee</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, Y H:i', strtotime($comment['created_at'])); ?>
                                                        <?php if ($comment['updated_at']): ?>
                                                            <span class="text-muted ml-2">(edited)</span>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-link text-muted" type="button" data-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <div class="dropdown-menu dropdown-menu-right">
                                                        <?php if ($comment['user_id'] == $session_user_id || has_permission('tickets', 'edit')): ?>
                                                            <button class="dropdown-item" onclick="editComment(<?php echo $comment['comment_id']; ?>)">
                                                                <i class="fas fa-edit mr-2"></i>Edit
                                                            </button>
                                                            <button class="dropdown-item text-danger" onclick="deleteComment(<?php echo $comment['comment_id']; ?>, '<?php echo $comment['type']; ?>')">
                                                                <i class="fas fa-trash mr-2"></i>Delete
                                                            </button>
                                                        <?php endif; ?>
                                                        <button class="dropdown-item" onclick="copyCommentText(<?php echo $comment['comment_id']; ?>)">
                                                            <i class="fas fa-copy mr-2"></i>Copy Text
                                                        </button>
                                                        <button class="dropdown-item" onclick="quoteComment(<?php echo $comment['comment_id']; ?>)">
                                                            <i class="fas fa-quote-right mr-2"></i>Quote
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="comment-text mt-2" id="comment-text-<?php echo $comment['comment_id']; ?>">
                                                <?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?>
                                            </div>
                                            
                                            <!-- Edit Form (Hidden) -->
                                            <div class="edit-form mt-2" id="edit-form-<?php echo $comment['comment_id']; ?>" style="display: none;">
                                                <textarea class="form-control mb-2" id="edit-text-<?php echo $comment['comment_id']; ?>" rows="3"><?php echo htmlspecialchars($comment['comment_text']); ?></textarea>
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <div class="form-check">
                                                            <input type="checkbox" class="form-check-input" id="edit-internal-<?php echo $comment['comment_id']; ?>" 
                                                                   <?php echo $comment['is_internal'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label small" for="edit-internal-<?php echo $comment['comment_id']; ?>">
                                                                Internal Comment
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <button class="btn btn-sm btn-success" onclick="saveCommentEdit(<?php echo $comment['comment_id']; ?>, '<?php echo $comment['type']; ?>')">
                                                            <i class="fas fa-save mr-1"></i>Save
                                                        </button>
                                                        <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?php echo $comment['comment_id']; ?>)">
                                                            <i class="fas fa-times mr-1"></i>Cancel
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-comment fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No comments or replies yet. Start the conversation!</p>
                            </div>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <!-- Add Comment/Reply Form -->
                        <form method="POST" action="ticket_comment.php" id="commentForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
                            <input type="hidden" name="action" value="add">
                            
                            <div class="form-group">
                                <label>Add Comment or Reply</label>
                                <div class="bg-light p-3 rounded mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                You're commenting as: <strong><?php echo htmlspecialchars($session_user_name); ?></strong>
                                            </small>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-secondary" onclick="formatText('bold')" title="Bold">
                                                <i class="fas fa-bold"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="formatText('italic')" title="Italic">
                                                <i class="fas fa-italic"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="formatText('code')" title="Code">
                                                <i class="fas fa-code"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="showEmojiPicker()" title="Emoji">
                                                <i class="fas fa-smile"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="showTemplates()" title="Templates">
                                                <i class="fas fa-sticky-note"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" name="is_internal" id="is_internal">
                                    <label class="form-check-label" for="is_internal">
                                        <i class="fas fa-eye-slash mr-1"></i>Internal Comment (not visible to user)
                                    </label>
                                </div>
                                
                                <textarea class="form-control" name="comment_text" id="commentText" rows="4" 
                                          placeholder="Type your comment or reply here..." required></textarea>
                                
                                <div class="d-flex justify-content-between mt-2">
                                    <small class="text-muted" id="charCount">0/2000 characters</small>
                                    <small class="text-muted">
                                        Press <kbd>Ctrl</kbd> + <kbd>Enter</kbd> to submit
                                    </small>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearCommentForm()">
                                        <i class="fas fa-times mr-1"></i>Clear
                                    </button>
                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="saveAsDraft()">
                                        <i class="fas fa-save mr-1"></i>Save Draft
                                    </button>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane mr-2"></i>Post Comment
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Quick Templates Dropdown -->
                        <div class="dropdown-menu p-3" id="templateMenu" style="width: 300px; display: none;">
                            <h6 class="dropdown-header">Quick Templates</h6>
                            <button type="button" class="dropdown-item" onclick="applyTemplate('greeting')">
                                <i class="fas fa-handshake mr-2 text-info"></i>Greeting
                            </button>
                            <button type="button" class="dropdown-item" onclick="applyTemplate('update')">
                                <i class="fas fa-sync-alt mr-2 text-warning"></i>Status Update
                            </button>
                            <button type="button" class="dropdown-item" onclick="applyTemplate('question')">
                                <i class="fas fa-question-circle mr-2 text-primary"></i>Follow-up Question
                            </button>
                            <button type="button" class="dropdown-item" onclick="applyTemplate('resolution')">
                                <i class="fas fa-check-circle mr-2 text-success"></i>Resolution
                            </button>
                            <button type="button" class="dropdown-item" onclick="applyTemplate('escalation')">
                                <i class="fas fa-exclamation-triangle mr-2 text-danger"></i>Escalation
                            </button>
                        </div>
                        
                        <!-- Emoji Picker -->
                        <div class="bg-white border rounded p-3 shadow-sm" id="emojiPicker" style="position: absolute; z-index: 1000; display: none; max-width: 300px;">
                            <div class="d-flex justify-content-between mb-2">
                                <small class="text-muted">Select Emoji</small>
                                <button type="button" class="btn btn-sm btn-link text-danger" onclick="hideEmojiPicker()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="emoji-grid">
                                <?php 
                                $emojis = ['😊', '👍', '👎', '❤️', '🎉', '⚠️', '❓', 'ℹ️', '✅', '❌', '⏰', '🔒', '📋', '📎', '🔧'];
                                foreach ($emojis as $emoji): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary m-1" onclick="insertEmoji('<?php echo $emoji; ?>')">
                                        <?php echo $emoji; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success mb-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-bolt mr-2"></i>Quick Actions
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="ticket_edit.php?id=<?php echo $ticket_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Ticket
                            </a>
                            
                            <a href="ticket_assign.php?id=<?php echo $ticket_id; ?>" class="btn btn-info">
                                <i class="fas fa-user-plus mr-2"></i>Assign Ticket
                            </a>
                            
                            <?php if ($ticket['ticket_status'] != 'closed'): ?>
                                <a href="ticket_close.php?id=<?php echo $ticket_id; ?>" class="btn btn-success">
                                    <i class="fas fa-check-circle mr-2"></i>Close Ticket
                                </a>
                            <?php else: ?>
                                <a href="ticket_reopen.php?id=<?php echo $ticket_id; ?>" class="btn btn-warning">
                                    <i class="fas fa-redo mr-2"></i>Reopen Ticket
                                </a>
                            <?php endif; ?>
                            
                            <button type="button" class="btn btn-primary" onclick="scrollToComments()">
                                <i class="fas fa-comment mr-2"></i>Add Comment
                            </button>
                            
                            <a href="tickets.php" class="btn btn-outline-dark">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Tickets
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Ticket Timeline -->
                <div class="card card-info">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-history mr-2"></i>Timeline
                        </h4>
                    </div>
                    <div class="card-body">
                        <ul class="timeline">
                            <li>
                                <div class="timeline-item">
                                    <span class="time"><i class="fas fa-clock"></i> <?php echo date('M j, Y H:i', strtotime($ticket['created_at'])); ?></span>
                                    <h5 class="timeline-header">Ticket Created</h5>
                                    <div class="timeline-body">
                                        Created by <?php echo htmlspecialchars($ticket['created_by_name']); ?>
                                    </div>
                                </div>
                            </li>
                            
                            <?php if ($ticket['updated_at'] && $ticket['updated_at'] != $ticket['created_at']): ?>
                                <li>
                                    <div class="timeline-item">
                                        <span class="time"><i class="fas fa-clock"></i> <?php echo date('M j, Y H:i', strtotime($ticket['updated_at'])); ?></span>
                                        <h5 class="timeline-header">Ticket Updated</h5>
                                    </div>
                                </li>
                            <?php endif; ?>
                            
                            <?php if (!empty($comments)): ?>
                                <li>
                                    <div class="timeline-item">
                                        <span class="time"><i class="fas fa-clock"></i> <?php echo date('M j, Y H:i', strtotime($comments[0]['created_at'])); ?></span>
                                        <h5 class="timeline-header">Latest Activity</h5>
                                        <div class="timeline-body">
                                            <?php echo htmlspecialchars($comments[0]['user_name']); ?> added a <?php echo $comments[0]['type']; ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endif; ?>
                            
                            <?php if ($ticket['ticket_status'] == 'closed' && $ticket['closed_at']): ?>
                                <li>
                                    <div class="timeline-item">
                                        <span class="time"><i class="fas fa-clock"></i> <?php echo date('M j, Y H:i', strtotime($ticket['closed_at'])); ?></span>
                                        <h5 class="timeline-header">Ticket Closed</h5>
                                        <div class="timeline-body">
                                            Closed by <?php echo htmlspecialchars($ticket['closed_by_name']); ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- Conversation Stats -->
                <div class="card card-secondary mt-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-chart-bar mr-2"></i>Conversation Stats
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Messages:</span>
                                <span class="font-weight-bold"><?php echo count($comments); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Comments:</span>
                                <span class="font-weight-bold">
                                    <?php 
                                    $comment_count = 0;
                                    foreach ($comments as $comment) {
                                        if ($comment['type'] == 'comment') $comment_count++;
                                    }
                                    echo $comment_count;
                                    ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Replies:</span>
                                <span class="font-weight-bold">
                                    <?php 
                                    $reply_count = 0;
                                    foreach ($comments as $comment) {
                                        if ($comment['type'] == 'reply') $reply_count++;
                                    }
                                    echo $reply_count;
                                    ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Internal Notes:</span>
                                <span class="font-weight-bold">
                                    <?php 
                                    $internal_count = 0;
                                    foreach ($comments as $comment) {
                                        if ($comment['is_internal']) $internal_count++;
                                    }
                                    echo $internal_count;
                                    ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Last Activity:</span>
                                <span class="font-weight-bold">
                                    <?php 
                                    $last_activity = max(
                                        strtotime($ticket['created_at']),
                                        strtotime($ticket['updated_at'] ?? ''),
                                        strtotime($ticket['closed_at'] ?? ''),
                                        !empty($comments) ? strtotime($comments[0]['created_at']) : 0
                                    );
                                    echo $last_activity ? date('M j, H:i', $last_activity) : 'Never';
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Replies -->
                <div class="card card-warning mt-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-reply-all mr-2"></i>Quick Replies
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-info btn-sm text-left" onclick="setQuickReply('acknowledge')">
                                <i class="fas fa-check mr-2"></i>Acknowledged
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm text-left" onclick="setQuickReply('investigating')">
                                <i class="fas fa-search mr-2"></i>Investigating
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm text-left" onclick="setQuickReply('need_info')">
                                <i class="fas fa-question mr-2"></i>Need More Info
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm text-left" onclick="setQuickReply('update')">
                                <i class="fas fa-sync-alt mr-2"></i>Update Provided
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm text-left" onclick="setQuickReply('resolved')">
                                <i class="fas fa-check-circle mr-2"></i>Issue Resolved
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include Emoji Picker CSS -->
<style>
.timeline {
    margin: 0 0 45px;
    padding: 0;
    list-style: none;
}

.timeline > li {
    position: relative;
    margin-bottom: 20px;
}

.timeline > li:last-child {
    margin-bottom: 0;
}

.timeline > li:before {
    content: ' ';
    display: table;
}

.timeline > li:after {
    content: ' ';
    display: table;
    clear: both;
}

.timeline > li > .timeline-item {
    box-shadow: 0 1px 1px rgba(0,0,0,0.1);
    border-radius: 3px;
    margin-top: 0;
    background: #fff;
    color: #444;
    padding: 10px;
    position: relative;
}

.timeline > li > .timeline-item > .time {
    color: #999;
    float: right;
    font-size: 12px;
}

.timeline > li > .timeline-item > .timeline-header {
    margin: 0;
    color: #555;
    border-bottom: 1px solid #f4f4f4;
    padding-bottom: 5px;
    font-size: 14px;
    font-weight: 600;
}

.timeline > li > .timeline-item > .timeline-body {
    padding: 10px 0 0 0;
    font-size: 13px;
}

.comment-text {
    white-space: pre-wrap;
    word-wrap: break-word;
    line-height: 1.6;
}

.ticket-description, .ticket-resolution {
    white-space: pre-wrap;
    word-wrap: break-word;
    line-height: 1.6;
}

.media {
    border-bottom: 1px solid #eee;
    padding-bottom: 15px;
    margin-bottom: 15px;
}

.media:last-child {
    border-bottom: none;
    padding-bottom: 0;
    margin-bottom: 0;
}

.emoji-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 5px;
}

.comment-item {
    transition: all 0.3s ease;
}

.comment-item:hover {
    background-color: #f8f9fa;
    border-radius: 5px;
    padding: 10px;
    margin-left: -10px;
    margin-right: -10px;
}

.comment-item.highlight {
    animation: highlight 2s ease;
    background-color: #fff3cd;
}

@keyframes highlight {
    0% { background-color: #fff3cd; }
    100% { background-color: transparent; }
}

.quote {
    border-left: 3px solid #007bff;
    padding-left: 10px;
    margin: 10px 0;
    font-style: italic;
    background-color: #f8f9fa;
}
</style>

<script>
$(document).ready(function() {
    // Character counter for comment textarea
    $('#commentText').on('input', function() {
        const length = $(this).val().length;
        $('#charCount').text(length + '/2000 characters');
        if (length > 1800) {
            $('#charCount').addClass('text-danger').removeClass('text-warning');
        } else if (length > 1500) {
            $('#charCount').addClass('text-warning').removeClass('text-muted');
        }
    });
    
    // Load draft if exists
    loadDraft();
    
    // Set up keyboard shortcuts
    setupKeyboardShortcuts();
});

function toggleCommentsFilter() {
    $('#commentsFilter').slideToggle('fast');
}

function filterComments() {
    const typeFilter = $('#filterType').val();
    const userFilter = $('#filterUser').val();
    const sortOrder = $('#filterSort').val();
    
    $('.comment-item').each(function() {
        const type = $(this).data('type');
        const user = $(this).data('user');
        const isInternal = $(this).data('internal');
        const date = $(this).data('date');
        
        let show = true;
        
        // Type filter
        if (typeFilter === 'comments' && type !== 'comment') show = false;
        if (typeFilter === 'replies' && type !== 'reply') show = false;
        if (typeFilter === 'internal' && isInternal != '1') show = false;
        if (typeFilter === 'public' && isInternal == '1') show = false;
        
        // User filter
        if (userFilter !== 'all' && user !== userFilter) show = false;
        
        $(this).toggle(show);
    });
    
    // Sort comments
    const container = $('#commentsList');
    const items = container.children('.comment-item').get();
    
    items.sort(function(a, b) {
        const dateA = $(a).data('date');
        const dateB = $(b).data('date');
        
        if (sortOrder === 'newest') {
            return dateB - dateA;
        } else {
            return dateA - dateB;
        }
    });
    
    $.each(items, function(idx, itm) {
        container.append(itm);
    });
}

function formatText(format) {
    const textarea = $('#commentText')[0];
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = textarea.value.substring(start, end);
    
    let formattedText = '';
    let cursorOffset = 0;
    
    switch(format) {
        case 'bold':
            formattedText = '**' + selectedText + '**';
            cursorOffset = 2;
            break;
        case 'italic':
            formattedText = '*' + selectedText + '*';
            cursorOffset = 1;
            break;
        case 'code':
            formattedText = '`' + selectedText + '`';
            cursorOffset = 1;
            break;
    }
    
    textarea.value = textarea.value.substring(0, start) + formattedText + textarea.value.substring(end);
    textarea.focus();
    textarea.setSelectionRange(start + cursorOffset, end + cursorOffset);
}

function showEmojiPicker() {
    const picker = $('#emojiPicker');
    const button = $('button:contains("Emoji")');
    const offset = button.offset();
    
    picker.css({
        top: offset.top + button.outerHeight() + 5,
        left: offset.left
    }).toggle();
}

function hideEmojiPicker() {
    $('#emojiPicker').hide();
}

function insertEmoji(emoji) {
    const textarea = $('#commentText')[0];
    const start = textarea.selectionStart;
    textarea.value = textarea.value.substring(0, start) + emoji + textarea.value.substring(start);
    textarea.focus();
    textarea.setSelectionRange(start + emoji.length, start + emoji.length);
    hideEmojiPicker();
}

function showTemplates() {
    const menu = $('#templateMenu');
    const button = $('button:contains("Templates")');
    const offset = button.offset();
    
    menu.css({
        top: offset.top + button.outerHeight(),
        left: offset.left
    }).toggle();
}

function applyTemplate(template) {
    let templateText = '';
    
    switch(template) {
        case 'greeting':
            templateText = "Hello,\n\nThank you for your patience. I'm looking into this issue and will provide an update shortly.\n\nBest regards,\n<?php echo htmlspecialchars($session_user_name); ?>";
            break;
        case 'update':
            templateText = "Update: The issue is currently being investigated.\n\nStatus: In Progress\nNext Update: Within the next 2-4 hours\n\nI'll keep you posted on our progress.";
            break;
        case 'question':
            templateText = "To help me investigate this further, could you please provide:\n\n1. Steps to reproduce the issue\n2. Screenshots or error messages\n3. When this issue started occurring\n\nThis information will help me resolve this more quickly.";
            break;
        case 'resolution':
            templateText = "The issue has been resolved.\n\nResolution: [Describe the solution]\n\nPlease let me know if you're still experiencing any issues.\n\nBest regards,\n<?php echo htmlspecialchars($session_user_name); ?>";
            break;
        case 'escalation':
            templateText = "**ESCALATION NOTICE**\n\nThis issue has been escalated to our senior support team due to:\n- Critical business impact\n- Complexity requiring specialized knowledge\n- Need for immediate resolution\n\nYou will receive an update from the escalation team shortly.";
            break;
    }
    
    $('#commentText').val(templateText);
    $('#templateMenu').hide();
    updateCharCount();
}

function setQuickReply(type) {
    let reply = '';
    
    switch(type) {
        case 'acknowledge':
            reply = "Acknowledged. I've received your ticket and will begin investigating shortly.";
            break;
        case 'investigating':
            reply = "I'm currently investigating this issue. I'll provide an update within the hour.";
            break;
        case 'need_info':
            reply = "I need more information to proceed:\n\n• [What specific information is needed?]\n• [Any logs or screenshots?]\n\nPlease provide these details so I can continue investigating.";
            break;
        case 'update':
            reply = "Update: [Provide current status]\n\nNext Steps: [Describe what happens next]\n\nETA for resolution: [Provide timeframe]";
            break;
        case 'resolved':
            reply = "This issue has been resolved.\n\nResolution: [Brief description of solution]\n\nIf you experience any further issues, please don't hesitate to reopen this ticket.";
            break;
    }
    
    $('#commentText').val(reply);
    updateCharCount();
}

function editComment(commentId) {
    // Hide all edit forms first
    $('.edit-form').hide();
    // Show the text
    $('.comment-text').show();
    
    // Show edit form for this comment
    $('#edit-form-' + commentId).show();
    $('#comment-text-' + commentId).hide();
    $('#edit-text-' + commentId).focus();
}

function cancelEdit(commentId) {
    $('#edit-form-' + commentId).hide();
    $('#comment-text-' + commentId).show();
}

function saveCommentEdit(commentId, type) {
    const newText = $('#edit-text-' + commentId).val();
    const isInternal = $('#edit-internal-' + commentId).is(':checked') ? 1 : 0;
    
    if (!newText.trim()) {
        alert('Comment text cannot be empty');
        return;
    }
    
    $.ajax({
        url: 'ticket_comment.php',
        method: 'POST',
        data: {
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>',
            action: 'edit',
            comment_id: commentId,
            comment_type: type,
            comment_text: newText,
            is_internal: isInternal
        },
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                $('#comment-text-' + commentId).text(newText);
                cancelEdit(commentId);
                
                // Update internal badge
                const badge = $('#comment-text-' + commentId).closest('.media').find('.badge-info');
                if (isInternal) {
                    if (badge.length === 0) {
                        $('#comment-text-' + commentId).closest('.media').find('h6').append('<span class="badge badge-info ml-2">Internal</span>');
                    }
                } else {
                    badge.remove();
                }
                
                showToast('Comment updated successfully', 'success');
            } else {
                alert('Error: ' + result.message);
            }
        },
        error: function() {
            alert('Error updating comment');
        }
    });
}

function deleteComment(commentId, type) {
    if (!confirm('Are you sure you want to delete this comment?')) {
        return;
    }
    
    $.ajax({
        url: 'ticket_comment.php',
        method: 'POST',
        data: {
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>',
            action: 'delete',
            comment_id: commentId,
            comment_type: type
        },
        success: function(response) {
            const result = JSON.parse(response);
            if (result.success) {
                $('#comment-text-' + commentId).closest('.media').fadeOut(300, function() {
                    $(this).remove();
                    updateCommentCount();
                    showToast('Comment deleted successfully', 'success');
                });
            } else {
                alert('Error: ' + result.message);
            }
        },
        error: function() {
            alert('Error deleting comment');
        }
    });
}

function copyCommentText(commentId) {
    const text = $('#comment-text-' + commentId).text();
    navigator.clipboard.writeText(text).then(function() {
        showToast('Comment text copied to clipboard', 'info');
    }, function() {
        alert('Failed to copy text');
    });
}

function quoteComment(commentId) {
    const text = $('#comment-text-' + commentId).text();
    const user = $('#comment-text-' + commentId).closest('.media').find('h6').text().split(' ')[0];
    const quote = `> ${text}\n\n`;
    
    const textarea = $('#commentText')[0];
    const current = textarea.value;
    textarea.value = quote + (current ? '\n\n' + current : '');
    textarea.focus();
    updateCharCount();
    scrollToComments();
}

function clearCommentForm() {
    $('#commentText').val('');
    $('#is_internal').prop('checked', false);
    updateCharCount();
}

function saveAsDraft() {
    const draft = {
        text: $('#commentText').val(),
        isInternal: $('#is_internal').is(':checked'),
        timestamp: new Date().getTime(),
        ticketId: <?php echo $ticket_id; ?>
    };
    
    localStorage.setItem('ticket_comment_draft_' + <?php echo $ticket_id; ?>, JSON.stringify(draft));
    showToast('Draft saved locally', 'info');
}

function loadDraft() {
    const draftKey = 'ticket_comment_draft_' + <?php echo $ticket_id; ?>;
    const draft = localStorage.getItem(draftKey);
    
    if (draft) {
        const data = JSON.parse(draft);
        if (data.ticketId === <?php echo $ticket_id; ?>) {
            if (confirm('You have a saved draft. Load it?')) {
                $('#commentText').val(data.text);
                $('#is_internal').prop('checked', data.isInternal);
                updateCharCount();
            }
            localStorage.removeItem(draftKey);
        }
    }
}

function updateCharCount() {
    const length = $('#commentText').val().length;
    $('#charCount').text(length + '/2000 characters');
}

function scrollToComments() {
    $('html, body').animate({
        scrollTop: $('#commentText').offset().top - 100
    }, 500);
    $('#commentText').focus();
}

function setupKeyboardShortcuts() {
    $(document).keydown(function(e) {
        // Ctrl+Enter to submit comment
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            if ($('#commentText').is(':focus') && $('#commentText').val().trim()) {
                $('#commentForm').submit();
                e.preventDefault();
            }
        }
        
        // Esc to clear form
        if (e.key === 'Escape') {
            if ($('#commentText').is(':focus')) {
                clearCommentForm();
            }
        }
        
        // Ctrl+D to save draft
        if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
            if ($('#commentText').is(':focus')) {
                saveAsDraft();
                e.preventDefault();
            }
        }
    });
}

function updateCommentCount() {
    const count = $('.comment-item:visible').length;
    $('#commentsSection .card-title').html('<i class="fas fa-comments mr-2"></i>Conversation (' + count + ')');
}

function showToast(message, type) {
    // Create toast element
    const toast = $('<div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000">' +
        '<div class="toast-header bg-' + type + ' text-white">' +
            '<strong class="mr-auto">Notification</strong>' +
            '<button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">&times;</button>' +
        '</div>' +
        '<div class="toast-body">' + message + '</div>' +
    '</div>');
    
    // Add to container
    $('.toast-container').append(toast);
    
    // Show toast
    toast.toast('show');
    
    // Remove after hidden
    toast.on('hidden.bs.toast', function() {
        $(this).remove();
    });
}

// Add toast container to body if not exists
if ($('.toast-container').length === 0) {
    $('body').append('<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1050;"></div>');
}

// Close emoji picker when clicking outside
$(document).click(function(e) {
    if (!$(e.target).closest('#emojiPicker, button:contains("Emoji")').length) {
        hideEmojiPicker();
    }
    if (!$(e.target).closest('#templateMenu, button:contains("Templates")').length) {
        $('#templateMenu').hide();
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
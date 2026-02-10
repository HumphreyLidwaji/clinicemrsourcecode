<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['case_id']);

// Get case details - FIXED field names
$case_sql = "SELECT sc.*, p.first_name, p.last_name, p.patient_mrn 
             FROM surgical_cases sc 
             LEFT JOIN patients p ON sc.patient_id = p.patient_id 
             WHERE sc.case_id = $case_id";
$case_result = $mysqli->query($case_sql);

if ($case_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$case = $case_result->fetch_assoc();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_team_member'])) {
        $user_id = intval($_POST['user_id']);
        $role = sanitizeInput($_POST['role']);
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        
        // If custom role was selected, use custom_role value
        if (isset($_POST['custom_role']) && !empty($_POST['custom_role']) && $role == 'custom') {
            $role = sanitizeInput($_POST['custom_role']);
        }
        
        // Check if user is already in team
        $check_sql = "SELECT * FROM surgical_team WHERE case_id = $case_id AND user_id = $user_id";
        $check_result = $mysqli->query($check_sql);
        
        if ($check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = "This user is already in the surgical team";
        } else {
            // Insert new team member - FIXED: using correct table structure
            $insert_sql = "INSERT INTO surgical_team SET
                          case_id = $case_id,
                          user_id = $user_id,
                          role = '$role',
                          is_primary = $is_primary,
                          assigned_at = NOW(),
                          created_at = NOW()";
            
            if ($mysqli->query($insert_sql)) {
                // If this is primary, unset other primaries
                if ($is_primary) {
                    $update_sql = "UPDATE surgical_team SET is_primary = 0 
                                   WHERE case_id = $case_id AND user_id != $user_id";
                    $mysqli->query($update_sql);
                }
                
                // Log activity
                $user_name_sql = "SELECT user_name FROM users WHERE user_id = $user_id";
                $user_name_result = $mysqli->query($user_name_sql);
                $user_name = $user_name_result->fetch_assoc()['user_name'];
                
                $log_description = "Added $user_name to surgical team for case: " . $case['case_number'];
                mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Team', log_action = 'Add', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
                
                $_SESSION['alert_message'] = "Team member added successfully";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error adding team member: " . $mysqli->error;
            }
        }
        
        header("Location: surgical_team_management.php?case_id=$case_id");
        exit();
    }
    
    if (isset($_POST['update_team_member'])) {
        $surgical_team_id = intval($_POST['team_id']);
        $role = sanitizeInput($_POST['role']);
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        
        // If custom role was selected, use custom_role value
        if (isset($_POST['custom_role']) && !empty($_POST['custom_role']) && $role == 'custom') {
            $role = sanitizeInput($_POST['custom_role']);
        }
        
        // Update team member - FIXED: using surgical_team_id
        $update_sql = "UPDATE surgical_team SET
                      role = '$role',
                      is_primary = $is_primary
                      WHERE surgical_team_id = $surgical_team_id";
        
        if ($mysqli->query($update_sql)) {
            // If this is primary, unset other primaries
            if ($is_primary) {
                $clear_primary_sql = "UPDATE surgical_team SET is_primary = 0 
                                      WHERE case_id = $case_id AND surgical_team_id != $surgical_team_id";
                $mysqli->query($clear_primary_sql);
            }
            
            $_SESSION['alert_message'] = "Team member updated successfully";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating team member: " . $mysqli->error;
        }
        
        header("Location: surgical_team_management.php?case_id=$case_id");
        exit();
    }
    
    if (isset($_POST['remove_team_member'])) {
        $surgical_team_id = intval($_POST['team_id']);
        
        // Get user details for logging
        $team_sql = "SELECT u.user_name FROM surgical_team st 
                     JOIN users u ON st.user_id = u.user_id 
                     WHERE st.surgical_team_id = $surgical_team_id";
        $team_result = $mysqli->query($team_sql);
        $team_member = $team_result->fetch_assoc();
        
        // Remove team member - FIXED: using surgical_team_id
        $delete_sql = "DELETE FROM surgical_team WHERE surgical_team_id = $surgical_team_id";
        
        if ($mysqli->query($delete_sql)) {
            // Log activity
            $log_description = "Removed " . $team_member['user_name'] . " from surgical team for case: " . $case['case_number'];
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Team', log_action = 'Remove', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_message'] = "Team member removed successfully";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error removing team member: " . $mysqli->error;
        }
        
        header("Location: surgical_team_management.php?case_id=$case_id");
        exit();
    }
}

// Get current team members - FIXED field names
$team_sql = "SELECT st.*, u.user_name, u.user_email
             FROM surgical_team st 
             JOIN users u ON st.user_id = u.user_id 
             WHERE st.case_id = $case_id 
             ORDER BY st.is_primary DESC, u.user_name";
$team_result = $mysqli->query($team_sql);

// Get available users (excluding those already in team)
$team_user_ids = [];
while ($member = $team_result->fetch_assoc()) {
    $team_user_ids[] = $member['user_id'];
}
mysqli_data_seek($team_result, 0);

$exclude_ids = !empty($team_user_ids) ? implode(',', $team_user_ids) : '0';
$available_users_sql = "SELECT user_id, user_name, user_type 
                        FROM users 
                        WHERE user_id NOT IN ($exclude_ids) 
                        AND user_id != 0 
                        AND user_type IN ('Doctor', 'Surgeon', 'Nurse', 'Anesthetist', 'Technician')
                        ORDER BY user_name";
$available_users_result = $mysqli->query($available_users_sql);

// Get team statistics - FIXED field names
$team_stats_sql = "SELECT 
                    COUNT(*) as total_members,
                    COUNT(CASE WHEN is_primary = 1 THEN 1 END) as primary_members,
                    COUNT(DISTINCT role) as unique_roles
                   FROM surgical_team 
                   WHERE case_id = $case_id";
$team_stats_result = $mysqli->query($team_stats_sql);
$team_stats = $team_stats_result->fetch_assoc();

// Get role distribution - FIXED field names
$role_dist_sql = "SELECT role, COUNT(*) as count 
                  FROM surgical_team 
                  WHERE case_id = $case_id 
                  GROUP BY role 
                  ORDER BY count DESC";
$role_dist_result = $mysqli->query($role_dist_sql);

// Get role suggestions
$roles = [
    'Surgeon',
    'Assistant Surgeon',
    'Scrub Nurse',
    'Circulating Nurse',
    'Anesthetist',
    'Resident',
    'Medical Student',
    'Technician',
    'Radiographer',
    'Pathologist'
];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-user-md mr-2"></i>Surgical Team Management: <?php echo htmlspecialchars($case['case_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Case
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Team Members</span>
                        <span class="info-box-number"><?php echo $team_stats['total_members']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-star"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Primary Members</span>
                        <span class="info-box-number"><?php echo $team_stats['primary_members']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-tags"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Unique Roles</span>
                        <span class="info-box-number"><?php echo $team_stats['unique_roles']; ?></span>
                    </div>
                </div>
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

        <!-- Page Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Case:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($case['case_number']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Patient:</strong> 
                            <span class="badge badge-success ml-2">
                                <?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>MRN:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo htmlspecialchars($case['patient_mrn']); ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Case
                        </a>
                        <a href="theatre_dashboard.php" class="btn btn-light">
                            <i class="fas fa-tachometer-alt mr-2"></i>Theatre Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Team Members List -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-users mr-2"></i>Current Surgical Team</h4>
                            <span class="badge badge-secondary"><?php echo $team_result->num_rows; ?> members</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($team_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Team Member</th>
                                            <th>Role</th>
                                            <th>Primary</th>
                                            <th>Assigned</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($member = $team_result->fetch_assoc()): 
                                            $initials = substr($member['user_name'], 0, 2);
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar-circle mr-3">
                                                            <?php echo strtoupper($initials); ?>
                                                        </div>
                                                        <div>
                                                            <div class="font-weight-bold"><?php echo htmlspecialchars($member['user_name']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($member['user_email'] ?? ''); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($member['role']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if($member['is_primary']): ?>
                                                        <span class="badge badge-success">Primary</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-light">Secondary</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($member['assigned_at'])); ?></div>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($member['assigned_at'])); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-info" data-toggle="modal" data-target="#editMemberModal<?php echo $member['surgical_team_id']; ?>" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove this team member?');">
                                                            <input type="hidden" name="team_id" value="<?php echo $member['surgical_team_id']; ?>">
                                                            <button type="submit" name="remove_team_member" class="btn btn-danger" title="Remove">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            
                                            <!-- Edit Member Modal -->
                                            <div class="modal fade" id="editMemberModal<?php echo $member['surgical_team_id']; ?>" tabindex="-1" role="dialog">
                                                <div class="modal-dialog" role="document">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-primary">
                                                            <h5 class="modal-title text-white"><i class="fas fa-edit mr-2"></i>Edit Team Member</h5>
                                                            <button type="button" class="close text-white" data-dismiss="modal">
                                                                <span>&times;</span>
                                                            </button>
                                                        </div>
                                                        <form method="POST">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="team_id" value="<?php echo $member['surgical_team_id']; ?>">
                                                                
                                                                <div class="form-group">
                                                                    <label>Team Member</label>
                                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($member['user_name']); ?>" readonly>
                                                                </div>
                                                                
                                                                <div class="form-group">
                                                                    <label>Role</label>
                                                                    <select class="form-control" name="role" id="roleSelect<?php echo $member['surgical_team_id']; ?>" required>
                                                                        <option value="">Select Role</option>
                                                                        <?php foreach($roles as $role_option): ?>
                                                                            <option value="<?php echo $role_option; ?>" <?php if($member['role'] == $role_option) echo 'selected'; ?>><?php echo $role_option; ?></option>
                                                                        <?php endforeach; ?>
                                                                        <option value="custom" <?php if(!in_array($member['role'], $roles)) echo 'selected'; ?>>Custom Role</option>
                                                                    </select>
                                                                </div>
                                                                
                                                                <div class="form-group custom-role-container" id="customRoleContainer<?php echo $member['surgical_team_id']; ?>" style="<?php if(!in_array($member['role'], $roles)) echo 'display: block;'; else echo 'display: none;'; ?>">
                                                                    <input type="text" class="form-control" name="custom_role" placeholder="Enter custom role" value="<?php if(!in_array($member['role'], $roles)) echo htmlspecialchars($member['role']); ?>">
                                                                </div>
                                                                
                                                                <div class="form-group">
                                                                    <div class="custom-control custom-checkbox">
                                                                        <input type="checkbox" class="custom-control-input" name="is_primary" id="is_primary_<?php echo $member['surgical_team_id']; ?>" value="1" <?php if($member['is_primary']) echo 'checked'; ?>>
                                                                        <label class="custom-control-label" for="is_primary_<?php echo $member['surgical_team_id']; ?>">
                                                                            Mark as Primary Team Member
                                                                        </label>
                                                                        <small class="form-text text-muted">
                                                                            Only one team member can be primary.
                                                                        </small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="update_team_member" class="btn btn-primary">Save Changes</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-user-md fa-3x text-muted mb-3"></i>
                                <h4 class="text-muted">No Team Members Added</h4>
                                <p class="text-muted mb-4">Add team members using the form on the right.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Role Distribution -->
                <?php if ($role_dist_result->num_rows > 0): ?>
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Role Distribution</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <canvas id="roleDistributionChart" height="200"></canvas>
                            </div>
                            <div class="col-md-4">
                                <div class="list-group">
                                    <?php 
                                    $role_dist_result->data_seek(0);
                                    while ($role_dist = $role_dist_result->fetch_assoc()): 
                                    ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span><?php echo htmlspecialchars($role_dist['role']); ?></span>
                                            <span class="badge badge-primary badge-pill"><?php echo $role_dist['count']; ?></span>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar Information -->
            <div class="col-md-4">
                <!-- Add Team Member Form -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-plus mr-2"></i>Add Team Member</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="form-group">
                                <label>Select User</label>
                                <select class="form-control select2" name="user_id" required>
                                    <option value="">Select a user...</option>
                                    <?php if ($available_users_result->num_rows > 0): ?>
                                        <?php while ($user = $available_users_result->fetch_assoc()): ?>
                                            <option value="<?php echo $user['user_id']; ?>">
                                                <?php echo htmlspecialchars($user['user_name']); ?>
                                                <small class="text-muted">(<?php echo htmlspecialchars($user['user_type']); ?>)</small>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <option value="">All users are already in the team</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Role</label>
                                <select class="form-control" name="role" id="roleSelectAdd" required>
                                    <option value="">Select Role</option>
                                    <?php foreach($roles as $role): ?>
                                        <option value="<?php echo $role; ?>"><?php echo $role; ?></option>
                                    <?php endforeach; ?>
                                    <option value="custom">Custom Role</option>
                                </select>
                            </div>
                            
                            <div class="form-group" id="customRoleContainerAdd" style="display: none;">
                                <input type="text" class="form-control" name="custom_role" placeholder="Enter custom role">
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" name="is_primary" id="is_primary_add" value="1">
                                    <label class="custom-control-label" for="is_primary_add">
                                        Mark as Primary Team Member
                                    </label>
                                    <small class="form-text text-muted">
                                        Only one team member can be primary. Selecting this will unset any existing primary.
                                    </small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" name="add_team_member" class="btn btn-primary btn-block" <?php echo ($available_users_result->num_rows == 0) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-plus mr-2"></i>Add to Team
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Case Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Case Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Case Number:</th>
                                    <td><strong class="text-primary"><?php echo htmlspecialchars($case['case_number']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Patient:</th>
                                    <td>
                                        <strong><?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?></strong><br>
                                        <small class="text-muted">MRN: <?php echo htmlspecialchars($case['patient_mrn']); ?></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Surgical Specialty:</th>
                                    <td><?php echo htmlspecialchars($case['surgical_specialty']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Surgical Urgency:</th>
                                    <td>
                                        <?php
                                        $urgency_badge = '';
                                        switch($case['surgical_urgency']) {
                                            case 'emergency': $urgency_badge = 'danger'; break;
                                            case 'urgent': $urgency_badge = 'warning'; break;
                                            case 'elective': $urgency_badge = 'info'; break;
                                            default: $urgency_badge = 'secondary';
                                        }
                                        ?>
                                        <span class="badge badge-<?php echo $urgency_badge; ?>">
                                            <?php echo ucfirst($case['surgical_urgency']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php if($case['surgery_date']): ?>
                                <tr>
                                    <th class="text-muted">Surgery Date:</th>
                                    <td><?php echo date('M j, Y', strtotime($case['surgery_date'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Quick Role Suggestions -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Quick Role Suggestions</h4>
                    </div>
                    <div class="card-body">
                        <?php 
                        // Get commonly needed roles for this case type
                        $suggested_roles = [];
                        switch($case['surgical_specialty']) {
                            case 'general':
                                $suggested_roles = ['Surgeon', 'Assistant Surgeon', 'Scrub Nurse', 'Circulating Nurse'];
                                break;
                            case 'ortho':
                                $suggested_roles = ['Orthopedic Surgeon', 'Assistant Surgeon', 'Scrub Nurse', 'Circulating Nurse', 'Radiographer'];
                                break;
                            case 'cardio':
                                $suggested_roles = ['Cardiac Surgeon', 'Assistant Surgeon', 'Perfusionist', 'Scrub Nurse', 'Circulating Nurse'];
                                break;
                            case 'neuro':
                                $suggested_roles = ['Neurosurgeon', 'Assistant Surgeon', 'Scrub Nurse', 'Circulating Nurse', 'Neuromonitoring Tech'];
                                break;
                            default:
                                $suggested_roles = ['Surgeon', 'Assistant Surgeon', 'Scrub Nurse', 'Circulating Nurse', 'Anesthetist'];
                        }
                        ?>
                        
                        <div class="d-grid gap-2">
                            <?php foreach($suggested_roles as $suggested_role): ?>
                                <button type="button" class="btn btn-outline-secondary role-suggestion text-left" data-role="<?php echo $suggested_role; ?>">
                                    <i class="fas fa-plus mr-2"></i><?php echo $suggested_role; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Team Guidelines -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-clipboard-check mr-2"></i>Team Guidelines</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="fas fa-check text-success mr-2"></i>Assign at least one primary team member</li>
                            <li class="mb-2"><i class="fas fa-check text-success mr-2"></i>Include all necessary support personnel</li>
                            <li class="mb-2"><i class="fas fa-check text-success mr-2"></i>Specify clear roles and responsibilities</li>
                            <li class="mb-2"><i class="fas fa-check text-success mr-2"></i>Update team assignments as needed</li>
                            <li><i class="fas fa-check text-success mr-2"></i>Document any changes to team composition</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.user-avatar-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: #007bff;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
}
</style>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap'
    });

    // Handle custom role selection for add form
    $('#roleSelectAdd').change(function() {
        if ($(this).val() === 'custom') {
            $('#customRoleContainerAdd').show();
            $('#customRoleContainerAdd input').prop('required', true);
        } else {
            $('#customRoleContainerAdd').hide();
            $('#customRoleContainerAdd input').prop('required', false);
        }
    });
    
    // Handle custom role in edit modals
    $('.modal').on('show.bs.modal', function() {
        var modalId = $(this).attr('id');
        if (modalId && modalId.startsWith('editMemberModal')) {
            var teamId = modalId.replace('editMemberModal', '');
            var select = $(this).find('#roleSelect' + teamId);
            var container = $(this).find('#customRoleContainer' + teamId);
            
            // Trigger change on load
            if (select.val() === 'custom') {
                container.show();
                container.find('input').prop('required', true);
            } else {
                container.hide();
                container.find('input').prop('required', false);
            }
            
            // Bind change event
            select.off('change').on('change', function() {
                if ($(this).val() === 'custom') {
                    container.show();
                    container.find('input').prop('required', true);
                } else {
                    container.hide();
                    container.find('input').prop('required', false);
                }
            });
        }
    });

    // Quick role suggestions
    $('.role-suggestion').click(function() {
        var role = $(this).data('role');
        $('#roleSelectAdd').val('custom');
        $('#roleSelectAdd').trigger('change');
        $('input[name="custom_role"]').val(role);
        $('select[name="user_id"]').focus();
    });

    // Role Distribution Chart
    <?php if ($role_dist_result->num_rows > 0): ?>
    var roleData = {
        labels: [
            <?php 
            $role_dist_result->data_seek(0);
            while ($role_dist = $role_dist_result->fetch_assoc()): 
                echo "'" . addslashes($role_dist['role']) . "',";
            endwhile; 
            ?>
        ],
        datasets: [{
            data: [
                <?php 
                $role_dist_result->data_seek(0);
                while ($role_dist = $role_dist_result->fetch_assoc()): 
                    echo $role_dist['count'] . ",";
                endwhile; 
                ?>
            ],
            backgroundColor: [
                '#007bff', '#28a745', '#ffc107', '#dc3545', '#6c757d',
                '#17a2b8', '#6610f2', '#e83e8c', '#fd7e14', '#20c997'
            ]
        }]
    };

    var ctx = document.getElementById('roleDistributionChart').getContext('2d');
    var roleDistributionChart = new Chart(ctx, {
        type: 'doughnut',
        data: roleData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: {
                display: false
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        var dataset = data.datasets[tooltipItem.datasetIndex];
                        var total = dataset.data.reduce(function(previousValue, currentValue) {
                            return previousValue + currentValue;
                        });
                        var currentValue = dataset.data[tooltipItem.index];
                        var percentage = Math.floor(((currentValue/total) * 100) + 0.5);
                        return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                    }
                }
            }
        }
    });
    <?php endif; ?>
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to go back to case
    if (e.keyCode === 27) {
        window.location.href = 'surgical_case_view.php?id=<?php echo $case_id; ?>';
    }
    // Ctrl + A to focus on add form
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        $('select[name="user_id"]').focus();
    }
});
</script>

<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
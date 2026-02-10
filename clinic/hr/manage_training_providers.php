<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $provider_id = intval($_GET['id']);
    
    if ($_GET['action'] == 'toggle_status') {
        // Get current status
        $current_sql = "SELECT is_active FROM training_providers WHERE provider_id = ?";
        $current_stmt = $mysqli->prepare($current_sql);
        $current_stmt->bind_param("i", $provider_id);
        $current_stmt->execute();
        $current_status = $current_stmt->get_result()->fetch_assoc()['is_active'];
        
        $new_status = $current_status ? 0 : 1;
        
        $sql = "UPDATE training_providers SET is_active = ? WHERE provider_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $new_status, $provider_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_provider_updated', ?, 'training_providers', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $action = $new_status ? 'activated' : 'deactivated';
            $description = "$action training provider ID: $provider_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $provider_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Provider " . ($new_status ? "activated" : "deactivated") . " successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating provider: " . $stmt->error;
        }
        header("Location: manage_training_providers.php");
        exit;
    }
    
    if ($_GET['action'] == 'delete') {
        // Check if provider has courses
        $check_sql = "SELECT COUNT(*) as course_count FROM training_courses WHERE provider_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $provider_id);
        $check_stmt->execute();
        $course_count = $check_stmt->get_result()->fetch_assoc()['course_count'];
        
        if ($course_count > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Cannot delete provider with associated courses! Deactivate instead.";
        } else {
            $sql = "DELETE FROM training_providers WHERE provider_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $provider_id);
            
            if ($stmt->execute()) {
                // Log the action
                $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_provider_deleted', ?, 'training_providers', ?)";
                $audit_stmt = $mysqli->prepare($audit_sql);
                $description = "Deleted training provider ID: $provider_id";
                $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $provider_id);
                $audit_stmt->execute();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Provider deleted successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error deleting provider: " . $stmt->error;
            }
        }
        header("Location: manage_training_providers.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "tp.provider_name";
$order = "ASC";

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query with filters
$sql = "SELECT SQL_CALC_FOUND_ROWS 
            tp.*,
            (SELECT COUNT(*) FROM training_courses tc WHERE tc.provider_id = tp.provider_id) as course_count
        FROM training_providers tp 
        WHERE 1=1";

$params = [];
$types = '';

if (!empty($status_filter) && $status_filter != 'all') {
    $sql .= " AND tp.is_active = ?";
    $params[] = ($status_filter == 'active') ? 1 : 0;
    $types .= 'i';
}

if (!empty($search)) {
    $sql .= " AND (tp.provider_name LIKE ? OR tp.contact_person LIKE ? OR tp.nita_accreditation_no LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$sql .= " ORDER BY $sort $order LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$providers_result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_providers = $num_rows[0];
$active_providers = 0;
$inactive_providers = 0;

// Reset pointer and calculate
mysqli_data_seek($providers_result, 0);
while ($provider = mysqli_fetch_assoc($providers_result)) {
    if ($provider['is_active']) {
        $active_providers++;
    } else {
        $inactive_providers++;
    }
}
mysqli_data_seek($providers_result, $record_from);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-building mr-2"></i>Manage Training Providers</h3>
        <div class="card-tools">
            <a href="training_dashboard.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search providers, contact persons, NITA numbers..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group mr-2">
                            <a href="add_training_provider.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>Add Provider
                            </a>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-building text-primary mr-1"></i>
                                Total: <strong><?php echo $total_providers; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Active: <strong><?php echo $active_providers; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-times-circle text-danger mr-1"></i>
                                Inactive: <strong><?php echo $inactive_providers; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter != 'all') { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible m-3">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    <?php endif; ?>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>Provider Name</th>
                <th>Contact Person</th>
                <th>NITA Accreditation</th>
                <th>Contact Info</th>
                <th>Courses</th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($providers_result)) {
                $provider_id = intval($row['provider_id']);
                $provider_name = nullable_htmlentities($row['provider_name']);
                $contact_person = nullable_htmlentities($row['contact_person'] ?? 'N/A');
                $nita_accreditation_no = nullable_htmlentities($row['nita_accreditation_no'] ?? 'Not Accredited');
                $phone = nullable_htmlentities($row['phone'] ?? 'N/A');
                $email = nullable_htmlentities($row['email'] ?? 'N/A');
                $course_count = intval($row['course_count']);
                $is_active = $row['is_active'];
                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold"><?php echo $provider_name; ?></div>
                        <?php if (!empty($row['address'])): ?>
                            <small class="text-muted"><?php echo substr(strip_tags($row['address']), 0, 50); ?>...</small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $contact_person; ?></td>
                    <td>
                        <?php if ($nita_accreditation_no != 'Not Accredited'): ?>
                            <span class="badge badge-success"><?php echo $nita_accreditation_no; ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary"><?php echo $nita_accreditation_no; ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small">
                            <div><i class="fas fa-phone text-muted mr-1"></i> <?php echo $phone; ?></div>
                            <?php if ($email != 'N/A'): ?>
                                <div><i class="fas fa-envelope text-muted mr-1"></i> <?php echo $email; ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="font-weight-bold <?php echo $course_count > 0 ? 'text-success' : 'text-muted'; ?>">
                            <?php echo $course_count; ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $is_active ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="view_training_provider.php?id=<?php echo $provider_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="edit_training_provider.php?id=<?php echo $provider_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Provider
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="manage_training_providers.php?action=toggle_status&id=<?php echo $provider_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-power-off mr-2"></i>
                                    <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                </a>
                                <?php if ($course_count == 0): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="manage_training_providers.php?action=delete&id=<?php echo $provider_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" data-confirm-message="Are you sure you want to delete <?php echo htmlspecialchars($provider_name); ?>? This action cannot be undone.">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Provider
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <i class="fas fa-building fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No training providers found</h5>
                        <p class="text-muted">No providers match your current filters.</p>
                        <a href="?status=all" class="btn btn-primary mt-2">
                            <i class="fas fa-redo mr-2"></i>Reset Filters
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + F for focus search
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('input[name="search"]').focus();
        }
    });

    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var href = $(this).attr('href');
        
        if (confirm(message)) {
            window.location.href = href;
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
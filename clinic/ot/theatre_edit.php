<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get theatre ID from URL
$theatre_id = intval($_GET['id'] ?? 0);

if ($theatre_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid theatre ID.";
    header("Location: theatres.php");
    exit;
}

// Fetch theatre details
$theatre_sql = "SELECT t.*, 
                       u.user_name as created_by_name,
                       mu.user_name as modified_by_name
                FROM theatres t
                LEFT JOIN users u ON t.created_by = u.user_id
                LEFT JOIN users mu ON t.modified_by = mu.user_id
                WHERE t.theatre_id = ? 
                AND t.archived_at IS NULL";

$theatre_stmt = $mysqli->prepare($theatre_sql);
$theatre_stmt->bind_param("i", $theatre_id);
$theatre_stmt->execute();
$theatre_result = $theatre_stmt->get_result();

if ($theatre_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Theatre not found or has been archived.";
    header("Location: theatres.php");
    exit;
}

$theatre = $theatre_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $theatre_number = sanitizeInput($_POST['theatre_number']);
    $theatre_name = sanitizeInput($_POST['theatre_name']);
    $location = sanitizeInput($_POST['location']);
    $capacity = intval($_POST['capacity']);
    $floor_area = $_POST['floor_area'] ? floatval($_POST['floor_area']) : null;
    $air_changes_per_hour = $_POST['air_changes_per_hour'] ? intval($_POST['air_changes_per_hour']) : null;
    $description = sanitizeInput($_POST['description']);
    $status = sanitizeInput($_POST['status']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: theatre_edit.php?id=$theatre_id");
        exit;
    }
    
    // Validate required fields
    $errors = [];
    
    if (empty($theatre_number)) {
        $errors[] = "Theatre number is required.";
    }
    
    if (empty($theatre_name)) {
        $errors[] = "Theatre name is required.";
    }
    
    if (empty($location)) {
        $errors[] = "Location is required.";
    }
    
    if ($capacity <= 0) {
        $errors[] = "Capacity must be a positive number.";
    }
    
    // Check for duplicate theatre number
    $duplicate_sql = "SELECT theatre_id FROM theatres 
                      WHERE theatre_number = ? 
                      AND theatre_id != ? 
                      AND archived_at IS NULL";
    $duplicate_stmt = $mysqli->prepare($duplicate_sql);
    $duplicate_stmt->bind_param("si", $theatre_number, $theatre_id);
    $duplicate_stmt->execute();
    $duplicate_result = $duplicate_stmt->get_result();
    
    if ($duplicate_result->num_rows > 0) {
        $errors[] = "Theatre number already exists. Please choose a different number.";
    }
    
    if (empty($errors)) {
        // Update theatre
        $update_sql = "UPDATE theatres SET 
                      theatre_number = ?,
                      theatre_name = ?,
                      location = ?,
                      capacity = ?,
                      floor_area = ?,
                      air_changes_per_hour = ?,
                      description = ?,
                      status = ?,
                      modified_by = ?,
                      modified_at = NOW()
                      WHERE theatre_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sssiissiii", 
            $theatre_number,
            $theatre_name,
            $location,
            $capacity,
            $floor_area,
            $air_changes_per_hour,
            $description,
            $status,
            $session_user_id,
            $theatre_id
        );
        
        if ($update_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Theatre updated successfully.";
            
            // Log the activity
            $activity_description = "Updated theatre: OT $theatre_number - $theatre_name";
            mysqli_query($mysqli, "INSERT INTO activity_logs (activity_description, activity_type, user_id, theatre_id) VALUES ('$activity_description', 'theatre_updated', $session_user_id, $theatre_id)");
            
            header("Location: theatre_view.php?id=$theatre_id");
            exit;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating theatre: " . $mysqli->error;
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
    }
}

// Fetch equipment for this theatre
$equipment_sql = "SELECT e.equipment_id, e.equipment_name, e.equipment_type, e.model,
                         e.serial_number, e.status, e.last_maintenance_date,
                         DATEDIFF(CURDATE(), e.last_maintenance_date) as days_since_maintenance
                  FROM theatre_equipment e
                  WHERE e.theatre_id = ?
                  AND e.is_active = 1
                  AND e.archived_at IS NULL
                  ORDER BY e.equipment_type, e.equipment_name";

$equipment_stmt = $mysqli->prepare($equipment_sql);
$equipment_stmt->bind_param("i", $theatre_id);
$equipment_stmt->execute();
$equipment = $equipment_stmt->get_result();

// Status options
$status_options = [
    'available' => ['label' => 'Available', 'badge' => 'badge-success', 'icon' => 'fa-check-circle'],
    'in_use' => ['label' => 'In Use', 'badge' => 'badge-warning', 'icon' => 'fa-procedures'],
    'maintenance' => ['label' => 'Maintenance', 'badge' => 'badge-danger', 'icon' => 'fa-tools'],
    'cleaning' => ['label' => 'Cleaning', 'badge' => 'badge-info', 'icon' => 'fa-broom']
];
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-dark">
            <i class="fas fa-fw fa-edit mr-2"></i>
            Edit Theatre: OT <?php echo htmlspecialchars($theatre['theatre_number']); ?> - <?php echo htmlspecialchars($theatre['theatre_name']); ?>
        </h3>
        <div class="card-tools">
            <a href="theatre_view.php?id=<?php echo $theatre_id; ?>" class="btn btn-light">
                <i class="fas fa-eye mr-2"></i>View Theatre
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
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

    <div class="card-body">
        <form method="POST" id="theatreEditForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Theatre Information -->
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Theatre Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="theatre_number">Theatre Number <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">OT</span>
                                            </div>
                                            <input type="text" class="form-control" id="theatre_number" name="theatre_number" 
                                                   value="<?php echo htmlspecialchars($theatre['theatre_number']); ?>" 
                                                   required maxlength="10" placeholder="e.g., 01, 02A">
                                        </div>
                                        <small class="form-text text-muted">Unique identifier for the theatre</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="theatre_name">Theatre Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="theatre_name" name="theatre_name" 
                                               value="<?php echo htmlspecialchars($theatre['theatre_name']); ?>" 
                                               required maxlength="100" placeholder="e.g., Main Operating Theatre">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="location">Location <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="location" name="location" 
                                               value="<?php echo htmlspecialchars($theatre['location']); ?>" 
                                               required maxlength="200" placeholder="e.g., 2nd Floor, East Wing">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="capacity">Capacity (Persons) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="capacity" name="capacity" 
                                               value="<?php echo $theatre['capacity']; ?>" 
                                               required min="1" max="50" placeholder="Maximum number of persons">
                                        <small class="form-text text-muted">Including surgical team and equipment</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="floor_area">Floor Area (sq.ft)</label>
                                        <input type="number" class="form-control" id="floor_area" name="floor_area" 
                                               value="<?php echo $theatre['floor_area']; ?>" 
                                               min="0" max="5000" step="0.1" placeholder="Square footage">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="air_changes_per_hour">Air Changes Per Hour</label>
                                        <input type="number" class="form-control" id="air_changes_per_hour" name="air_changes_per_hour" 
                                               value="<?php echo $theatre['air_changes_per_hour']; ?>" 
                                               min="0" max="100" placeholder="Ventilation rate">
                                        <small class="form-text text-muted">Recommended: 15-25 changes/hour for OT</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" maxlength="500" 
                                          placeholder="Additional details about the theatre, special equipment, or notes"><?php echo htmlspecialchars($theatre['description']); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status & Actions -->
                <div class="col-md-4">
                    <!-- Status & System Info -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-cog mr-2"></i>Status & Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="status">Status <span class="text-danger">*</span></label>
                                <select class="form-control" id="status" name="status" required>
                                    <?php foreach($status_options as $value => $option): ?>
                                        <option value="<?php echo $value; ?>" 
                                                data-badge="<?php echo $option['badge']; ?>"
                                                data-icon="<?php echo $option['icon']; ?>"
                                                <?php echo $theatre['status'] == $value ? 'selected' : ''; ?>>
                                            <?php echo $option['label']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="border-top pt-3 mt-3">
                                <h6 class="text-muted">System Information</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Created:</td>
                                        <td>
                                            <?php echo date('M j, Y H:i', strtotime($theatre['created_at'])); ?>
                                            <small class="text-muted">by <?php echo htmlspecialchars($theatre['created_by_name']); ?></small>
                                        </td>
                                    </tr>
                                    <?php if($theatre['modified_at']): ?>
                                    <tr>
                                        <td class="text-muted">Last Modified:</td>
                                        <td>
                                            <?php echo date('M j, Y H:i', strtotime($theatre['modified_at'])); ?>
                                            <?php if($theatre['modified_by_name']): ?>
                                                <small class="text-muted">by <?php echo htmlspecialchars($theatre['modified_by_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Theatre
                                </button>
                                <a href="theatre_view.php?id=<?php echo $theatre_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#archiveModal">
                                    <i class="fas fa-archive mr-2"></i>Archive Theatre
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Equipment Overview -->
<div class="card mt-4">
    <div class="card-header bg-info text-white py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="card-title mb-0">
                <i class="fas fa-tools mr-2"></i>Assigned Equipment
                <span class="badge badge-light ml-2"><?php echo $equipment->num_rows; ?> items</span>
            </h4>
            <a href="theatre_equipment.php?id=<?php echo $theatre_id; ?>" class="btn btn-light btn-sm">
                <i class="fas fa-cog mr-1"></i>Manage Equipment
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($equipment->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Equipment Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Last Maintenance</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($item = $equipment->fetch_assoc()): 
                            $maintenance_due = ($item['days_since_maintenance'] ?? 0) > 180;
                            $equipment_status_badge = $item['status'] == 'active' ? 'badge-success' : ($item['status'] == 'maintenance' ? 'badge-warning' : 'badge-danger');
                        ?>
                            <tr class="<?php echo $maintenance_due ? 'table-warning' : ''; ?>">
                                <td class="font-weight-bold"><?php echo htmlspecialchars($item['equipment_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['equipment_type']); ?></td>
                                <td>
                                    <span class="badge <?php echo $equipment_status_badge; ?>">
                                        <?php echo ucfirst($item['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <?php echo $item['last_maintenance_date'] ? date('M j, Y', strtotime($item['last_maintenance_date'])) : 'Never'; ?>
                                    </small>
                                    <?php if($maintenance_due): ?>
                                        <br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Due</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="dropdown dropleft">
                                        <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item" href="equipment_edit.php?id=<?php echo $item['equipment_id']; ?>">
                                                <i class="fas fa-fw fa-edit mr-2"></i>Edit Equipment
                                            </a>
                                            <a class="dropdown-item" href="maintenance_new.php?equipment_id=<?php echo $item['equipment_id']; ?>">
                                                <i class="fas fa-fw fa-wrench mr-2"></i>Schedule Maintenance
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-tools fa-2x text-muted mb-2"></i>
                <p class="text-muted mb-0">No equipment assigned to this theatre</p>
                <a href="theatre_equipment.php?id=<?php echo $theatre_id; ?>" class="btn btn-primary mt-2">
                    <i class="fas fa-plus mr-1"></i>Add Equipment
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Archive Confirmation Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-archive mr-2"></i>Archive Theatre
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span class="text-white">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Warning:</strong> This action cannot be undone.
                </div>
                <p>Are you sure you want to archive <strong>OT <?php echo htmlspecialchars($theatre['theatre_number']); ?> - <?php echo htmlspecialchars($theatre['theatre_name']); ?></strong>?</p>
                <p class="text-muted">Archived theatres will not appear in regular lists but can be restored if needed.</p>
                
                <!-- Check for active surgeries -->
                <?php
                $active_surgeries_sql = "SELECT COUNT(*) as active_count FROM surgeries 
                                        WHERE theatre_id = ? 
                                        AND scheduled_date >= CURDATE() 
                                        AND status IN ('scheduled', 'confirmed', 'in_progress')
                                        AND archived_at IS NULL";
                $active_stmt = $mysqli->prepare($active_surgeries_sql);
                $active_stmt->bind_param("i", $theatre_id);
                $active_stmt->execute();
                $active_result = $active_stmt->get_result();
                $active_count = $active_result->fetch_assoc()['active_count'];
                ?>
                
                <?php if ($active_count > 0): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-ban mr-2"></i>
                        Cannot archive theatre with active or upcoming surgeries.
                        <br><strong><?php echo $active_count; ?> active surgery(s) found.</strong>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <?php if ($active_count == 0): ?>
                    <a href="post.php?archive_theatre=<?php echo $theatre_id; ?>" class="btn btn-danger confirm-action" 
                       data-message="Are you sure you want to archive this theatre? This action cannot be undone.">
                        <i class="fas fa-archive mr-2"></i>Archive Theatre
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-danger" disabled>
                        <i class="fas fa-ban mr-2"></i>Cannot Archive
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Update status preview
    function updateStatusPreview() {
        const selectedOption = $('#status option:selected');
        const badgeClass = selectedOption.data('badge');
        const iconClass = selectedOption.data('icon');
        const statusText = selectedOption.text();
        
        $('#statusPreview').remove();
        $('#status').after('<div id="statusPreview" class="mt-2"><span class="badge ' + badgeClass + '"><i class="fas ' + iconClass + ' mr-1"></i>' + statusText + '</span></div>');
    }
    
    // Initial preview
    updateStatusPreview();
    
    // Update preview when status changes
    $('#status').change(updateStatusPreview);

    // Confirm action links
    $('.confirm-action').click(function(e) {
        if (!confirm($(this).data('message'))) {
            e.preventDefault();
        }
    });

    // Form validation
    $('#theatreEditForm').submit(function(e) {
        let valid = true;
        const theatreNumber = $('#theatre_number').val().trim();
        const theatreName = $('#theatre_name').val().trim();
        const location = $('#location').val().trim();
        const capacity = $('#capacity').val();

        // Clear previous errors
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();

        // Validate theatre number
        if (!theatreNumber) {
            $('#theatre_number').addClass('is-invalid').after('<div class="invalid-feedback">Theatre number is required</div>');
            valid = false;
        }

        // Validate theatre name
        if (!theatreName) {
            $('#theatre_name').addClass('is-invalid').after('<div class="invalid-feedback">Theatre name is required</div>');
            valid = false;
        }

        // Validate location
        if (!location) {
            $('#location').addClass('is-invalid').after('<div class="invalid-feedback">Location is required</div>');
            valid = false;
        }

        // Validate capacity
        if (!capacity || capacity <= 0) {
            $('#capacity').addClass('is-invalid').after('<div class="invalid-feedback">Capacity must be a positive number</div>');
            valid = false;
        }

        if (!valid) {
            e.preventDefault();
            // Scroll to first error
            $('.is-invalid').first().focus();
        } else {
            // Show loading state
            $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S for save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#theatreEditForm').submit();
    }
    // Ctrl + D for discard/cancel
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        window.location.href = 'theatre_view.php?id=<?php echo $theatre_id; ?>';
    }
    // Ctrl + E for equipment
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'theatre_equipment.php?id=<?php echo $theatre_id; ?>';
    }
    // Ctrl + M for maintenance
    if (e.ctrlKey && e.keyCode === 77) {
        e.preventDefault();
        window.location.href = 'theatre_maintenance.php?id=<?php echo $theatre_id; ?>';
    }
});
</script>

<style>
.invalid-feedback {
    display: block;
}
.card {
    border: 1px solid #e3e6f0;
}
.form-control:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}
.table-borderless td {
    border: none !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $theatre_number = sanitizeInput($_POST['theatre_number']);
    $theatre_name = sanitizeInput($_POST['theatre_name']);
    $description = sanitizeInput($_POST['description']);
    $location = sanitizeInput($_POST['location']);
    $capacity = intval($_POST['capacity']);
    $floor_area = $_POST['floor_area'] ? floatval($_POST['floor_area']) : null;
    $air_changes_per_hour = $_POST['air_changes_per_hour'] ? intval($_POST['air_changes_per_hour']) : null;
    $equipment_available = sanitizeInput($_POST['equipment_available']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: theatre_new.php");
        exit;
    }

    // Validate required fields
    if (empty($theatre_number) || empty($theatre_name) || empty($location)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        header("Location: theatre_new.php");
        exit;
    }

    // Check if theatre number already exists
    $check_sql = "SELECT theatre_id FROM theatres WHERE theatre_number = ? AND archived_at IS NULL";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $theatre_number);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Theatre number already exists. Please use a different theatre number.";
        header("Location: theatre_new.php");
        exit;
    }

    // Insert new theatre
    $insert_sql = "INSERT INTO theatres (
        theatre_number, theatre_name, description, location, 
        capacity, floor_area, air_changes_per_hour,
        equipment_available, status, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'available', ?)";

    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param(
        "ssssiissi",
        $theatre_number,
        $theatre_name,
        $description,
        $location,
        $capacity,
        $floor_area,
        $air_changes_per_hour,
        $equipment_available,
        $session_user_id
    );

    if ($insert_stmt->execute()) {
        $new_theatre_id = $insert_stmt->insert_id;
        
        // Log the activity
        $activity_description = "Created new theatre: OT $theatre_number - $theatre_name";
        mysqli_query($mysqli, "INSERT INTO activity_logs (activity_description, activity_type, user_id) VALUES ('$activity_description', 'theatre_created', $session_user_id)");
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Operation Theatre created successfully!";
        header("Location: theatre_view.php?id=$new_theatre_id");
        exit;
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating theatre: " . $mysqli->error;
        header("Location: theatre_new.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Operation Theatre
        </h3>
        <div class="card-tools">
            <a href="theatres.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Theatres
            </a>
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

        <form method="POST" id="theatreForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Theatre Information -->
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Theatre Information</h4>
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
                                                   required maxlength="10" placeholder="e.g., 01, 02A">
                                        </div>
                                        <small class="form-text text-muted">Unique identifier for the theatre</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="theatre_name">Theatre Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="theatre_name" name="theatre_name" 
                                               required maxlength="100" placeholder="e.g., Main Operating Theatre">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="location">Location <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="location" name="location" 
                                               required maxlength="200" placeholder="e.g., 2nd Floor, East Wing">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="capacity">Capacity (Persons) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="capacity" name="capacity" 
                                               required min="1" max="50" value="10" placeholder="Maximum number of persons">
                                        <small class="form-text text-muted">Including surgical team and equipment</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="floor_area">Floor Area (sq.ft)</label>
                                        <input type="number" class="form-control" id="floor_area" name="floor_area" 
                                               min="0" max="5000" step="0.1" placeholder="Square footage">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="air_changes_per_hour">Air Changes Per Hour</label>
                                        <input type="number" class="form-control" id="air_changes_per_hour" name="air_changes_per_hour" 
                                               min="0" max="100" value="20" placeholder="Ventilation rate">
                                        <small class="form-text text-muted">Recommended: 15-25 changes/hour for OT</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" maxlength="500" 
                                          placeholder="Additional details about the theatre, special equipment, or notes..."></textarea>
                            </div>

                            <div class="form-group">
                                <label for="equipment_available">Available Equipment</label>
                                <textarea class="form-control" id="equipment_available" name="equipment_available" 
                                          rows="3" placeholder="List of standard equipment available in this theatre..."></textarea>
                                <small class="form-text text-muted">You can add specific equipment later in equipment management</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions & Preview -->
                <div class="col-md-4">
                    <!-- Form Actions -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Create Theatre
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="theatres.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Theatre Preview -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Theatre Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-hospital-alt fa-3x text-info mb-2"></i>
                                <h5 id="preview_theatre_name">Theatre Name</h5>
                                <div id="preview_theatre_number" class="text-muted">OT #</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Location:</span>
                                    <span id="preview_location" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Capacity:</span>
                                    <span id="preview_capacity" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Floor Area:</span>
                                    <span id="preview_floor_area" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Air Changes:</span>
                                    <span id="preview_air_changes" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="badge badge-success">Available</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Update preview in real-time
    $('#theatre_name').on('input', function() {
        $('#preview_theatre_name').text($(this).val() || 'Theatre Name');
    });

    $('#theatre_number').on('input', function() {
        $('#preview_theatre_number').text('OT ' + ($(this).val() || '#'));
    });

    $('#location').on('input', function() {
        $('#preview_location').text($(this).val() || '-');
    });

    $('#capacity').on('input', function() {
        $('#preview_capacity').text($(this).val() + ' person(s)');
    });

    $('#floor_area').on('input', function() {
        $('#preview_floor_area').text($(this).val() ? $(this).val() + ' sq.ft' : '-');
    });

    $('#air_changes_per_hour').on('input', function() {
        $('#preview_air_changes').text($(this).val() ? $(this).val() + '/hour' : '-');
    });

    // Form validation
    $('#theatreForm').on('submit', function(e) {
        var theatreNumber = $('#theatre_number').val().trim();
        var theatreName = $('#theatre_name').val().trim();
        var location = $('#location').val().trim();
        var capacity = $('#capacity').val();

        // Clear previous errors
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();

        let hasError = false;

        if (!theatreNumber) {
            $('#theatre_number').addClass('is-invalid').after('<div class="invalid-feedback">Theatre number is required</div>');
            hasError = true;
        }

        if (!theatreName) {
            $('#theatre_name').addClass('is-invalid').after('<div class="invalid-feedback">Theatre name is required</div>');
            hasError = true;
        }

        if (!location) {
            $('#location').addClass('is-invalid').after('<div class="invalid-feedback">Location is required</div>');
            hasError = true;
        }

        if (!capacity || capacity <= 0) {
            $('#capacity').addClass('is-invalid').after('<div class="invalid-feedback">Capacity must be a positive number</div>');
            hasError = true;
        }

        if (hasError) {
            e.preventDefault();
            // Scroll to first error
            $('.is-invalid').first().focus();
        } else {
            // Show loading state
            $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#theatreForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'theatres.php';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        $('#theatreForm').trigger('reset');
        // Reset preview
        $('#preview_theatre_name').text('Theatre Name');
        $('#preview_theatre_number').text('OT #');
        $('#preview_location').text('-');
        $('#preview_capacity').text('-');
        $('#preview_floor_area').text('-');
        $('#preview_air_changes').text('-');
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
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    

// Get equipment ID from URL if provided
$equipment_id = intval($_GET['equipment_id'] ?? 0);
$schedule_date = $_GET['schedule_date'] ?? date('Y-m-d');

// Fetch equipment details if equipment_id is provided
$equipment_name = "";
$equipment_type = "";
$theatre_name = "";
$theatre_number = "";

if ($equipment_id > 0) {
    $equipment_sql = "SELECT e.equipment_name, e.equipment_type, e.model_number, 
                             t.theatre_name, t.theatre_number, t.theatre_id
                      FROM theatre_equipment e
                      LEFT JOIN theatres t ON e.theatre_id = t.theatre_id
                      WHERE e.equipment_id = ? AND e.archived_at IS NULL";
    $equipment_stmt = $mysqli->prepare($equipment_sql);
    $equipment_stmt->bind_param("i", $equipment_id);
    $equipment_stmt->execute();
    $equipment_result = $equipment_stmt->get_result();
    
    if ($equipment_result->num_rows > 0) {
        $equipment_data = $equipment_result->fetch_assoc();
        $equipment_name = $equipment_data['equipment_name'];
        $equipment_type = $equipment_data['equipment_type'];
        $theatre_name = $equipment_data['theatre_name'];
        $theatre_number = $equipment_data['theatre_number'];
        $theatre_id = $equipment_data['theatre_id'];
    } else {
        $equipment_id = 0; // Invalid equipment ID
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_id = intval($_POST['equipment_id']);
    $maintenance_type = sanitizeInput($_POST['maintenance_type']);
    $description = sanitizeInput($_POST['description']);
    $scheduled_date = sanitizeInput($_POST['scheduled_date']);
    $priority = sanitizeInput($_POST['priority']);
    $estimated_duration = intval($_POST['estimated_duration']);
    $estimated_cost = $_POST['estimated_cost'] ? floatval($_POST['estimated_cost']) : null;
    $notes = sanitizeInput($_POST['notes']);
    $required_parts = sanitizeInput($_POST['required_parts']);
    $assigned_technician = $_POST['assigned_technician'] ? intval($_POST['assigned_technician']) : null;
    
    // Validate required fields
    $errors = [];
    
    if (empty($equipment_id)) {
        $errors[] = "Equipment selection is required.";
    }
    
    if (empty($maintenance_type)) {
        $errors[] = "Maintenance type is required.";
    }
    
    if (empty($description)) {
        $errors[] = "Description is required.";
    }
    
    if (empty($scheduled_date)) {
        $errors[] = "Scheduled date is required.";
    }
    
    if (empty($priority)) {
        $errors[] = "Priority is required.";
    }
    
    // Check if equipment is available on scheduled date
    if ($scheduled_date && $equipment_id) {
        $conflict_sql = "SELECT maintenance_id FROM maintenance 
                         WHERE equipment_id = ? 
                         AND scheduled_date = ? 
                         AND status IN ('scheduled', 'in_progress')
                         AND archived_at IS NULL";
        $conflict_stmt = $mysqli->prepare($conflict_sql);
        $conflict_stmt->bind_param("is", $equipment_id, $scheduled_date);
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();
        
        if ($conflict_result->num_rows > 0) {
            $errors[] = "This equipment already has maintenance scheduled for the selected date.";
        }
    }
    
    if (empty($errors)) {
        // Insert new maintenance record
        $insert_sql = "INSERT INTO maintenance (
            equipment_id, maintenance_type, description, scheduled_date, 
            priority, estimated_duration, estimated_cost, notes, required_parts,
            assigned_technician, status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("issssiissii", 
            $equipment_id,
            $maintenance_type,
            $description,
            $scheduled_date,
            $priority,
            $estimated_duration,
            $estimated_cost,
            $notes,
            $required_parts,
            $assigned_technician,
            $session_user_id
        );
        
        if ($insert_stmt->execute()) {
            $new_maintenance_id = $insert_stmt->insert_id;
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Maintenance scheduled successfully!";
            
            // Log the activity
            $activity_description = "Scheduled maintenance for equipment ID $equipment_id on $scheduled_date";
            mysqli_query($mysqli, "INSERT INTO activity_logs (activity_description, activity_type, user_id, maintenance_id) VALUES ('$activity_description', 'maintenance_scheduled', $session_user_id, $new_maintenance_id)");
            
            // Update equipment's next maintenance date
            $update_equipment_sql = "UPDATE theatre_equipment 
                                    SET next_maintenance_date = ?,
                                        modified_by = ?,
                                        modified_at = NOW()
                                    WHERE equipment_id = ?";
            $update_stmt = $mysqli->prepare($update_equipment_sql);
            $update_stmt->bind_param("sii", $scheduled_date, $session_user_id, $equipment_id);
            $update_stmt->execute();
            
            // Redirect based on button clicked
            if (isset($_POST['save_and_view'])) {
                header("Location: maintenance_view.php?id=$new_maintenance_id");
            } else {
                header("Location: equipment_maintenance.php?equipment_id=$equipment_id");
            }
            exit;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error scheduling maintenance: " . $mysqli->error;
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
    }
}

// Get available technicians
$technicians_sql = "SELECT user_id, user_name, user_email 
                    FROM users 
                    WHERE user_type IN ('technician', 'engineer') 
                    AND user_status = 'active'
                    ORDER BY user_name";
$technicians_result = $mysqli->query($technicians_sql);

// Common maintenance types
$maintenance_types = [
    'Preventive Maintenance',
    'Corrective Maintenance',
    'Calibration',
    'Software Update',
    'Hardware Repair',
    'Safety Check',
    'Performance Test',
    'Cleaning',
    'Lubrication',
    'Parts Replacement',
    'Electrical Check',
    'Mechanical Adjustment'
];
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-plus-circle mr-2"></i>
                    Schedule Maintenance
                    <?php if ($equipment_id): ?>
                        <small class="text-white-50">for <?php echo htmlspecialchars($equipment_name); ?></small>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-tools">
                <?php if ($equipment_id): ?>
                    <a href="equipment_view.php?id=<?php echo $equipment_id; ?>" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left mr-1"></i>Back to Equipment
                    </a>
                <?php else: ?>
                    <a href="equipment_maintenance.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left mr-1"></i>Back to Maintenance
                    </a>
                <?php endif; ?>
            </div>
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
        <form method="post" autocomplete="off" id="maintenanceForm">
            <div class="row">
                <!-- Left Column - Basic Information -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Maintenance Details</h5>
                        </div>
                        <div class="card-body">
                            <!-- Equipment Selection -->
                            <div class="form-group">
                                <label for="equipment_id">Equipment <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="equipment_id" name="equipment_id" required <?php echo $equipment_id ? 'disabled' : ''; ?>>
                                    <option value="">- Select Equipment -</option>
                                    <?php
                                    $equipment_list_sql = "SELECT e.equipment_id, e.equipment_name, e.equipment_type, 
                                                                  t.theatre_number, t.theatre_name
                                                           FROM theatre_equipment e
                                                           LEFT JOIN theatres t ON e.theatre_id = t.theatre_id
                                                           WHERE e.archived_at IS NULL 
                                                           AND e.status = 'active'
                                                           ORDER BY e.equipment_name";
                                    $equipment_list_result = $mysqli->query($equipment_list_sql);
                                    while($equip = $equipment_list_result->fetch_assoc()) {
                                        $e_id = intval($equip['equipment_id']);
                                        $e_name = htmlspecialchars($equip['equipment_name']);
                                        $e_type = htmlspecialchars($equip['equipment_type']);
                                        $t_number = htmlspecialchars($equip['theatre_number']);
                                        $selected = $equipment_id == $e_id ? 'selected' : '';
                                        echo "<option value='$e_id' $selected data-type='$e_type' data-theatre='OT $t_number - {$equip['theatre_name']}'>$e_name ($e_type) - OT $t_number</option>";
                                    }
                                    ?>
                                </select>
                                <?php if ($equipment_id): ?>
                                    <input type="hidden" name="equipment_id" value="<?php echo $equipment_id; ?>">
                                <?php endif; ?>
                            </div>

                            <!-- Equipment Info Display -->
                            <?php if ($equipment_id): ?>
                                <div class="alert alert-info">
                                    <div class="row">
                                        <div class="col-6">
                                            <strong>Equipment:</strong><br>
                                            <?php echo htmlspecialchars($equipment_name); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($equipment_type); ?></small>
                                        </div>
                                        <div class="col-6">
                                            <strong>Theatre:</strong><br>
                                            OT <?php echo htmlspecialchars($theatre_number); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($theatre_name); ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Maintenance Type -->
                            <div class="form-group">
                                <label for="maintenance_type">Maintenance Type <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="maintenance_type" name="maintenance_type" required>
                                    <option value="">- Select Type -</option>
                                    <?php foreach ($maintenance_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" 
                                            <?php echo ($_POST['maintenance_type'] ?? '') == $type ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="other">Other (specify in description)</option>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="form-group">
                                <label for="description">Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" required maxlength="500" 
                                          placeholder="Describe the maintenance work to be performed..."><?php echo $_POST['description'] ?? ''; ?></textarea>
                                <small class="form-text text-muted">Detailed description of the maintenance task</small>
                            </div>

                            <!-- Required Parts -->
                            <div class="form-group">
                                <label for="required_parts">Required Parts/Supplies</label>
                                <textarea class="form-control" id="required_parts" name="required_parts" 
                                          rows="2" maxlength="300" 
                                          placeholder="List any parts, tools, or supplies needed..."><?php echo $_POST['required_parts'] ?? ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Scheduling & Assignment -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0"><i class="fas fa-calendar-alt mr-2"></i>Scheduling & Assignment</h5>
                        </div>
                        <div class="card-body">
                            <!-- Scheduled Date -->
                            <div class="form-group">
                                <label for="scheduled_date">Scheduled Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="scheduled_date" name="scheduled_date" 
                                       value="<?php echo $_POST['scheduled_date'] ?? $schedule_date; ?>" 
                                       min="<?php echo date('Y-m-d'); ?>" required>
                                <small class="form-text text-muted">Date when maintenance should be performed</small>
                            </div>

                            <!-- Priority -->
                            <div class="form-group">
                                <label for="priority">Priority <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="priority" name="priority" required>
                                    <option value="">- Select Priority -</option>
                                    <option value="low" <?php echo ($_POST['priority'] ?? '') == 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo ($_POST['priority'] ?? '') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo ($_POST['priority'] ?? '') == 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="critical" <?php echo ($_POST['priority'] ?? '') == 'critical' ? 'selected' : ''; ?>>Critical</option>
                                </select>
                            </div>

                            <!-- Estimated Duration -->
                            <div class="form-group">
                                <label for="estimated_duration">Estimated Duration (minutes)</label>
                                <input type="number" class="form-control" id="estimated_duration" name="estimated_duration" 
                                       value="<?php echo $_POST['estimated_duration'] ?? 60; ?>" 
                                       min="15" max="480" step="15" placeholder="60">
                                <small class="form-text text-muted">Estimated time required for maintenance</small>
                            </div>

                            <!-- Estimated Cost -->
                            <div class="form-group">
                                <label for="estimated_cost">Estimated Cost ($)</label>
                                <input type="number" class="form-control" id="estimated_cost" name="estimated_cost" 
                                       value="<?php echo $_POST['estimated_cost'] ?? ''; ?>" 
                                       min="0" step="0.01" placeholder="0.00">
                                <small class="form-text text-muted">Estimated cost for parts and labor</small>
                            </div>

                            <!-- Assigned Technician -->
                            <div class="form-group">
                                <label for="assigned_technician">Assigned Technician</label>
                                <select class="form-control select2" id="assigned_technician" name="assigned_technician">
                                    <option value="">- Assign Technician -</option>
                                    <?php while($tech = $technicians_result->fetch_assoc()): ?>
                                        <option value="<?php echo $tech['user_id']; ?>" 
                                            <?php echo ($_POST['assigned_technician'] ?? '') == $tech['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tech['user_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <!-- Notes -->
                            <div class="form-group">
                                <label for="notes">Additional Notes</label>
                                <textarea class="form-control" id="notes" name="notes" 
                                          rows="3" maxlength="500" 
                                          placeholder="Any additional instructions or information..."><?php echo $_POST['notes'] ?? ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center">
                            <button type="submit" name="save_and_view" class="btn btn-success btn-lg mr-2">
                                <i class="fas fa-save mr-2"></i>Schedule & View Details
                            </button>
                            <button type="submit" name="save_and_list" class="btn btn-primary btn-lg mr-2">
                                <i class="fas fa-list mr-2"></i>Schedule & Return to List
                            </button>
                            <?php if ($equipment_id): ?>
                                <a href="equipment_view.php?id=<?php echo $equipment_id; ?>" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            <?php else: ?>
                                <a href="equipment_maintenance.php" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Quick Help Card -->
<div class="card mt-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0">
            <i class="fas fa-question-circle mr-2"></i>Scheduling Guidelines
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <h6><i class="fas fa-asterisk text-danger mr-1"></i> Required Fields</h6>
                <p class="small text-muted">Fields marked with asterisk (*) are mandatory for scheduling maintenance.</p>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-calendar-check text-warning mr-1"></i> Date Selection</h6>
                <p class="small text-muted">Select future dates only. Equipment cannot be scheduled for maintenance on the same day as other maintenance tasks.</p>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-tools text-success mr-1"></i> Priority Levels</h6>
                <p class="small text-muted">
                    <strong>Critical:</strong> Immediate attention required<br>
                    <strong>High:</strong> Schedule within 24-48 hours<br>
                    <strong>Medium:</strong> Schedule within the week<br>
                    <strong>Low:</strong> Routine maintenance
                </p>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });

    // Form validation
    $('#maintenanceForm').submit(function(e) {
        let valid = true;
        const equipmentId = $('#equipment_id').val();
        const maintenanceType = $('#maintenance_type').val();
        const description = $('#description').val().trim();
        const scheduledDate = $('#scheduled_date').val();
        const priority = $('#priority').val();

        // Clear previous errors
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();

        // Validate equipment selection
        if (!equipmentId) {
            $('#equipment_id').addClass('is-invalid').after('<div class="invalid-feedback">Please select equipment</div>');
            valid = false;
        }

        // Validate maintenance type
        if (!maintenanceType) {
            $('#maintenance_type').addClass('is-invalid').after('<div class="invalid-feedback">Maintenance type is required</div>');
            valid = false;
        }

        // Validate description
        if (!description) {
            $('#description').addClass('is-invalid').after('<div class="invalid-feedback">Description is required</div>');
            valid = false;
        }

        // Validate scheduled date
        if (!scheduledDate) {
            $('#scheduled_date').addClass('is-invalid').after('<div class="invalid-feedback">Scheduled date is required</div>');
            valid = false;
        } else {
            const selectedDate = new Date(scheduledDate);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (selectedDate < today) {
                $('#scheduled_date').addClass('is-invalid').after('<div class="invalid-feedback">Cannot schedule maintenance for past dates</div>');
                valid = false;
            }
        }

        // Validate priority
        if (!priority) {
            $('#priority').addClass('is-invalid').after('<div class="invalid-feedback">Priority is required</div>');
            valid = false;
        }

        if (!valid) {
            e.preventDefault();
            // Scroll to first error
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            showAlert('Please fix the errors in the form before submitting.', 'error');
        }
    });

    // Check for scheduling conflicts
    $('#scheduled_date, #equipment_id').change(function() {
        const equipmentId = $('#equipment_id').val();
        const scheduledDate = $('#scheduled_date').val();
        
        if (equipmentId && scheduledDate) {
            checkSchedulingConflict(equipmentId, scheduledDate);
        }
    });

    // Auto-fill description based on maintenance type
    $('#maintenance_type').change(function() {
        const type = $(this).val();
        const descriptionMap = {
            'Preventive Maintenance': 'Routine preventive maintenance and inspection',
            'Corrective Maintenance': 'Repair and correction of identified issues',
            'Calibration': 'Calibration and accuracy verification',
            'Software Update': 'Software/firmware update and testing',
            'Hardware Repair': 'Hardware component repair or replacement',
            'Safety Check': 'Safety systems verification and testing',
            'Performance Test': 'Performance verification and optimization',
            'Cleaning': 'Thorough cleaning and sanitization',
            'Lubrication': 'Lubrication of mechanical components',
            'Parts Replacement': 'Replacement of worn or damaged parts',
            'Electrical Check': 'Electrical systems inspection and testing',
            'Mechanical Adjustment': 'Mechanical alignment and adjustment'
        };
        
        if (descriptionMap[type] && !$('#description').val()) {
            $('#description').val(descriptionMap[type]);
        }
    });

    // Helper function to check scheduling conflicts
    function checkSchedulingConflict(equipmentId, scheduledDate) {
        $.get('ajax/check_maintenance_conflict.php', {
            equipment_id: equipmentId,
            scheduled_date: scheduledDate
        }, function(response) {
            if (response.conflict) {
                $('#scheduled_date').addClass('is-invalid');
                if (!$('#scheduled_date').next('.invalid-feedback').length) {
                    $('#scheduled_date').after('<div class="invalid-feedback">Equipment already has maintenance scheduled on this date</div>');
                }
                showAlert('Warning: This equipment already has maintenance scheduled on the selected date.', 'warning');
            } else {
                $('#scheduled_date').removeClass('is-invalid');
                $('#scheduled_date').next('.invalid-feedback').remove();
            }
        });
    }

    // Helper function to show alerts
    function showAlert(message, type) {
        const alertClass = type === 'success' ? 'alert-success' : type === 'warning' ? 'alert-warning' : 'alert-danger';
        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                <i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'} mr-2"></i>
                ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `;
        $('#maintenanceForm').prepend(alertHtml);
        
        // Auto-remove alert after 5 seconds
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    }
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S for save (save and view)
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('button[name="save_and_view"]').click();
    }
    // Ctrl + Enter for save and list
    if (e.ctrlKey && e.keyCode === 13) {
        e.preventDefault();
        $('button[name="save_and_list"]').click();
    }
});
</script>

<style>
.select2-container .select2-selection--single {
    height: 38px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px !important;
}
.card {
    border: 1px solid #e3e6f0;
}
.invalid-feedback {
    display: block;
}
.form-text {
    font-size: 0.875rem;
}
</style>

<?php 
     require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    ?>
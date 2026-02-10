<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    

// Get theatre ID from URL if provided
$theatre_id = intval($_GET['theatre_id'] ?? 0);
$theatre_name = "";
$theatre_number = "";

if ($theatre_id > 0) {
    // Fetch theatre details
    $theatre_sql = "SELECT theatre_name, theatre_number FROM theatres WHERE theatre_id = ? AND archived_at IS NULL";
    $theatre_stmt = $mysqli->prepare($theatre_sql);
    $theatre_stmt->bind_param("i", $theatre_id);
    $theatre_stmt->execute();
    $theatre_result = $theatre_stmt->get_result();
    
    if ($theatre_result->num_rows > 0) {
        $theatre_data = $theatre_result->fetch_assoc();
        $theatre_name = $theatre_data['theatre_name'];
        $theatre_number = $theatre_data['theatre_number'];
    } else {
        $theatre_id = 0; // Invalid theatre ID
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_name = sanitizeInput($_POST['equipment_name']);
    $equipment_type = sanitizeInput($_POST['equipment_type']);
    $model_number = sanitizeInput($_POST['model_number']);
    $serial_number = sanitizeInput($_POST['serial_number']);
    $manufacturer = sanitizeInput($_POST['manufacturer']);
    $purchase_date = sanitizeInput($_POST['purchase_date']);
    $purchase_cost = $_POST['purchase_cost'] ? floatval($_POST['purchase_cost']) : null;
    $warranty_expiry = sanitizeInput($_POST['warranty_expiry']);
    $last_maintenance_date = sanitizeInput($_POST['last_maintenance_date']);
    $next_maintenance_date = sanitizeInput($_POST['next_maintenance_date']);
    $maintenance_interval_days = $_POST['maintenance_interval_days'] ? intval($_POST['maintenance_interval_days']) : 180;
    $specifications = sanitizeInput($_POST['specifications']);
    $notes = sanitizeInput($_POST['notes']);
    $status = sanitizeInput($_POST['status']);
    $selected_theatre_id = intval($_POST['theatre_id']);
    
    // Validate required fields
    $errors = [];
    
    if (empty($equipment_name)) {
        $errors[] = "Equipment name is required.";
    }
    
    if (empty($equipment_type)) {
        $errors[] = "Equipment type is required.";
    }
    
    if ($selected_theatre_id <= 0) {
        $errors[] = "Please select a theatre for the equipment.";
    }
    
    // Check for duplicate serial number if provided
    if (!empty($serial_number)) {
        $duplicate_sql = "SELECT equipment_id FROM theatre_equipment 
                          WHERE serial_number = ? 
                          AND archived_at IS NULL";
        $duplicate_stmt = $mysqli->prepare($duplicate_sql);
        $duplicate_stmt->bind_param("s", $serial_number);
        $duplicate_stmt->execute();
        $duplicate_result = $duplicate_stmt->get_result();
        
        if ($duplicate_result->num_rows > 0) {
            $errors[] = "Serial number already exists. Please use a different serial number or leave blank if not available.";
        }
    }
    
    // Validate dates
    if ($purchase_date && !validateDate($purchase_date)) {
        $errors[] = "Invalid purchase date format.";
    }
    
    if ($warranty_expiry && !validateDate($warranty_expiry)) {
        $errors[] = "Invalid warranty expiry date format.";
    }
    
    if ($last_maintenance_date && !validateDate($last_maintenance_date)) {
        $errors[] = "Invalid last maintenance date format.";
    }
    
    if ($next_maintenance_date && !validateDate($next_maintenance_date)) {
        $errors[] = "Invalid next maintenance date format.";
    }
    
    if (empty($errors)) {
        // Insert new equipment
        $insert_sql = "INSERT INTO theatre_equipment (
            equipment_name, equipment_type, model_number, serial_number, 
            manufacturer, purchase_date, purchase_cost, warranty_expiry,
            last_maintenance_date, next_maintenance_date, maintenance_interval_days,
            specifications, notes, status, theatre_id, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("ssssssdsssisssii", 
            $equipment_name,
            $equipment_type,
            $model_number,
            $serial_number,
            $manufacturer,
            $purchase_date,
            $purchase_cost,
            $warranty_expiry,
            $last_maintenance_date,
            $next_maintenance_date,
            $maintenance_interval_days,
            $specifications,
            $notes,
            $status,
            $selected_theatre_id,
            $session_user_id
        );
        
        if ($insert_stmt->execute()) {
            $new_equipment_id = $insert_stmt->insert_id;
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Equipment added successfully!";
            
            // Log the activity
            $activity_description = "Added new equipment: $equipment_name ($equipment_type)";
            mysqli_query($mysqli, "INSERT INTO activity_logs (activity_description, activity_type, user_id, equipment_id) VALUES ('$activity_description', 'equipment_created', $session_user_id, $new_equipment_id)");
            
            // Redirect to equipment view or list
            if (isset($_POST['save_and_view'])) {
                header("Location: equipment_view.php?id=$new_equipment_id");
            } else {
                header("Location: theatre_equipment.php?theatre_id=$selected_theatre_id");
            }
            exit;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding equipment: " . $mysqli->error;
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
    }
}

// Common equipment types for suggestions
$common_equipment_types = [
    'Anesthesia Machine',
    'Patient Monitor',
    'Ventilator',
    'Defibrillator',
    'Electrosurgical Unit',
    'Surgical Table',
    'Operating Light',
    'Suction Apparatus',
    'C-Arm X-Ray',
    'Ultrasound Machine',
    'Endoscopy Tower',
    'Laparoscopy System',
    'Microscope',
    'Drill System',
    'Saw System',
    'Insufflator',
    'Warming Cabinet',
    'Sterilizer',
    'Aspirator',
    'Infusion Pump'
];

// Common manufacturers
$common_manufacturers = [
    'GE Healthcare',
    'Siemens Healthineers',
    'Philips Healthcare',
    'Medtronic',
    'Stryker',
    'Johnson & Johnson',
    'Boston Scientific',
    'Baxter International',
    'Fresenius Medical Care',
    'B. Braun',
    'Smith & Nephew',
    'Zimmer Biomet',
    'Getinge',
    'Hill-Rom',
    'Draeger',
    'Mindray',
    'Nihon Kohden',
    'Fukuda Denshi',
    'Schiller',
    'Welch Allyn'
];

// Function to validate date
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-plus-circle mr-2"></i>
                    Add New Equipment
                    <?php if ($theatre_id): ?>
                        <small class="text-white-50">for OT <?php echo $theatre_number; ?> - <?php echo $theatre_name; ?></small>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-tools">
                <?php if ($theatre_id): ?>
                    <a href="theatre_equipment.php?theatre_id=<?php echo $theatre_id; ?>" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left mr-1"></i>Back to Equipment
                    </a>
                <?php else: ?>
                    <a href="theatre_equipment.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left mr-1"></i>Back to Equipment
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
        <form method="post" autocomplete="off" id="equipmentForm">
            <?php if ($theatre_id): ?>
                <input type="hidden" name="theatre_id" value="<?php echo $theatre_id; ?>">
            <?php endif; ?>
            
            <div class="row">
                <!-- Left Column - Basic Information -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Basic Information</h5>
                        </div>
                        <div class="card-body">
                            <!-- Equipment Name -->
                            <div class="form-group">
                                <label for="equipment_name">Equipment Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="equipment_name" name="equipment_name" 
                                       value="<?php echo $_POST['equipment_name'] ?? ''; ?>" 
                                       required maxlength="100" placeholder="e.g., Anesthesia Workstation, Patient Monitor">
                                <small class="form-text text-muted">Descriptive name for the equipment</small>
                            </div>

                            <!-- Equipment Type -->
                            <div class="form-group">
                                <label for="equipment_type">Equipment Type <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="equipment_type" name="equipment_type" 
                                       value="<?php echo $_POST['equipment_type'] ?? ''; ?>" 
                                       required maxlength="50" list="equipmentTypes" placeholder="Select or type equipment type">
                                <datalist id="equipmentTypes">
                                    <?php foreach ($common_equipment_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="form-text text-muted">Category or type of equipment</small>
                            </div>

                            <!-- Theatre Selection -->
                            <div class="form-group">
                                <label for="theatre_id">Operation Theatre <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="theatre_id" name="theatre_id" required <?php echo $theatre_id ? 'disabled' : ''; ?>>
                                    <option value="">- Select Theatre -</option>
                                    <?php
                                    $theatres_sql = "SELECT theatre_id, theatre_number, theatre_name, location 
                                                    FROM theatres 
                                                    WHERE archived_at IS NULL 
                                                    AND status != 'maintenance'
                                                    ORDER BY theatre_number";
                                    $theatres_result = $mysqli->query($theatres_sql);
                                    while($theatre = $theatres_result->fetch_assoc()) {
                                        $t_id = intval($theatre['theatre_id']);
                                        $t_number = htmlspecialchars($theatre['theatre_number']);
                                        $t_name = htmlspecialchars($theatre['theatre_name']);
                                        $t_location = htmlspecialchars($theatre['location']);
                                        $selected = ($theatre_id && $t_id == $theatre_id) ? 'selected' : (($_POST['theatre_id'] ?? '') == $t_id ? 'selected' : '');
                                        echo "<option value='$t_id' $selected data-location='$t_location'>OT $t_number - $t_name</option>";
                                    }
                                    ?>
                                </select>
                                <?php if ($theatre_id): ?>
                                    <input type="hidden" name="theatre_id" value="<?php echo $theatre_id; ?>">
                                <?php endif; ?>
                                <small class="form-text text-muted">Theatre where this equipment will be located</small>
                            </div>

                            <!-- Model and Serial Numbers -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="model_number">Model Number</label>
                                        <input type="text" class="form-control" id="model_number" name="model_number" 
                                               value="<?php echo $_POST['model_number'] ?? ''; ?>" 
                                               maxlength="50" placeholder="e.g., Aisys CS2, IntelliVue MX40">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="serial_number">Serial Number</label>
                                        <input type="text" class="form-control" id="serial_number" name="serial_number" 
                                               value="<?php echo $_POST['serial_number'] ?? ''; ?>" 
                                               maxlength="50" placeholder="Unique serial number">
                                        <small class="form-text text-muted">Must be unique if provided</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Manufacturer -->
                            <div class="form-group">
                                <label for="manufacturer">Manufacturer</label>
                                <input type="text" class="form-control" id="manufacturer" name="manufacturer" 
                                       value="<?php echo $_POST['manufacturer'] ?? ''; ?>" 
                                       maxlength="100" list="manufacturers" placeholder="Select or type manufacturer">
                                <datalist id="manufacturers">
                                    <?php foreach ($common_manufacturers as $manufacturer): ?>
                                        <option value="<?php echo htmlspecialchars($manufacturer); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <!-- Status -->
                            <div class="form-group">
                                <label for="status">Status <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="status" name="status" required>
                                    <option value="active" <?php echo ($_POST['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="maintenance" <?php echo ($_POST['status'] ?? '') == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="inactive" <?php echo ($_POST['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <small class="form-text text-muted">Initial status of the equipment</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Purchase & Maintenance -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0"><i class="fas fa-shopping-cart mr-2"></i>Purchase & Maintenance</h5>
                        </div>
                        <div class="card-body">
                            <!-- Purchase Information -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="purchase_date">Purchase Date</label>
                                        <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                               value="<?php echo $_POST['purchase_date'] ?? ''; ?>" 
                                               max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="purchase_cost">Purchase Cost ($)</label>
                                        <input type="number" class="form-control" id="purchase_cost" name="purchase_cost" 
                                               value="<?php echo $_POST['purchase_cost'] ?? ''; ?>" 
                                               min="0" step="0.01" placeholder="0.00">
                                    </div>
                                </div>
                            </div>

                            <!-- Warranty Information -->
                            <div class="form-group">
                                <label for="warranty_expiry">Warranty Expiry Date</label>
                                <input type="date" class="form-control" id="warranty_expiry" name="warranty_expiry" 
                                       value="<?php echo $_POST['warranty_expiry'] ?? ''; ?>">
                                <small class="form-text text-muted">Date when warranty coverage ends</small>
                            </div>

                            <!-- Maintenance Information -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="last_maintenance_date">Last Maintenance Date</label>
                                        <input type="date" class="form-control" id="last_maintenance_date" name="last_maintenance_date" 
                                               value="<?php echo $_POST['last_maintenance_date'] ?? ''; ?>" 
                                               max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="next_maintenance_date">Next Maintenance Date</label>
                                        <input type="date" class="form-control" id="next_maintenance_date" name="next_maintenance_date" 
                                               value="<?php echo $_POST['next_maintenance_date'] ?? ''; ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Maintenance Interval -->
                            <div class="form-group">
                                <label for="maintenance_interval_days">Maintenance Interval (Days)</label>
                                <input type="number" class="form-control" id="maintenance_interval_days" name="maintenance_interval_days" 
                                       value="<?php echo $_POST['maintenance_interval_days'] ?? 180; ?>" 
                                       min="30" max="365" placeholder="180">
                                <small class="form-text text-muted">Recommended days between maintenance (default: 180 days)</small>
                            </div>

                            <!-- Auto-calculate next maintenance -->
                            <div class="form-group">
                                <button type="button" class="btn btn-outline-info btn-sm" id="calculateNextMaintenance">
                                    <i class="fas fa-calculator mr-1"></i>Calculate Next Maintenance
                                </button>
                                <small class="form-text text-muted">Automatically calculate next maintenance date based on last maintenance and interval</small>
                            </div>
                        </div>
                    </div>

                    <!-- Specifications & Notes -->
                    <div class="card mt-4">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0"><i class="fas fa-file-alt mr-2"></i>Additional Information</h5>
                        </div>
                        <div class="card-body">
                            <!-- Specifications -->
                            <div class="form-group">
                                <label for="specifications">Specifications</label>
                                <textarea class="form-control" id="specifications" name="specifications" 
                                          rows="3" maxlength="500" 
                                          placeholder="Technical specifications, features, or capabilities"><?php echo $_POST['specifications'] ?? ''; ?></textarea>
                                <small class="form-text text-muted">Technical details and capabilities</small>
                            </div>

                            <!-- Notes -->
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" 
                                          rows="2" maxlength="500" 
                                          placeholder="Additional notes, comments, or special instructions"><?php echo $_POST['notes'] ?? ''; ?></textarea>
                                <small class="form-text text-muted">Any additional information or special instructions</small>
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
                                <i class="fas fa-save mr-2"></i>Save & View Equipment
                            </button>
                            <button type="submit" name="save_and_list" class="btn btn-primary btn-lg mr-2">
                                <i class="fas fa-list mr-2"></i>Save & Return to List
                            </button>
                            <?php if ($theatre_id): ?>
                                <a href="theatre_equipment.php?theatre_id=<?php echo $theatre_id; ?>" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            <?php else: ?>
                                <a href="theatre_equipment.php" class="btn btn-secondary btn-lg">
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
            <i class="fas fa-question-circle mr-2"></i>Quick Help
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <h6><i class="fas fa-asterisk text-danger mr-1"></i> Required Fields</h6>
                <p class="small text-muted">Fields marked with asterisk (*) are mandatory and must be filled.</p>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-key text-warning mr-1"></i> Serial Numbers</h6>
                <p class="small text-muted">Serial numbers must be unique. Leave blank if not available.</p>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-calculator text-success mr-1"></i> Maintenance</h6>
                <p class="small text-muted">Use the calculator to automatically set next maintenance date.</p>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });

    // Calculate next maintenance date
    $('#calculateNextMaintenance').click(function() {
        const lastMaintenanceDate = $('#last_maintenance_date').val();
        const intervalDays = $('#maintenance_interval_days').val() || 180;
        
        if (lastMaintenanceDate) {
            const lastDate = new Date(lastMaintenanceDate);
            const nextDate = new Date(lastDate);
            nextDate.setDate(nextDate.getDate() + parseInt(intervalDays));
            
            const formattedDate = nextDate.toISOString().split('T')[0];
            $('#next_maintenance_date').val(formattedDate);
            
            // Show success message
            showAlert('Next maintenance date calculated successfully!', 'success');
        } else {
            showAlert('Please enter last maintenance date first.', 'warning');
        }
    });

    // Form validation
    $('#equipmentForm').submit(function(e) {
        let valid = true;
        const equipmentName = $('#equipment_name').val().trim();
        const equipmentType = $('#equipment_type').val().trim();
        const theatreId = $('#theatre_id').val();

        // Clear previous errors
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();

        // Validate equipment name
        if (!equipmentName) {
            $('#equipment_name').addClass('is-invalid').after('<div class="invalid-feedback">Equipment name is required</div>');
            valid = false;
        }

        // Validate equipment type
        if (!equipmentType) {
            $('#equipment_type').addClass('is-invalid').after('<div class="invalid-feedback">Equipment type is required</div>');
            valid = false;
        }

        // Validate theatre selection
        if (!theatreId) {
            $('#theatre_id').addClass('is-invalid').after('<div class="invalid-feedback">Please select a theatre</div>');
            valid = false;
        }

        // Validate dates
        const purchaseDate = $('#purchase_date').val();
        const warrantyExpiry = $('#warranty_expiry').val();
        const lastMaintenance = $('#last_maintenance_date').val();
        const nextMaintenance = $('#next_maintenance_date').val();

        if (purchaseDate && !isValidDate(purchaseDate)) {
            $('#purchase_date').addClass('is-invalid').after('<div class="invalid-feedback">Invalid purchase date</div>');
            valid = false;
        }

        if (warrantyExpiry && !isValidDate(warrantyExpiry)) {
            $('#warranty_expiry').addClass('is-invalid').after('<div class="invalid-feedback">Invalid warranty expiry date</div>');
            valid = false;
        }

        if (lastMaintenance && !isValidDate(lastMaintenance)) {
            $('#last_maintenance_date').addClass('is-invalid').after('<div class="invalid-feedback">Invalid last maintenance date</div>');
            valid = false;
        }

        if (nextMaintenance && !isValidDate(nextMaintenance)) {
            $('#next_maintenance_date').addClass('is-invalid').after('<div class="invalid-feedback">Invalid next maintenance date</div>');
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

    // Auto-fill manufacturer based on equipment type
    $('#equipment_type').on('input', function() {
        const type = $(this).val().toLowerCase();
        const manufacturerMap = {
            'anesthesia': 'GE Healthcare',
            'monitor': 'Philips Healthcare',
            'ventilator': 'Draeger',
            'defibrillator': 'Philips Healthcare',
            'surgical': 'Stryker',
            'table': 'Maquet',
            'light': 'Berchtold',
            'c-arm': 'Siemens Healthineers',
            'ultrasound': 'GE Healthcare',
            'endoscopy': 'Olympus',
            'laparoscopy': 'Stryker',
            'microscope': 'Leica',
            'drill': 'Stryker',
            'saw': 'Stryker'
        };

        for (const [key, value] of Object.entries(manufacturerMap)) {
            if (type.includes(key)) {
                $('#manufacturer').val(value);
                break;
            }
        }
    });

    // Show today's date as placeholder for dates
    const today = new Date().toISOString().split('T')[0];
    $('#purchase_date').attr('placeholder', today);
    $('#last_maintenance_date').attr('placeholder', today);

    // Helper function to validate date
    function isValidDate(dateString) {
        const regEx = /^\d{4}-\d{2}-\d{2}$/;
        if (!dateString.match(regEx)) return false;
        const d = new Date(dateString);
        const dNum = d.getTime();
        if (!dNum && dNum !== 0) return false;
        return d.toISOString().slice(0,10) === dateString;
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
        $('#equipmentForm').prepend(alertHtml);
        
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
    // Ctrl + Q for calculate maintenance
    if (e.ctrlKey && e.keyCode === 81) {
        e.preventDefault();
        $('#calculateNextMaintenance').click();
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
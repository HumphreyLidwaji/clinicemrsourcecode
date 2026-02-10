<?php
// patient_edit.php - Edit Patient
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Get patient_id from URL
$patient_id = intval($_GET['patient_id'] ?? 0);

if ($patient_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid patient ID";
    header("Location: patients.php");
    exit;
}

// Get patient data
$patient_sql = "SELECT p.* 
                FROM patients p 
                WHERE p.patient_id = ? AND p.patient_status != 'ARCHIVED'";
$patient_stmt = $mysqli->prepare($patient_sql);
$patient_stmt->bind_param("i", $patient_id);
$patient_stmt->execute();
$patient_result = $patient_stmt->get_result();
$patient = $patient_result->fetch_assoc();

if (!$patient) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Patient not found";
    header("Location: patients.php");
    exit;
}

//CAPTURE OLD DATA (Already Loaded)
// Immediately store old data before POST handling
$old_data = $patient;

// Remove non-business fields from audit
unset(
    $old_data['updated_at'],
    $old_data['updated_by'],
    $old_data['created_at'],
    $old_data['created_by'],
    $old_data['patient_status'],
    $old_data['archived_at']
);

// Get next of kin
$kin_sql = "SELECT * FROM patient_next_of_kin WHERE patient_id = ?";
$kin_stmt = $mysqli->prepare($kin_sql);
$kin_stmt->bind_param("i", $patient_id);
$kin_stmt->execute();
$kin_result = $kin_stmt->get_result();
$next_of_kin = $kin_result->fetch_assoc();

// Get patient statistics from visits
$stats_sql = "SELECT 
                COUNT(*) as total_visits,
                MAX(visit_datetime) as last_visit
              FROM visits 
              WHERE patient_id = ?";
$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("i", $patient_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Patient demographics
    $first_name = sanitizeInput($_POST['first_name']);
    $middle_name = sanitizeInput($_POST['middle_name']);
    $last_name = sanitizeInput($_POST['last_name']);
    // MRN is not updated - keep original
    $date_of_birth = sanitizeInput($_POST['date_of_birth']);
    $sex = sanitizeInput($_POST['sex']);
    $blood_group = sanitizeInput($_POST['blood_group']);
    $id_type = sanitizeInput($_POST['id_type']);
    $id_number = sanitizeInput($_POST['id_number']);
    $phone_primary = sanitizeInput($_POST['phone_primary']);
    $phone_secondary = sanitizeInput($_POST['phone_secondary']);
    $email = sanitizeInput($_POST['email']);
    
    // Kenyan address structure
    $county = sanitizeInput($_POST['county']);
    $sub_county = sanitizeInput($_POST['sub_county']);
    $ward = sanitizeInput($_POST['ward']);
    $village = sanitizeInput($_POST['village']);
    $postal_address = sanitizeInput($_POST['postal_address']);
    $postal_code = sanitizeInput($_POST['postal_code']);
    
    // Next of kin
    $kin_full_name = sanitizeInput($_POST['kin_full_name']);
    $kin_relationship = sanitizeInput($_POST['kin_relationship']);
    $kin_phone = sanitizeInput($_POST['kin_phone']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: patient_edit.php?patient_id=" . $patient_id);
        exit;
    }

    // Validate required fields
    if (empty($first_name) || empty($last_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields (First Name and Last Name).";
        header("Location: patient_edit.php?patient_id=" . $patient_id);
        exit;
    }

    // Validate ID number uniqueness (excluding current patient) if provided
    if (!empty($id_number)) {
        $id_check_sql = "SELECT patient_id FROM patients WHERE id_number = ? AND patient_id != ?";
        $id_check_stmt = $mysqli->prepare($id_check_sql);
        $id_check_stmt->bind_param("si", $id_number, $patient_id);
        $id_check_stmt->execute();
        $id_check_result = $id_check_stmt->get_result();

        if ($id_check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "ID Number already exists. Please use a different one.";
            header("Location: patient_edit.php?patient_id=" . $patient_id);
            exit;
        }
    }

    //BUILD NEW DATA (After Sanitizing POST)
    $new_data = [
        'first_name'      => $first_name,
        'middle_name'     => $middle_name,
        'last_name'       => $last_name,
        'date_of_birth'   => $date_of_birth,
        'sex'             => $sex,
        'blood_group'     => $blood_group,
        'id_type'         => $id_type,
        'id_number'       => $id_number,
        'phone_primary'   => $phone_primary,
        'phone_secondary' => $phone_secondary,
        'email'           => $email,
        'county'          => $county,
        'sub_county'      => $sub_county,
        'ward'            => $ward,
        'village'         => $village,
        'postal_address'  => $postal_address,
        'postal_code'     => $postal_code
    ];

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Update patient (excluding MRN)
        $patient_sql = "UPDATE patients SET
            first_name = ?, middle_name = ?, last_name = ?, 
            date_of_birth = ?, sex = ?, 
            blood_group = ?, id_type = ?, id_number = ?,
            phone_primary = ?, phone_secondary = ?, email = ?,
            county = ?, sub_county = ?, ward = ?, village = ?, 
            postal_address = ?, postal_code = ?, updated_by = ?, updated_at = NOW()
            WHERE patient_id = ?";

        $patient_stmt = $mysqli->prepare($patient_sql);
        $patient_stmt->bind_param(
            "sssssssssssssssssii",
            $first_name,
            $middle_name,
            $last_name,
            $date_of_birth,
            $sex,
            $blood_group,
            $id_type,
            $id_number,
            $phone_primary,
            $phone_secondary,
            $email,
            $county,
            $sub_county,
            $ward,
            $village,
            $postal_address,
            $postal_code,
            $session_user_id,
            $patient_id
        );

        if (!$patient_stmt->execute()) {
            throw new Exception("Error updating patient: " . $mysqli->error);
        }

        // Check if next of kin exists
        $kin_check_sql = "SELECT kin_id FROM patient_next_of_kin WHERE patient_id = ?";
        $kin_check_stmt = $mysqli->prepare($kin_check_sql);
        $kin_check_stmt->bind_param("i", $patient_id);
        $kin_check_stmt->execute();
        $kin_check_result = $kin_check_stmt->get_result();

        if ($kin_check_result->num_rows > 0) {
            if (!empty($kin_full_name)) {
                // Update existing next of kin
                $kin_sql = "UPDATE patient_next_of_kin SET
                    full_name = ?, relationship = ?, phone = ?
                    WHERE patient_id = ?";

                $kin_stmt = $mysqli->prepare($kin_sql);
                $kin_stmt->bind_param(
                    "sssi",
                    $kin_full_name,
                    $kin_relationship,
                    $kin_phone,
                    $patient_id
                );
            } else {
                // Delete next of kin if name is empty
                $kin_sql = "DELETE FROM patient_next_of_kin WHERE patient_id = ?";
                $kin_stmt = $mysqli->prepare($kin_sql);
                $kin_stmt->bind_param("i", $patient_id);
            }
        } else {
            // Insert new next of kin only if name is provided
            if (!empty($kin_full_name)) {
                $kin_sql = "INSERT INTO patient_next_of_kin (
                    patient_id, full_name, relationship, phone, created_by
                ) VALUES (?, ?, ?, ?, ?)";

                $kin_stmt = $mysqli->prepare($kin_sql);
                $kin_stmt->bind_param(
                    "isssi",
                    $patient_id,
                    $kin_full_name,
                    $kin_relationship,
                    $kin_phone,
                    $session_user_id
                );
            }
        }

        // Execute next of kin operation if we have a statement
        if (isset($kin_stmt)) {
            if (!$kin_stmt->execute()) {
                throw new Exception("Error updating next of kin: " . $mysqli->error);
            }
        }

        //COMPARE OLD vs NEW (Only Changed Fields)
        $changed_old = [];
        $changed_new = [];

        foreach ($new_data as $field => $new_value) {
            $old_value = $old_data[$field] ?? null;

            // Normalize values to avoid false mismatches
            // Convert both to string, trim, and compare
            $old_str = trim((string)$old_value);
            $new_str = trim((string)$new_value);
            
            // Handle NULL comparisons properly
            if ($old_value === null && $new_value === null) {
                continue; // Both are null, no change
            }
            
            if (($old_value === null && $new_value !== null) || 
                ($old_value !== null && $new_value === null) || 
                $old_str !== $new_str) {
                $changed_old[$field] = $old_value;
                $changed_new[$field] = $new_value;
            }
        }

        //COMMIT → THEN AUDIT (Correct Order)
        $mysqli->commit();

        // Only log if there were actual changes
        if (!empty($changed_new)) {
            // Create a description that shows what changed
            $changed_fields = array_keys($changed_new);
            $description = 'Updated patient demographics: ' . implode(', ', $changed_fields);
            
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE',
                'module'      => 'Patients',
                'table_name'  => 'patients',
                'entity_type' => 'patient',
                'record_id'   => $patient_id,
                'patient_id'  => $patient_id,
                'description' => $description,
                'status'      => 'SUCCESS',
                'old_values'  => $changed_old,
                'new_values'  => $changed_new
            ]);
        }

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Patient updated successfully!";
        header("Location: patient_details.php?patient_id=" . $patient_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: patient_edit.php?patient_id=" . $patient_id);
        exit;
    }
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-edit mr-2"></i>Edit Patient
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="patient_details.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Patient
                </a>
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

        <!-- Patient Information Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Patient:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($patient['patient_mrn']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Name:</strong> 
                            <span class="badge badge-success ml-2">
                                <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php echo $patient['patient_status'] == 'ACTIVE' ? 'success' : 'secondary'; ?> ml-2">
                                <?php echo htmlspecialchars($patient['patient_status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Total Visits:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $stats['total_visits'] ?? 0; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="patient_details.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="patientForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" id="patientForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="patient_mrn" value="<?php echo htmlspecialchars($patient['patient_mrn']); ?>">

            <div class="row">
                <!-- Left Column: Patient Demographics & Address -->
                <div class="col-md-6">
                    <!-- Patient Demographics Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Patient Demographics</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">First Name</label>
                                        <input type="text" class="form-control" name="first_name" 
                                               placeholder="First Name" maxlength="100" required 
                                               value="<?php echo htmlspecialchars($patient['first_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Middle Name</label>
                                        <input type="text" class="form-control" name="middle_name" 
                                               placeholder="Middle Name" maxlength="100" 
                                               value="<?php echo htmlspecialchars($patient['middle_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Last Name</label>
                                        <input type="text" class="form-control" name="last_name" 
                                               placeholder="Last Name" maxlength="100" required 
                                               value="<?php echo htmlspecialchars($patient['last_name'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Medical Record Number (MRN)</label>
                                        <input type="text" class="form-control" readonly
                                               value="<?php echo htmlspecialchars($patient['patient_mrn'] ?? ''); ?>">
                                        <small class="form-text text-muted">MRN cannot be changed</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Date of Birth</label>
                                        <input type="date" class="form-control" name="date_of_birth" 
                                               max="<?php echo date('Y-m-d'); ?>" 
                                               value="<?php echo htmlspecialchars($patient['date_of_birth'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Sex</label>
                                        <select class="form-control select2" name="sex">
                                            <option value="">- Select Sex -</option>
                                            <option value="M" <?php echo ($patient['sex'] ?? '') == 'M' ? 'selected' : ''; ?>>Male</option>
                                            <option value="F" <?php echo ($patient['sex'] ?? '') == 'F' ? 'selected' : ''; ?>>Female</option>
                                            <option value="I" <?php echo ($patient['sex'] ?? '') == 'I' ? 'selected' : ''; ?>>Intersex</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Blood Group</label>
                                        <select class="form-control select2" name="blood_group">
                                            <option value="">- Select Blood Group -</option>
                                            <option value="A+" <?php echo ($patient['blood_group'] ?? '') == 'A+' ? 'selected' : ''; ?>>A+</option>
                                            <option value="A-" <?php echo ($patient['blood_group'] ?? '') == 'A-' ? 'selected' : ''; ?>>A-</option>
                                            <option value="B+" <?php echo ($patient['blood_group'] ?? '') == 'B+' ? 'selected' : ''; ?>>B+</option>
                                            <option value="B-" <?php echo ($patient['blood_group'] ?? '') == 'B-' ? 'selected' : ''; ?>>B-</option>
                                            <option value="AB+" <?php echo ($patient['blood_group'] ?? '') == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                            <option value="AB-" <?php echo ($patient['blood_group'] ?? '') == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                            <option value="O+" <?php echo ($patient['blood_group'] ?? '') == 'O+' ? 'selected' : ''; ?>>O+</option>
                                            <option value="O-" <?php echo ($patient['blood_group'] ?? '') == 'O-' ? 'selected' : ''; ?>>O-</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>ID Type</label>
                                        <select class="form-control select2" name="id_type">
                                            <option value="">- Select ID Type -</option>
                                            <option value="NATIONAL_ID" <?php echo ($patient['id_type'] ?? '') == 'NATIONAL_ID' ? 'selected' : ''; ?>>National ID</option>
                                            <option value="BIRTH_CERT" <?php echo ($patient['id_type'] ?? '') == 'BIRTH_CERT' ? 'selected' : ''; ?>>Birth Certificate</option>
                                            <option value="PASSPORT" <?php echo ($patient['id_type'] ?? '') == 'PASSPORT' ? 'selected' : ''; ?>>Passport</option>
                                            <option value="ALIEN_ID" <?php echo ($patient['id_type'] ?? '') == 'ALIEN_ID' ? 'selected' : ''; ?>>Alien ID</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>ID Number</label>
                                        <input type="text" class="form-control" name="id_number" 
                                               placeholder="ID Number" maxlength="30" 
                                               value="<?php echo htmlspecialchars($patient['id_number'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Primary Phone</label>
                                        <input type="tel" class="form-control" name="phone_primary" 
                                               placeholder="Primary Phone" maxlength="30" required 
                                               value="<?php echo htmlspecialchars($patient['phone_primary'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Secondary Phone</label>
                                        <input type="tel" class="form-control" name="phone_secondary" 
                                               placeholder="Secondary Phone" maxlength="30" 
                                               value="<?php echo htmlspecialchars($patient['phone_secondary'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mt-3">
                                <label>Email Address</label>
                                <input type="email" class="form-control" name="email" 
                                       placeholder="Email Address" maxlength="100" 
                                       value="<?php echo htmlspecialchars($patient['email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Address Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-map-marker-alt mr-2"></i>Address Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>County</label>
                                        <input type="text" class="form-control" name="county" 
                                               placeholder="County" maxlength="100" 
                                               value="<?php echo htmlspecialchars($patient['county'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Sub-County</label>
                                        <input type="text" class="form-control" name="sub_county" 
                                               placeholder="Sub-County" maxlength="100" 
                                               value="<?php echo htmlspecialchars($patient['sub_county'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Ward</label>
                                        <input type="text" class="form-control" name="ward" 
                                               placeholder="Ward" maxlength="100" 
                                               value="<?php echo htmlspecialchars($patient['ward'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Village/Area</label>
                                        <input type="text" class="form-control" name="village" 
                                               placeholder="Village or Area" maxlength="100" 
                                               value="<?php echo htmlspecialchars($patient['village'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Postal Code</label>
                                        <input type="text" class="form-control" name="postal_code" 
                                               placeholder="Postal Code" maxlength="10" 
                                               value="<?php echo htmlspecialchars($patient['postal_code'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mt-3">
                                <label>Postal Address</label>
                                <input type="text" class="form-control" name="postal_address" 
                                       placeholder="P.O. Box or Postal Address" maxlength="150" 
                                       value="<?php echo htmlspecialchars($patient['postal_address'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Next of Kin & Preview -->
                <div class="col-md-6">
                    <!-- Next of Kin Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user-check mr-2"></i>Next of Kin</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" class="form-control" name="kin_full_name" 
                                       placeholder="Next of Kin Full Name" maxlength="150" 
                                       value="<?php echo htmlspecialchars($next_of_kin['full_name'] ?? ''); ?>">
                            </div>

                            <div class="form-group mt-3">
                                <label>Relationship</label>
                                <select class="form-control select2" name="kin_relationship">
                                    <option value="">- Select Relationship -</option>
                                    <option value="Father" <?php echo ($next_of_kin['relationship'] ?? '') == 'Father' ? 'selected' : ''; ?>>Father</option>
                                    <option value="Mother" <?php echo ($next_of_kin['relationship'] ?? '') == 'Mother' ? 'selected' : ''; ?>>Mother</option>
                                    <option value="Brother" <?php echo ($next_of_kin['relationship'] ?? '') == 'Brother' ? 'selected' : ''; ?>>Brother</option>
                                    <option value="Sister" <?php echo ($next_of_kin['relationship'] ?? '') == 'Sister' ? 'selected' : ''; ?>>Sister</option>
                                    <option value="Spouse" <?php echo ($next_of_kin['relationship'] ?? '') == 'Spouse' ? 'selected' : ''; ?>>Spouse</option>
                                    <option value="Child" <?php echo ($next_of_kin['relationship'] ?? '') == 'Child' ? 'selected' : ''; ?>>Child</option>
                                    <option value="Guardian" <?php echo ($next_of_kin['relationship'] ?? '') == 'Guardian' ? 'selected' : ''; ?>>Guardian</option>
                                    <option value="Other" <?php echo ($next_of_kin['relationship'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group mt-3">
                                <label>Phone Number</label>
                                <input type="tel" class="form-control" name="kin_phone" 
                                       placeholder="Phone Number" maxlength="30" 
                                       value="<?php echo htmlspecialchars($next_of_kin['phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Patient Preview Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-info py-2">
                            <h4 class="card-title mb-0 text-white"><i class="fas fa-eye mr-2"></i>Patient Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%" class="text-muted">Name:</th>
                                        <td id="preview_name" class="font-weight-bold">
                                            <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">MRN:</th>
                                        <td id="preview_mrn" class="font-weight-bold">
                                            <?php echo htmlspecialchars($patient['patient_mrn']); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Sex:</th>
                                        <td id="preview_sex" class="font-weight-bold">
                                            <?php 
                                                $sex_display = '';
                                                switch($patient['sex']) {
                                                    case 'M': $sex_display = 'Male'; break;
                                                    case 'F': $sex_display = 'Female'; break;
                                                    case 'I': $sex_display = 'Intersex'; break;
                                                    default: $sex_display = '-';
                                                }
                                                echo htmlspecialchars($sex_display); 
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Date of Birth:</th>
                                        <td id="preview_dob" class="font-weight-bold">
                                            <?php echo !empty($patient['date_of_birth']) ? date('M j, Y', strtotime($patient['date_of_birth'])) : '-'; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Blood Group:</th>
                                        <td id="preview_blood_group" class="font-weight-bold">
                                            <?php echo htmlspecialchars($patient['blood_group'] ?? '-'); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">ID Number:</th>
                                        <td id="preview_id_number" class="font-weight-bold">
                                            <?php echo htmlspecialchars($patient['id_number'] ?? '-'); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Next of Kin:</th>
                                        <td id="preview_kin" class="font-weight-bold">
                                            <?php echo htmlspecialchars($next_of_kin['full_name'] ?? '-'); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Total Visits:</th>
                                        <td class="font-weight-bold"><?php echo $stats['total_visits'] ?? 0; ?></td>
                                    </tr>
                                    <?php if (!empty($stats['last_visit'])): ?>
                                    <tr>
                                        <th class="text-muted">Last Visit:</th>
                                        <td class="font-weight-bold">
                                            <?php echo date('M j, Y', strtotime($stats['last_visit'])); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Form Guidelines Card -->
                    <div class="card">
                        <div class="card-header bg-dark py-2">
                            <h4 class="card-title mb-0 text-white"><i class="fas fa-info-circle mr-2"></i>Form Guidelines</h4>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0" style="font-size: 0.9rem;">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <strong>Required fields</strong> are marked with asterisk (*)
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <strong>MRN</strong> is permanent and cannot be changed
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <strong>Primary phone</strong> is required for communication
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <strong>Blood group</strong> is important for medical emergencies
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <strong>Next of kin</strong> is optional but recommended
                                </li>
                                <li>
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Use <kbd>Ctrl</kbd> + <kbd>S</kbd> to save quickly
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <a href="patient_details.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Discard Changes
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="fas fa-redo mr-2"></i>Reset Form
                                    </button>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-2"></i>Save Changes
                                    </button>
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
    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        theme: 'bootstrap'
    });

    // Update preview in real-time
    function updatePreviewName() {
        const firstName = $('input[name="first_name"]').val() || '';
        const middleName = $('input[name="middle_name"]').val() || '';
        const lastName = $('input[name="last_name"]').val() || '';
        
        let fullName = firstName;
        if (middleName) fullName += ' ' + middleName;
        if (lastName) fullName += ' ' + lastName;
        
        $('#preview_name').text(fullName || 'Patient Name');
    }

    $('input[name="first_name"]').on('input', updatePreviewName);
    $('input[name="middle_name"]').on('input', updatePreviewName);
    $('input[name="last_name"]').on('input', updatePreviewName);
    
    // MRN is read-only, no need to update preview

    $('select[name="sex"]').change(function() {
        var sexText = $(this).find('option:selected').text();
        $('#preview_sex').text(sexText || '-');
    });

    $('input[name="date_of_birth"]').change(function() {
        if ($(this).val()) {
            const date = new Date($(this).val());
            $('#preview_dob').text(date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric',
                year: 'numeric'
            }));
        } else {
            $('#preview_dob').text('-');
        }
    });

    $('select[name="blood_group"]').change(function() {
        $('#preview_blood_group').text($(this).val() || '-');
    });

    $('input[name="id_number"]').on('input', function() {
        $('#preview_id_number').text($(this).val() || '-');
    });

    $('input[name="kin_full_name"]').on('input', function() {
        $('#preview_kin').text($(this).val() || '-');
    });

    // Form validation
    $('#patientForm').submit(function(e) {
        var isValid = true;
        var requiredFields = [
            'first_name', 'last_name', 'phone_primary'
        ];
        
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        requiredFields.forEach(function(field) {
            var element = $('[name="' + field + '"]');
            if (!element.val()) {
                isValid = false;
                element.addClass('is-invalid');
                if (element.is('select')) {
                    element.next('.select2-container').find('.select2-selection').addClass('is-invalid');
                }
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#patientForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields marked with *' +
                    '</div>'
                );
            }
            
            // Scroll to first error
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            
            return false;
        }
        
        // Show loading
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i> Updating...').prop('disabled', true);
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#patientForm').submit();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            if (confirm('Are you sure you want to discard changes?')) {
                window.location.href = 'patient_details.php?patient_id=<?php echo $patient_id; ?>';
            }
        }
    });
});
</script>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.select2-container .select2-selection.is-invalid {
    border-color: #dc3545;
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
.form-control[readonly] {
    background-color: #f8f9fa;
    border-color: #ced4da;
    cursor: not-allowed;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
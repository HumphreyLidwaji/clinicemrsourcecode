<?php
// patient_add.php - Patient Registration
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Add this line

// Get facility code from facilities table
$facilityCode = "HOSP"; // Default fallback value

// Get the primary facility's internal code
$facility_sql = "SELECT facility_internal_code FROM facilities WHERE is_active = 1 LIMIT 1";
$facility_result = $mysqli->query($facility_sql);

if ($facility_result && $facility_result->num_rows > 0) {
    $facility_row = $facility_result->fetch_assoc();
    $facilityCode = $facility_row['facility_internal_code'];
} else {
    // If no active facility found, get any facility
    $facility_sql = "SELECT facility_internal_code FROM facilities LIMIT 1";
    $facility_result = $mysqli->query($facility_sql);
    
    if ($facility_result && $facility_result->num_rows > 0) {
        $facility_row = $facility_result->fetch_assoc();
        $facilityCode = $facility_row['facility_internal_code'];
    }
}

// Get next MRN number without incrementing it
function getNextMRN(mysqli $db, string $facilityCode): string
{
    $year = date('Y');
    
    $stmt = $db->prepare("
        SELECT last_number 
        FROM mrn_sequences 
        WHERE facility_code = ? AND year = ?
    ");
    $stmt->bind_param("si", $facilityCode, $year);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $nextNumber = $row['last_number'] + 1;
    } else {
        $nextNumber = 1;
    }

    return sprintf('%s/%s/%06d', $facilityCode, $year, $nextNumber);
}

// Generate initial MRN preview (without incrementing)
$generatedMRN = getNextMRN($mysqli, $facilityCode);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Patient demographics - updated field names
    $first_name = sanitizeInput($_POST['first_name']);
    $middle_name = sanitizeInput($_POST['middle_name']);
    $last_name = sanitizeInput($_POST['last_name']);
    
    // Generate MRN only when form is submitted
    $patient_mrn = generateMRN($mysqli, $facilityCode);
    
    $date_of_birth = sanitizeInput($_POST['date_of_birth']);
    $sex = sanitizeInput($_POST['sex']);
    $blood_group = sanitizeInput($_POST['blood_group']);
    $phone_primary = sanitizeInput($_POST['phone_primary']);
    $phone_secondary = sanitizeInput($_POST['phone_secondary']);
    $email = sanitizeInput($_POST['email']);
    $id_type = sanitizeInput($_POST['id_type']);
    $id_number = sanitizeInput($_POST['id_number']);
    
    // Kenyan address structure - updated field names
    $county = sanitizeInput($_POST['county']);
    $sub_county = sanitizeInput($_POST['sub_county']);
    $ward = sanitizeInput($_POST['ward']);
    $village = sanitizeInput($_POST['village']);
    $postal_address = sanitizeInput($_POST['postal_address']);
    $postal_code = sanitizeInput($_POST['postal_code']);
    
    // Next of kin - updated field names
    $kin_full_name = sanitizeInput($_POST['kin_full_name']);
    $kin_relationship = sanitizeInput($_POST['kin_relationship']);
    $kin_phone = sanitizeInput($_POST['kin_phone']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: patient_add.php");
        exit;
    }

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($kin_full_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields (First Name, Last Name, and Next of Kin).";
        header("Location: patient_add.php");
        exit;
    }

    // Validate MRN uniqueness (double-check)
    $mrn_check_sql = "SELECT patient_id FROM patients WHERE patient_mrn = ?";
    $mrn_check_stmt = $mysqli->prepare($mrn_check_sql);
    $mrn_check_stmt->bind_param("s", $patient_mrn);
    $mrn_check_stmt->execute();
    $mrn_check_result = $mrn_check_stmt->get_result();

    if ($mrn_check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Medical Record Number (MRN) already exists. Please try again.";
        header("Location: patient_add.php");
        exit;
    }

    // Validate ID number if provided
    if (!empty($id_number)) {
        $id_check_sql = "SELECT patient_id FROM patients WHERE id_number = ?";
        $id_check_stmt = $mysqli->prepare($id_check_sql);
        $id_check_stmt->bind_param("s", $id_number);
        $id_check_stmt->execute();
        $id_check_result = $id_check_stmt->get_result();

        if ($id_check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "ID Number already exists in the system.";
            header("Location: patient_add.php");
            exit;
        }
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert patient - updated field names
        $patient_sql = "INSERT INTO patients (
            first_name, middle_name, last_name, patient_mrn, date_of_birth, 
            sex, blood_group, id_type, id_number, phone_primary, phone_secondary, email,
            county, sub_county, ward, village, postal_address, postal_code,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $patient_stmt = $mysqli->prepare($patient_sql);
        $patient_stmt->bind_param(
            "ssssssssssssssssssi",
            $first_name,
            $middle_name,
            $last_name,
            $patient_mrn,
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
            $session_user_id
        );

        if (!$patient_stmt->execute()) {
            throw new Exception("Error creating patient: " . $mysqli->error);
        }

        $patient_id = $patient_stmt->insert_id;

        // Insert next of kin - updated field names
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

            if (!$kin_stmt->execute()) {
                throw new Exception("Error creating next of kin: " . $mysqli->error);
            }
        }

        // Commit transaction
        $mysqli->commit();

        // AUDIT LOG: Log patient creation (AFTER successful commit)
        $new_data = [
            'first_name'      => $first_name,
            'middle_name'     => $middle_name,
            'last_name'       => $last_name,
            'patient_mrn'     => $patient_mrn,
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

        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Patients',
            'table_name'  => 'patients',
            'entity_type' => 'patient',
            'record_id'   => $patient_id,
            'patient_id'  => $patient_id,
            'description' => 'New patient registration',
            'status'      => 'SUCCESS',
            'old_values'  => null, // No old values for creation
            'new_values'  => $new_data
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Patient registered successfully!";
        header("Location: patient_details.php?patient_id=" . $patient_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: patient_add.php");
        exit;
    }
}

// Get today's registration stats - updated query
$today_patients_sql = "SELECT COUNT(*) as count FROM patients WHERE DATE(created_at) = CURDATE()";
$today_result = $mysqli->query($today_patients_sql);
$today_patients = $today_result->fetch_assoc()['count'];

// Get counties for suggestions
$counties_sql = "SELECT DISTINCT county FROM patients WHERE county IS NOT NULL AND county != '' ORDER BY county LIMIT 10";
$counties_result = $mysqli->query($counties_sql);
$counties = [];
while ($row = $counties_result->fetch_assoc()) {
    $counties[] = $row['county'];
}

// Get sub counties for suggestions
$sub_counties_sql = "SELECT DISTINCT sub_county FROM patients WHERE sub_county IS NOT NULL AND sub_county != '' ORDER BY sub_county LIMIT 10";
$sub_counties_result = $mysqli->query($sub_counties_sql);
$sub_counties = [];
while ($row = $sub_counties_result->fetch_assoc()) {
    $sub_counties[] = $row['sub_county'];
}

// Function to generate MRN (increments the sequence)
function generateMRN(mysqli $db, string $facilityCode): string
{
    $year = date('Y');

    $db->begin_transaction();

    try {
        // Lock sequence row
        $stmt = $db->prepare("
            SELECT last_number 
            FROM mrn_sequences 
            WHERE facility_code = ? AND year = ?
            FOR UPDATE
        ");
        $stmt->bind_param("si", $facilityCode, $year);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $row = $result->fetch_assoc()) {
            $nextNumber = $row['last_number'] + 1;

            $update = $db->prepare("
                UPDATE mrn_sequences 
                SET last_number = ? 
                WHERE facility_code = ? AND year = ?
            ");
            $update->bind_param("isi", $nextNumber, $facilityCode, $year);
            $update->execute();
        } else {
            $nextNumber = 1;

            $insert = $db->prepare("
                INSERT INTO mrn_sequences (facility_code, year, last_number)
                VALUES (?, ?, ?)
            ");
            $insert->bind_param("sii", $facilityCode, $year, $nextNumber);
            $insert->execute();
        }

        $db->commit();
        return sprintf('%s/%s/%06d', $facilityCode, $year, $nextNumber);
        
    } catch (Exception $e) {
        $db->rollback();
        throw new Exception("Error generating MRN: " . $e->getMessage());
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-user-plus mr-2"></i>Register New Patient
        </h3>
        <div class="card-tools">
            <a href="patients.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Patients
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

        <!-- Registration Stats Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-info ml-2">New Registration</span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Patients Today:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $today_patients; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Required Fields:</strong> 
                            <span class="badge badge-danger ml-2">3</span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="patients.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <?php if (SimplePermission::any("patient_create")) { ?>
                        <button type="submit" form="patientForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Register Patient
                        </button>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" id="patientForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="row">
                <!-- Left Column: Patient Demographics -->
                <div class="col-md-8">
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
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="first_name" 
                                                   placeholder="First Name" maxlength="100" required autofocus>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Middle Name</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="middle_name" 
                                                   placeholder="Middle Name" maxlength="100">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Last Name</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="last_name" 
                                                   placeholder="Last Name" maxlength="100" required>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Medical Record Number (MRN)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-id-card"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="patient_mrn" id="mrnField" 
                                                   placeholder="MRN" maxlength="50" value="<?php echo htmlspecialchars($generatedMRN); ?>" readonly>
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-outline-secondary" id="refreshMRN" title="Refresh MRN">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <small class="form-text text-muted">MRN will be generated when you submit the form (Preview: <?php echo htmlspecialchars($generatedMRN); ?>)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Date of Birth</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-birthday-cake"></i></span>
                                            </div>
                                            <input type="date" class="form-control" name="date_of_birth" 
                                                   max="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Sex</label>
                                        <select class="form-control select2" name="sex" data-placeholder="Select Sex">
                                            <option value=""></option>
                                            <option value="M">Male</option>
                                            <option value="F">Female</option>
                                            <option value="I">Intersex</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Blood Group</label>
                                        <select class="form-control select2" name="blood_group" data-placeholder="Select Blood Group">
                                            <option value=""></option>
                                            <option value="A+">A+</option>
                                            <option value="A-">A-</option>
                                            <option value="B+">B+</option>
                                            <option value="B-">B-</option>
                                            <option value="AB+">AB+</option>
                                            <option value="AB-">AB-</option>
                                            <option value="O+">O+</option>
                                            <option value="O-">O-</option>
                                            <option value="Unknown">Unknown</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>ID Type</label>
                                        <select class="form-control select2" name="id_type" data-placeholder="Select ID Type">
                                            <option value=""></option>
                                            <option value="NATIONAL_ID">National ID</option>
                                            <option value="BIRTH_CERT">Birth Certificate</option>
                                            <option value="PASSPORT">Passport</option>
                                            <option value="ALIEN_ID">Alien ID</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>ID Number</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-id-card"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="id_number" 
                                                   placeholder="ID Number" maxlength="30">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-phone-alt mr-2"></i>Contact Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Primary Phone</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span>
                                            </div>
                                            <input type="tel" class="form-control" name="phone_primary" 
                                                   placeholder="Primary Phone" maxlength="30" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Secondary Phone</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-mobile-alt"></i></span>
                                            </div>
                                            <input type="tel" class="form-control" name="phone_secondary" 
                                                   placeholder="Secondary Phone" maxlength="30">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>Email Address</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                                            </div>
                                            <input type="email" class="form-control" name="email" 
                                                   placeholder="Email Address" maxlength="100">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Kenyan Address Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-map-marker-alt mr-2"></i>Address Information (Kenya)</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>County</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-map"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="county" 
                                                   placeholder="County" maxlength="100" list="countiesList">
                                            <datalist id="countiesList">
                                                <?php foreach ($counties as $county): ?>
                                                    <option value="<?php echo htmlspecialchars($county); ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Sub-County</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-map"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="sub_county" 
                                                   placeholder="Sub-County" maxlength="100" list="subCountiesList">
                                            <datalist id="subCountiesList">
                                                <?php foreach ($sub_counties as $sub_county): ?>
                                                    <option value="<?php echo htmlspecialchars($sub_county); ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Ward</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-map-pin"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="ward" 
                                                   placeholder="Ward" maxlength="100">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Village/Area</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-home"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="village" 
                                                   placeholder="Village or Area" maxlength="100">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Postal Code</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-fw fa-mail-bulk"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="postal_code" 
                                                   placeholder="Postal Code" maxlength="10">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Postal Address</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="postal_address" 
                                                   placeholder="P.O. Box or Postal Address" maxlength="100">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Next of Kin Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user-check mr-2"></i>Next of Kin</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="required">Full Name</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-user-check"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="kin_full_name" 
                                           placeholder="Next of Kin Full Name" maxlength="150" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Relationship</label>
                                <select class="form-control select2" name="kin_relationship" 
                                        data-placeholder="Select Relationship">
                                    <option value=""></option>
                                    <option value="Father">Father</option>
                                    <option value="Mother">Mother</option>
                                    <option value="Brother">Brother</option>
                                    <option value="Sister">Sister</option>
                                    <option value="Spouse">Spouse</option>
                                    <option value="Child">Child</option>
                                    <option value="Guardian">Guardian</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Phone Number</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span>
                                    </div>
                                    <input type="tel" class="form-control" name="kin_phone" 
                                           placeholder="Phone Number" maxlength="30">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Preview & Actions -->
                <div class="col-md-4">
                    <!-- Registration Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Registration Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if (SimplePermission::any("patient_create")) { ?>
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-user-plus mr-2"></i>Register Patient
                                </button>
                                <?php } ?>
                                <button type="reset" class="btn btn-outline-secondary" id="resetForm">
                                    <i class="fas fa-redo mr-2"></i>Reset Form
                                </button>
                                <a href="patients.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel Registration
                                </a>
                            </div>
                            <hr>
                            <div class="small">
                                <p class="mb-2"><strong>Keyboard Shortcuts:</strong></p>
                                <div class="row">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + F</span> First Name
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + L</span> Last Name
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + P</span> Primary Phone
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + S</span> Save
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Patient Preview Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Patient Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="avatar-circle mb-2">
                                    <i class="fas fa-user-injured fa-2x"></i>
                                </div>
                                <h5 id="preview_name" class="font-weight-bold">Patient Name</h5>
                                <div id="preview_mrn" class="text-muted small">MRN: <?php echo htmlspecialchars($generatedMRN); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Sex:</span>
                                    <span id="preview_sex" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>DOB:</span>
                                    <span id="preview_dob" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Blood Group:</span>
                                    <span id="preview_blood_group" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>ID Number:</span>
                                    <span id="preview_id_number" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Next of Kin:</span>
                                    <span id="preview_kin" class="font-weight-bold text-primary">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Statistics Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Today's Statistics</h4>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="stat-box bg-primary-light p-2 rounded">
                                        <i class="fas fa-users fa-2x text-primary mb-2"></i>
                                        <h4 class="mb-0"><?php echo $today_patients; ?></h4>
                                        <small class="text-muted">Patients Today</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-success-light p-2 rounded">
                                        <i class="fas fa-clock fa-2x text-success mb-2"></i>
                                        <h4 class="mb-0"><?php echo date('H:i'); ?></h4>
                                        <small class="text-muted">Current Time</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Tips Card -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h4>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled small mb-0">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <span class="text-danger">*</span> denotes required fields
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    MRN is auto-generated when form is submitted
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Next of Kin is mandatory
                                </li>
                                <li>
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Use Ctrl+R to reset form
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions Footer -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        All patient data is encrypted and stored securely
                                    </small>
                                </div>
                                <div>
                                    <a href="patients.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary btn-sm" id="resetFormFooter">
                                        <i class="fas fa-redo mr-1"></i>Reset
                                    </button>
                                    <?php if (SimplePermission::any("patient_create")) { ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i>Register Patient
                                    </button>
                                    <?php } ?>
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
        placeholder: "Select...",
        theme: 'bootstrap',
        minimumResultsForSearch: 10
    });

    // Refresh MRN preview
    $('#refreshMRN').click(function() {
        // Just reload the page to get fresh preview
        window.location.reload();
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

    // Update sex preview
    $('select[name="sex"]').change(function() {
        var sexText = $(this).find('option:selected').text();
        $('#preview_sex').text(sexText || '-');
    });

    // Update blood group preview
    $('select[name="blood_group"]').change(function() {
        $('#preview_blood_group').text($(this).val() || '-');
    });

    // Update DOB preview
    $('input[name="date_of_birth"]').change(function() {
        if ($(this).val()) {
            const date = new Date($(this).val());
            $('#preview_dob').text(date.toLocaleDateString());
        } else {
            $('#preview_dob').text('-');
        }
    });

    // Update ID number preview
    $('input[name="id_number"]').on('input', function() {
        $('#preview_id_number').text($(this).val() || '-');
    });

    // Update next of kin preview
    $('input[name="kin_full_name"]').on('input', function() {
        $('#preview_kin').text($(this).val() || '-');
    });

    // Bind input events for preview
    $('input[name="first_name"]').on('input', updatePreviewName);
    $('input[name="middle_name"]').on('input', updatePreviewName);
    $('input[name="last_name"]').on('input', updatePreviewName);

    // Form reset handler
    function resetForm() {
        if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
            $('#patientForm')[0].reset();
            $('.select2').val(null).trigger('change');
            
            // Reset preview (except MRN)
            $('#preview_name').text('Patient Name');
            $('#preview_sex').text('-');
            $('#preview_blood_group').text('-');
            $('#preview_dob').text('-');
            $('#preview_id_number').text('-');
            $('#preview_kin').text('-');
            
            // Focus on first name field
            $('input[name="first_name"]').focus();
        }
    }

    // Bind reset buttons
    $('#resetForm, #resetFormFooter').click(resetForm);

    // Form validation
    $('#patientForm').on('submit', function(e) {
        var requiredFields = ['first_name', 'last_name', 'phone_primary', 'kin_full_name'];
        var isValid = true;
        
        // Clear previous invalid states
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        // Validate required fields
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

        // Validate phone format
        var phonePrimary = $('input[name="phone_primary"]').val();
        if (phonePrimary && !/^[0-9+\-\s()]{10,15}$/.test(phonePrimary)) {
            isValid = false;
            $('input[name="phone_primary"]').addClass('is-invalid');
        }

        // Validate email format if provided
        var email = $('input[name="email"]').val();
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            isValid = false;
            $('input[name="email"]').addClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#patientForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields marked with * and ensure phone/email formats are correct' +
                    '</div>'
                );
            }
            
            // Scroll to first error
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Registering...').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + F to focus on first name field
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('input[name="first_name"]').focus();
        }
        // Ctrl + L to focus on last name field
        if (e.ctrlKey && e.keyCode === 76) {
            e.preventDefault();
            $('input[name="last_name"]').focus();
        }
        // Ctrl + P to focus on primary phone
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            $('input[name="phone_primary"]').focus();
        }
        // Ctrl + K to focus on next of kin
        if (e.ctrlKey && e.keyCode === 75) {
            e.preventDefault();
            $('input[name="kin_full_name"]').focus();
        }
        // Ctrl + S to submit form
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#patientForm').submit();
        }
        // Ctrl + R to reset form
        if (e.ctrlKey && e.keyCode === 82) {
            e.preventDefault();
            resetForm();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            if (confirm('Cancel registration and return to patients list?')) {
                window.location.href = 'patients.php';
            }
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Auto-focus on first name field
    $('input[name="first_name"]').focus();
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
.avatar-circle {
    width: 60px;
    height: 60px;
    background-color: #e3f2fd;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.stat-box {
    transition: all 0.3s ease;
}
.stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.bg-primary-light {
    background-color: rgba(13, 110, 253, 0.1);
}
.bg-success-light {
    background-color: rgba(25, 135, 84, 0.1);
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current user session info
$session_user_id = $_SESSION['user_id'] ?? 0;
$session_user_name = $_SESSION['user_name'] ?? 'User';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: facility_add.php");
        exit;
    }
    
    // Get form data
    $facility_internal_code = trim($_POST['facility_internal_code'] ?? '');
    $mfl_code = trim($_POST['mfl_code'] ?? '');
    $facility_name = trim($_POST['facility_name'] ?? '');
    $facility_level = $_POST['facility_level'] ?? '';
    $facility_type = $_POST['facility_type'] ?? '';
    $ownership = $_POST['ownership'] ?? '';
    $county = trim($_POST['county'] ?? '');
    $sub_county = trim($_POST['sub_county'] ?? '');
    $ward = trim($_POST['ward'] ?? '');
    $physical_address = trim($_POST['physical_address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $sha_facility_code = trim($_POST['sha_facility_code'] ?? '');
    $nhif_accreditation_status = $_POST['nhif_accreditation_status'] ?? 'PENDING';
    $kra_pin = trim($_POST['kra_pin'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    
    // Basic validation
    $errors = [];
    
    if (empty($facility_internal_code)) {
        $errors[] = "Internal code is required";
    } elseif (strlen($facility_internal_code) > 20) {
        $errors[] = "Internal code must be 20 characters or less";
    }
    
    if (empty($mfl_code)) {
        $errors[] = "MFL code is required";
    } elseif (strlen($mfl_code) > 20) {
        $errors[] = "MFL code must be 20 characters or less";
    }
    
    if (empty($facility_name)) {
        $errors[] = "Facility name is required";
    } elseif (strlen($facility_name) > 200) {
        $errors[] = "Facility name must be 200 characters or less";
    }
    
    if (empty($facility_level)) {
        $errors[] = "Facility level is required";
    }
    
    if (empty($facility_type)) {
        $errors[] = "Facility type is required";
    }
    
    if (empty($ownership)) {
        $errors[] = "Ownership is required";
    }
    
    if (empty($county)) {
        $errors[] = "County is required";
    }
    
    if (empty($sub_county)) {
        $errors[] = "Sub-county is required";
    }
    
    if (empty($ward)) {
        $errors[] = "Ward is required";
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if ($kra_pin && !preg_match('/^[A-Z]{1}[0-9]{9}[A-Z]{1}$/', $kra_pin)) {
        $errors[] = "Invalid KRA PIN format (e.g., A123456789Z)";
    }
    
    // Check for duplicates
    if (empty($errors)) {
        $check_sql = "SELECT facility_id FROM facilities WHERE facility_internal_code = ? OR mfl_code = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ss", $facility_internal_code, $mfl_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $duplicates = [];
            while ($row = $check_result->fetch_assoc()) {
                if ($row['facility_internal_code'] === $facility_internal_code) {
                    $duplicates[] = "Internal code '$facility_internal_code'";
                }
                if ($row['mfl_code'] === $mfl_code) {
                    $duplicates[] = "MFL code '$mfl_code'";
                }
            }
            $errors[] = "Duplicate found: " . implode(" and ", $duplicates);
        }
        $check_stmt->close();
    }
    
    if (empty($errors)) {
        // Insert new facility
        $insert_sql = "INSERT INTO facilities (
            facility_internal_code, mfl_code, facility_name, facility_level, 
            facility_type, ownership, county, sub_county, ward, physical_address, 
            phone, email, sha_facility_code, nhif_accreditation_status, kra_pin, 
            is_active, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param(
            "sssssssssssssssiii",
            $facility_internal_code, $mfl_code, $facility_name, $facility_level,
            $facility_type, $ownership, $county, $sub_county, $ward, $physical_address,
            $phone, $email, $sha_facility_code, $nhif_accreditation_status, $kra_pin,
            $is_active, $session_user_id, $session_user_id
        );
        
        if ($insert_stmt->execute()) {
            $new_facility_id = $insert_stmt->insert_id;
            
            // Log the action
            $audit_sql = "INSERT INTO audit_log (
                entity_type, entity_id, action, description, user_id, ip_address, user_agent
            ) VALUES ('facility', ?, 'CREATE', ?, ?, ?, ?)";
            
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "New facility added: $facility_name ($mfl_code)";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $audit_stmt->bind_param("ississ", $new_facility_id, $description, $session_user_id, $ip_address, $user_agent);
            $audit_stmt->execute();
            $audit_stmt->close();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Facility added successfully!";
            header("Location: facility.php?id=$new_facility_id");
            exit;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding facility: " . $mysqli->error;
            header("Location: facility_add.php");
            exit;
        }
    } else {
        // Store errors and form data in session to repopulate form
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fix the following errors:<br>" . implode("<br>", $errors);
        header("Location: facility_add.php");
        exit;
    }
}

// Get form data from session if exists (for repopulating after errors)
$form_data = $_SESSION['form_data'] ?? [];
$form_errors = $_SESSION['form_errors'] ?? [];

// Clear session data
unset($_SESSION['form_data']);
unset($_SESSION['form_errors']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Facility - Clinic Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .required:after {
            content: " *";
            color: #dc3545;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .form-section h5 {
            color: #007bff;
            margin-bottom: 15px;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        .county-selector .county-btn {
            margin: 2px;
            font-size: 0.9rem;
        }
        .kra-pin-format {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .phone-input-group .input-group-text {
            border-right: 0;
        }
        .phone-input-group .form-control {
            border-left: 0;
        }
        .coordinate-input .input-group-text {
            font-size: 0.8rem;
            padding: 0.375rem 0.5rem;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25em 0.6em;
            font-size: 0.75em;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }
        .badge-pending { background-color: #ffc107; color: #212529; }
        .badge-accredited { background-color: #28a745; color: white; }
        .badge-suspended { background-color: #dc3545; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="../dashboard.php">
                    <i class="fas fa-hospital me-2"></i>Clinic Management
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="../dashboard.php">
                                <i class="fas fa-home me-1"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="facility.php">
                                <i class="fas fa-hospital me-1"></i>Facilities
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="facility_add.php">
                                <i class="fas fa-plus-circle me-1"></i>Add Facility
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../reports.php">
                                <i class="fas fa-chart-bar me-1"></i>Reports
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="row">
            <div class="col-md-10 offset-md-1">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="fas fa-hospital text-primary me-2"></i>Add New Facility
                        </h1>
                        <p class="text-muted mb-0">Register a new healthcare facility in the system</p>
                    </div>
                    <div>
                        <a href="facility.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Facilities
                        </a>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if (isset($_SESSION['alert_message'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show mb-4">
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        <i class="fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                        <?php echo $_SESSION['alert_message']; ?>
                    </div>
                    <?php 
                    unset($_SESSION['alert_type']);
                    unset($_SESSION['alert_message']);
                    ?>
                <?php endif; ?>

                <!-- Main Form -->
                <div class="card shadow">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle me-2"></i>Facility Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <form id="facilityForm" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <!-- Section 1: Basic Information -->
                            <div class="form-section">
                                <h5><i class="fas fa-id-card me-2"></i>Basic Information</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="facility_name" class="form-label required">Facility Name</label>
                                        <input type="text" class="form-control <?php echo isset($form_errors['facility_name']) ? 'is-invalid' : ''; ?>" 
                                               id="facility_name" name="facility_name" 
                                               value="<?php echo htmlspecialchars($form_data['facility_name'] ?? ''); ?>" 
                                               required maxlength="200">
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['facility_name'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">Official name of the facility</small>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label for="facility_internal_code" class="form-label required">Internal Code</label>
                                        <input type="text" class="form-control <?php echo isset($form_errors['facility_internal_code']) ? 'is-invalid' : ''; ?>" 
                                               id="facility_internal_code" name="facility_internal_code" 
                                               value="<?php echo htmlspecialchars($form_data['facility_internal_code'] ?? ''); ?>" 
                                               required maxlength="20">
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['facility_internal_code'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">Unique internal identifier</small>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label for="mfl_code" class="form-label required">MFL Code</label>
                                        <input type="text" class="form-control <?php echo isset($form_errors['mfl_code']) ? 'is-invalid' : ''; ?>" 
                                               id="mfl_code" name="mfl_code" 
                                               value="<?php echo htmlspecialchars($form_data['mfl_code'] ?? ''); ?>" 
                                               required maxlength="20">
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['mfl_code'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">Master Facility List code</small>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="facility_type" class="form-label required">Facility Type</label>
                                        <select class="form-select <?php echo isset($form_errors['facility_type']) ? 'is-invalid' : ''; ?>" 
                                                id="facility_type" name="facility_type" required>
                                            <option value="">Select Type</option>
                                            <option value="DISPENSARY" <?php echo ($form_data['facility_type'] ?? '') == 'DISPENSARY' ? 'selected' : ''; ?>>Dispensary</option>
                                            <option value="HEALTH_CENTER" <?php echo ($form_data['facility_type'] ?? '') == 'HEALTH_CENTER' ? 'selected' : ''; ?>>Health Center</option>
                                            <option value="SUB_COUNTY_HOSPITAL" <?php echo ($form_data['facility_type'] ?? '') == 'SUB_COUNTY_HOSPITAL' ? 'selected' : ''; ?>>Sub-County Hospital</option>
                                            <option value="COUNTY_REFERRAL" <?php echo ($form_data['facility_type'] ?? '') == 'COUNTY_REFERRAL' ? 'selected' : ''; ?>>County Referral Hospital</option>
                                            <option value="NATIONAL_REFERRAL" <?php echo ($form_data['facility_type'] ?? '') == 'NATIONAL_REFERRAL' ? 'selected' : ''; ?>>National Referral Hospital</option>
                                            <option value="CLINIC" <?php echo ($form_data['facility_type'] ?? '') == 'CLINIC' ? 'selected' : ''; ?>>Clinic</option>
                                        </select>
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['facility_type'] ?? ''; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="facility_level" class="form-label required">Facility Level</label>
                                        <select class="form-select <?php echo isset($form_errors['facility_level']) ? 'is-invalid' : ''; ?>" 
                                                id="facility_level" name="facility_level" required>
                                            <option value="">Select Level</option>
                                            <option value="LEVEL_2" <?php echo ($form_data['facility_level'] ?? '') == 'LEVEL_2' ? 'selected' : ''; ?>>Level 2</option>
                                            <option value="LEVEL_3" <?php echo ($form_data['facility_level'] ?? '') == 'LEVEL_3' ? 'selected' : ''; ?>>Level 3</option>
                                            <option value="LEVEL_4" <?php echo ($form_data['facility_level'] ?? '') == 'LEVEL_4' ? 'selected' : ''; ?>>Level 4</option>
                                            <option value="LEVEL_5" <?php echo ($form_data['facility_level'] ?? '') == 'LEVEL_5' ? 'selected' : ''; ?>>Level 5</option>
                                            <option value="LEVEL_6" <?php echo ($form_data['facility_level'] ?? '') == 'LEVEL_6' ? 'selected' : ''; ?>>Level 6</option>
                                        </select>
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['facility_level'] ?? ''; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="ownership" class="form-label required">Ownership</label>
                                        <select class="form-select <?php echo isset($form_errors['ownership']) ? 'is-invalid' : ''; ?>" 
                                                id="ownership" name="ownership" required>
                                            <option value="">Select Ownership</option>
                                            <option value="MOH" <?php echo ($form_data['ownership'] ?? '') == 'MOH' ? 'selected' : ''; ?>>Ministry of Health</option>
                                            <option value="COUNTY" <?php echo ($form_data['ownership'] ?? '') == 'COUNTY' ? 'selected' : ''; ?>>County Government</option>
                                            <option value="PRIVATE" <?php echo ($form_data['ownership'] ?? '') == 'PRIVATE' ? 'selected' : ''; ?>>Private</option>
                                            <option value="FAITH_BASED" <?php echo ($form_data['ownership'] ?? '') == 'FAITH_BASED' ? 'selected' : ''; ?>>Faith-Based</option>
                                            <option value="NGO" <?php echo ($form_data['ownership'] ?? '') == 'NGO' ? 'selected' : ''; ?>>NGO</option>
                                            <option value="ARMED_FORCES" <?php echo ($form_data['ownership'] ?? '') == 'ARMED_FORCES' ? 'selected' : ''; ?>>Armed Forces</option>
                                        </select>
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['ownership'] ?? ''; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 2: Location Information -->
                            <div class="form-section">
                                <h5><i class="fas fa-map-marker-alt me-2"></i>Location Information</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="county" class="form-label required">County</label>
                                        <select class="form-select <?php echo isset($form_errors['county']) ? 'is-invalid' : ''; ?>" 
                                                id="county" name="county" required>
                                            <option value="">Select County</option>
                                            <?php
                                            // Common Kenyan counties
                                            $kenyan_counties = [
                                                'Mombasa', 'Kwale', 'Kilifi', 'Tana River', 'Lamu', 'Taita Taveta',
                                                'Garissa', 'Wajir', 'Mandera', 'Marsabit', 'Isiolo', 'Meru', 'Tharaka Nithi',
                                                'Embu', 'Kitui', 'Machakos', 'Makueni', 'Nyandarua', 'Nyeri', 'Kirinyaga',
                                                'Murang\'a', 'Kiambu', 'Turkana', 'West Pokot', 'Samburu', 'Trans Nzoia',
                                                'Uasin Gishu', 'Elgeyo Marakwet', 'Nandi', 'Baringo', 'Laikipia', 'Nakuru',
                                                'Narok', 'Kajiado', 'Kericho', 'Bomet', 'Kakamega', 'Vihiga', 'Bungoma',
                                                'Busia', 'Siaya', 'Kisumu', 'Homa Bay', 'Migori', 'Kisii', 'Nyamira', 'Nairobi'
                                            ];
                                            sort($kenyan_counties);
                                            foreach ($kenyan_counties as $county):
                                            ?>
                                                <option value="<?php echo htmlspecialchars($county); ?>"
                                                    <?php echo ($form_data['county'] ?? '') == $county ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($county); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="OTHER" <?php echo ($form_data['county'] ?? '') == 'OTHER' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['county'] ?? ''; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="sub_county" class="form-label required">Sub-County</label>
                                        <input type="text" class="form-control <?php echo isset($form_errors['sub_county']) ? 'is-invalid' : ''; ?>" 
                                               id="sub_county" name="sub_county" 
                                               value="<?php echo htmlspecialchars($form_data['sub_county'] ?? ''); ?>" 
                                               required maxlength="100">
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['sub_county'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">e.g., Westlands, Embakasi, etc.</small>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="ward" class="form-label required">Ward</label>
                                        <input type="text" class="form-control <?php echo isset($form_errors['ward']) ? 'is-invalid' : ''; ?>" 
                                               id="ward" name="ward" 
                                               value="<?php echo htmlspecialchars($form_data['ward'] ?? ''); ?>" 
                                               required maxlength="100">
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['ward'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">Administrative ward</small>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="physical_address" class="form-label">Physical Address</label>
                                        <textarea class="form-control <?php echo isset($form_errors['physical_address']) ? 'is-invalid' : ''; ?>" 
                                                  id="physical_address" name="physical_address" 
                                                  rows="2" maxlength="200"><?php echo htmlspecialchars($form_data['physical_address'] ?? ''); ?></textarea>
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['physical_address'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">Street address, building, plot number, etc.</small>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="latitude" class="form-label">Latitude</label>
                                        <div class="input-group coordinate-input">
                                            <span class="input-group-text">
                                                <i class="fas fa-globe-americas"></i>
                                            </span>
                                            <input type="text" class="form-control" id="latitude" name="latitude" 
                                                   value="<?php echo htmlspecialchars($form_data['latitude'] ?? ''); ?>" 
                                                   placeholder="e.g., -1.286389" pattern="-?\d{1,3}\.\d+">
                                        </div>
                                        <small class="form-text text-muted">Decimal degrees (e.g., -1.286389 for Nairobi)</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="longitude" class="form-label">Longitude</label>
                                        <div class="input-group coordinate-input">
                                            <span class="input-group-text">
                                                <i class="fas fa-globe-americas"></i>
                                            </span>
                                            <input type="text" class="form-control" id="longitude" name="longitude" 
                                                   value="<?php echo htmlspecialchars($form_data['longitude'] ?? ''); ?>" 
                                                   placeholder="e.g., 36.817223" pattern="-?\d{1,3}\.\d+">
                                        </div>
                                        <small class="form-text text-muted">Decimal degrees (e.g., 36.817223 for Nairobi)</small>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="getCurrentLocation()">
                                            <i class="fas fa-location-arrow me-1"></i>Use Current Location
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm ms-2" onclick="openMapPicker()">
                                            <i class="fas fa-map me-1"></i>Pick from Map
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3: Contact Information -->
                            <div class="form-section">
                                <h5><i class="fas fa-address-book me-2"></i>Contact Information</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <div class="input-group phone-input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-phone"></i>
                                            </span>
                                            <input type="tel" class="form-control <?php echo isset($form_errors['phone']) ? 'is-invalid' : ''; ?>" 
                                                   id="phone" name="phone" 
                                                   value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>" 
                                                   maxlength="30" placeholder="e.g., 0712345678">
                                        </div>
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['phone'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">Primary contact number</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-envelope"></i>
                                            </span>
                                            <input type="email" class="form-control <?php echo isset($form_errors['email']) ? 'is-invalid' : ''; ?>" 
                                                   id="email" name="email" 
                                                   value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" 
                                                   maxlength="100" placeholder="e.g., info@facility.com">
                                        </div>
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['email'] ?? ''; ?>
                                        </div>
                                        <small class="form-text text-muted">Official email address</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 4: Regulatory Information -->
                            <div class="form-section">
                                <h5><i class="fas fa-certificate me-2"></i>Regulatory Information</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="sha_facility_code" class="form-label">SHA Facility Code</label>
                                        <input type="text" class="form-control" id="sha_facility_code" name="sha_facility_code" 
                                               value="<?php echo htmlspecialchars($form_data['sha_facility_code'] ?? ''); ?>" 
                                               maxlength="30">
                                        <small class="form-text text-muted">Social Health Authority code</small>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="nhif_accreditation_status" class="form-label">NHIF Accreditation</label>
                                        <select class="form-select" id="nhif_accreditation_status" name="nhif_accreditation_status">
                                            <option value="PENDING" <?php echo ($form_data['nhif_accreditation_status'] ?? 'PENDING') == 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="ACCREDITED" <?php echo ($form_data['nhif_accreditation_status'] ?? '') == 'ACCREDITED' ? 'selected' : ''; ?>>Accredited</option>
                                            <option value="SUSPENDED" <?php echo ($form_data['nhif_accreditation_status'] ?? '') == 'SUSPENDED' ? 'selected' : ''; ?>>Suspended</option>
                                        </select>
                                        <small class="form-text text-muted">National Hospital Insurance Fund status</small>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="kra_pin" class="form-label">KRA PIN</label>
                                        <input type="text" class="form-control <?php echo isset($form_errors['kra_pin']) ? 'is-invalid' : ''; ?>" 
                                               id="kra_pin" name="kra_pin" 
                                               value="<?php echo htmlspecialchars($form_data['kra_pin'] ?? ''); ?>" 
                                               maxlength="30" placeholder="A123456789Z">
                                        <div class="invalid-feedback">
                                            <?php echo $form_errors['kra_pin'] ?? ''; ?>
                                        </div>
                                        <small class="form-text kra-pin-format">Format: Letter + 9 digits + Letter (e.g., A123456789Z)</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 5: Status -->
                            <div class="form-section">
                                <h5><i class="fas fa-toggle-on me-2"></i>Status</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                                   value="1" <?php echo isset($form_data['is_active']) && $form_data['is_active'] == 1 ? 'checked' : 'checked'; ?>>
                                            <label class="form-check-label" for="is_active">
                                                Active Facility
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">Deactivate to hide facility from active lists</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <span class="me-3">Current Status:</span>
                                            <span id="statusBadge" class="status-badge badge-accredited">Active</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="d-flex justify-content-between">
                                        <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='facility.php'">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </button>
                                        <div>
                                            <button type="button" class="btn btn-outline-primary me-2" onclick="saveAsDraft()">
                                                <i class="fas fa-save me-2"></i>Save as Draft
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-plus-circle me-2"></i>Add Facility
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-lightbulb me-2"></i>Quick Tips
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <i class="fas fa-info-circle text-primary me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">MFL Code</h6>
                                        <p class="text-muted small mb-0">Master Facility List code is unique for each facility in Kenya.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <i class="fas fa-certificate text-warning me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">NHIF Accreditation</h6>
                                        <p class="text-muted small mb-0">Facilities must be NHIF accredited to accept NHIF insurance.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <i class="fas fa-map-marked-alt text-success me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Location Data</h6>
                                        <p class="text-muted small mb-0">GPS coordinates help with mapping and navigation to the facility.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Input Mask -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.inputmask/5.0.7/jquery.inputmask.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize Select2 for county dropdown
        $('#county').select2({
            placeholder: "Select County",
            allowClear: true,
            width: '100%'
        });
        
        // Initialize phone input mask
        $('#phone').inputmask({
            mask: '9999999999',
            placeholder: '0712345678',
            showMaskOnHover: false,
            showMaskOnFocus: true
        });
        
        // Initialize KRA PIN input mask
        $('#kra_pin').inputmask({
            mask: 'A999999999A',
            placeholder: 'A123456789Z',
            casing: 'upper'
        });
        
        // Toggle status badge based on is_active checkbox
        $('#is_active').change(function() {
            if ($(this).is(':checked')) {
                $('#statusBadge').removeClass('badge-suspended').addClass('badge-accredited').text('Active');
            } else {
                $('#statusBadge').removeClass('badge-accredited').addClass('badge-suspended').text('Inactive');
            }
        });
        
        // Form validation
        $('#facilityForm').on('submit', function(e) {
            let isValid = true;
            
            // Clear previous validation
            $('.is-invalid').removeClass('is-invalid');
            $('.invalid-feedback').text('');
            
            // Required field validation
            $('[required]').each(function() {
                if (!$(this).val().trim()) {
                    $(this).addClass('is-invalid');
                    $(this).next('.invalid-feedback').text('This field is required');
                    isValid = false;
                }
            });
            
            // Email validation
            const email = $('#email').val();
            if (email && !isValidEmail(email)) {
                $('#email').addClass('is-invalid');
                $('#email').nextAll('.invalid-feedback').first().text('Please enter a valid email address');
                isValid = false;
            }
            
            // KRA PIN validation
            const kraPin = $('#kra_pin').val();
            if (kraPin && !isValidKRAPin(kraPin)) {
                $('#kra_pin').addClass('is-invalid');
                $('#kra_pin').nextAll('.invalid-feedback').first().text('Invalid KRA PIN format (e.g., A123456789Z)');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                // Scroll to first error
                $('.is-invalid').first().focus();
            }
        });
        
        // Auto-generate internal code from facility name
        $('#facility_name').on('blur', function() {
            if (!$('#facility_internal_code').val()) {
                const name = $(this).val();
                if (name.length > 3) {
                    // Generate code: First 3 letters + timestamp
                    const prefix = name.substring(0, 3).toUpperCase();
                    const timestamp = Date.now().toString().substr(-4);
                    $('#facility_internal_code').val(prefix + timestamp);
                }
            }
        });
        
        // Auto-suggest MFL code based on county and facility type
        $('#county, #facility_type').on('change', function() {
            if (!$('#mfl_code').val()) {
                const county = $('#county').val();
                const type = $('#facility_type').val();
                
                if (county && type) {
                    const countyCode = county.substring(0, 3).toUpperCase();
                    const typeCode = getFacilityTypeCode(type);
                    const randomNum = Math.floor(Math.random() * 9000) + 1000;
                    $('#mfl_code').val(countyCode + typeCode + randomNum);
                }
            }
        });
    });
    
    function isValidEmail(email) {
        const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
        return re.test(String(email).toLowerCase());
    }
    
    function isValidKRAPin(pin) {
        const re = /^[A-Z]{1}[0-9]{9}[A-Z]{1}$/;
        return re.test(pin);
    }
    
    function getFacilityTypeCode(type) {
        const codes = {
            'DISPENSARY': 'DSP',
            'HEALTH_CENTER': 'HLC',
            'SUB_COUNTY_HOSPITAL': 'SCH',
            'COUNTY_REFERRAL': 'CRH',
            'NATIONAL_REFERRAL': 'NRH',
            'CLINIC': 'CLN'
        };
        return codes[type] || 'FAC';
    }
    
    function getCurrentLocation() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    $('#latitude').val(position.coords.latitude.toFixed(6));
                    $('#longitude').val(position.coords.longitude.toFixed(6));
                    showAlert('Location obtained successfully!', 'success');
                },
                function(error) {
                    let message = 'Unable to retrieve your location. ';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            message += 'Please enable location services.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            message += 'Location information is unavailable.';
                            break;
                        case error.TIMEOUT:
                            message += 'Location request timed out.';
                            break;
                        default:
                            message += 'An unknown error occurred.';
                    }
                    showAlert(message, 'warning');
                }
            );
        } else {
            showAlert('Geolocation is not supported by your browser.', 'warning');
        }
    }
    
    function openMapPicker() {
        const latitude = $('#latitude').val() || '-1.286389';
        const longitude = $('#longitude').val() || '36.817223';
        const url = `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=15/${latitude}/${longitude}`;
        window.open(url, '_blank', 'width=800,height=600');
    }
    
    function saveAsDraft() {
        // Collect form data
        const formData = new FormData(document.getElementById('facilityForm'));
        const draftData = {};
        formData.forEach((value, key) => {
            draftData[key] = value;
        });
        
        // Save to localStorage
        localStorage.setItem('facility_draft', JSON.stringify({
            data: draftData,
            timestamp: new Date().toISOString(),
            user: '<?php echo $session_user_name; ?>'
        }));
        
        showAlert('Draft saved successfully!', 'success');
        
        // Optionally submit as draft
        const draftInput = document.createElement('input');
        draftInput.type = 'hidden';
        draftInput.name = 'save_as_draft';
        draftInput.value = '1';
        document.getElementById('facilityForm').appendChild(draftInput);
        document.getElementById('facilityForm').submit();
    }
    
    function showAlert(message, type) {
        // Remove any existing alerts
        $('.alert-dismissible').remove();
        
        // Create alert
        const alertClass = type === 'success' ? 'alert-success' : 'alert-warning';
        const icon = type === 'success' ? 'check-circle' : 'exclamation-triangle';
        
        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show position-fixed top-0 end-0 m-3" style="z-index: 1050;">
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <i class="fas fa-${icon} me-2"></i>${message}
            </div>
        `;
        
        $('body').append(alertHtml);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            $('.alert-dismissible').alert('close');
        }, 5000);
    }
    
    // Load draft if exists
    const draft = localStorage.getItem('facility_draft');
    if (draft) {
        try {
            const draftData = JSON.parse(draft);
            if (confirm('You have a saved draft. Load it?')) {
                // Populate form fields
                Object.keys(draftData.data).forEach(key => {
                    const element = $(`[name="${key}"]`);
                    if (element.length) {
                        if (element.attr('type') === 'checkbox') {
                            element.prop('checked', draftData.data[key] === '1');
                        } else if (element.is('select')) {
                            element.val(draftData.data[key]).trigger('change');
                        } else {
                            element.val(draftData.data[key]);
                        }
                    }
                });
                showAlert('Draft loaded successfully!', 'success');
                localStorage.removeItem('facility_draft');
            }
        } catch (e) {
            console.error('Error loading draft:', e);
        }
    }
    </script>
</body>
</html>
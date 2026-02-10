<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current user session info
$session_user_id = $_SESSION['user_id'] ?? 0;
$session_user_name = $_SESSION['user_name'] ?? 'User';

// Get facility ID from URL
$facility_id = intval($_GET['id'] ?? 0);

if (!$facility_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid facility ID.";
    header("Location: facility.php");
    exit;
}

// Get facility details
$facility_sql = "SELECT f.*,
                        u.user_name as created_by_name,
                        u2.user_name as updated_by_name
                 FROM facilities f
                 LEFT JOIN users u ON f.created_by = u.user_id
                 LEFT JOIN users u2 ON f.updated_by = u2.user_id
                 WHERE f.facility_id = ?";
$facility_stmt = $mysqli->prepare($facility_sql);
$facility_stmt->bind_param("i", $facility_id);
$facility_stmt->execute();
$facility_result = $facility_stmt->get_result();

if ($facility_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Facility not found.";
    header("Location: facility.php");
    exit;
}

$facility = $facility_result->fetch_assoc();
$facility_stmt->close();

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: facility_edit.php?id=" . $facility_id);
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
        $errors['facility_internal_code'] = "Internal code is required";
    } elseif (strlen($facility_internal_code) > 20) {
        $errors['facility_internal_code'] = "Internal code must be 20 characters or less";
    }
    
    if (empty($mfl_code)) {
        $errors['mfl_code'] = "MFL code is required";
    } elseif (strlen($mfl_code) > 20) {
        $errors['mfl_code'] = "MFL code must be 20 characters or less";
    }
    
    if (empty($facility_name)) {
        $errors['facility_name'] = "Facility name is required";
    } elseif (strlen($facility_name) > 200) {
        $errors['facility_name'] = "Facility name must be 200 characters or less";
    }
    
    if (empty($facility_level)) {
        $errors['facility_level'] = "Facility level is required";
    }
    
    if (empty($facility_type)) {
        $errors['facility_type'] = "Facility type is required";
    }
    
    if (empty($ownership)) {
        $errors['ownership'] = "Ownership is required";
    }
    
    if (empty($county)) {
        $errors['county'] = "County is required";
    }
    
    if (empty($sub_county)) {
        $errors['sub_county'] = "Sub-county is required";
    }
    
    if (empty($ward)) {
        $errors['ward'] = "Ward is required";
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Invalid email format";
    }
    
    if ($kra_pin && !preg_match('/^[A-Z]{1}[0-9]{9}[A-Z]{1}$/', $kra_pin)) {
        $errors['kra_pin'] = "Invalid KRA PIN format (e.g., A123456789Z)";
    }
    
    // Check for duplicates (excluding current facility)
    if (empty($errors)) {
        $check_sql = "SELECT facility_id FROM facilities 
                      WHERE (facility_internal_code = ? OR mfl_code = ?) 
                      AND facility_id != ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ssi", $facility_internal_code, $mfl_code, $facility_id);
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
            $errors['duplicate'] = "Duplicate found: " . implode(" and ", $duplicates);
        }
        $check_stmt->close();
    }
    
    if (empty($errors)) {
        // Get old values for audit log
        $old_values = json_encode($facility);
        
        // Update facility
        $update_sql = "UPDATE facilities SET
            facility_internal_code = ?,
            mfl_code = ?,
            facility_name = ?,
            facility_level = ?,
            facility_type = ?,
            ownership = ?,
            county = ?,
            sub_county = ?,
            ward = ?,
            physical_address = ?,
            phone = ?,
            email = ?,
            sha_facility_code = ?,
            nhif_accreditation_status = ?,
            kra_pin = ?,
            is_active = ?,
            latitude = ?,
            longitude = ?,
            updated_by = ?,
            updated_at = NOW()
            WHERE facility_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param(
            "sssssssssssssssissi",
            $facility_internal_code, $mfl_code, $facility_name, $facility_level,
            $facility_type, $ownership, $county, $sub_county, $ward, $physical_address,
            $phone, $email, $sha_facility_code, $nhif_accreditation_status, $kra_pin,
            $is_active, $latitude, $longitude, $session_user_id, $facility_id
        );
        
        if ($update_stmt->execute()) {
            // Get new values for audit log
            $new_facility_sql = "SELECT * FROM facilities WHERE facility_id = ?";
            $new_facility_stmt = $mysqli->prepare($new_facility_sql);
            $new_facility_stmt->bind_param("i", $facility_id);
            $new_facility_stmt->execute();
            $new_facility_result = $new_facility_stmt->get_result();
            $new_facility = $new_facility_result->fetch_assoc();
            $new_facility_stmt->close();
            
            $new_values = json_encode($new_facility);
            
            // Log the action
            $audit_sql = "INSERT INTO audit_log (
                entity_type, entity_id, action, old_values, new_values, 
                description, user_id, ip_address, user_agent
            ) VALUES ('facility', ?, 'UPDATE', ?, ?, ?, ?, ?, ?)";
            
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Facility updated: $facility_name ($mfl_code)";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $audit_stmt->bind_param("issssiss", 
                $facility_id, $old_values, $new_values, $description, 
                $session_user_id, $ip_address, $user_agent
            );
            $audit_stmt->execute();
            $audit_stmt->close();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Facility updated successfully!";
            header("Location: facility.php");
            exit;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating facility: " . $mysqli->error;
            header("Location: facility_edit.php?id=" . $facility_id);
            exit;
        }
    } else {
        // Store errors and form data in session to repopulate form
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fix the following errors:<br>" . implode("<br>", $errors);
        header("Location: facility_edit.php?id=" . $facility_id);
        exit;
    }
}

// Get form data from session if exists (for repopulating after errors)
$form_data = $_SESSION['form_data'] ?? $facility;
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
    <title>Edit Facility - Clinic Management System</title>
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
            border-left: 4px solid #28a745;
        }
        .form-section h5 {
            color: #28a745;
            margin-bottom: 15px;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
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
        .history-timeline {
            position: relative;
            padding-left: 30px;
        }
        .history-timeline:before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        .history-item {
            position: relative;
            margin-bottom: 20px;
        }
        .history-item:before {
            content: '';
            position: absolute;
            left: -23px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #28a745;
            border: 2px solid white;
        }
        .history-content {
            background: white;
            padding: 10px 15px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
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
                            <a class="nav-link active" href="facility_edit.php?id=<?php echo $facility_id; ?>">
                                <i class="fas fa-edit me-1"></i>Edit Facility
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
                            <i class="fas fa-edit text-success me-2"></i>Edit Facility
                        </h1>
                        <p class="text-muted mb-0">
                            Editing: <?php echo htmlspecialchars($facility['facility_name']); ?>
                            <small class="ms-2">
                                <i class="fas fa-hashtag"></i> <?php echo $facility_id; ?>
                            </small>
                        </p>
                    </div>
                    <div>
                        <a href="facility.php" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-arrow-left me-2"></i>Back to Facilities
                        </a>
                        <a href="facility.php?id=<?php echo $facility_id; ?>" class="btn btn-info">
                            <i class="fas fa-eye me-2"></i>View Details
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

                <div class="row">
                    <!-- Main Form -->
                    <div class="col-md-8">
                        <div class="card shadow">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-edit me-2"></i>Edit Facility Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <form id="facilityForm" method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="facility_id" value="<?php echo $facility_id; ?>">
                                    
                                    <!-- Section 1: Basic Information -->
                                    <div class="form-section">
                                        <h5><i class="fas fa-id-card me-2"></i>Basic Information</h5>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="facility_name" class="form-label required">Facility Name</label>
                                                <input type="text" class="form-control <?php echo isset($form_errors['facility_name']) ? 'is-invalid' : ''; ?>" 
                                                       id="facility_name" name="facility_name" 
                                                       value="<?php echo htmlspecialchars($form_data['facility_name']); ?>" 
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
                                                       value="<?php echo htmlspecialchars($form_data['facility_internal_code']); ?>" 
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
                                                       value="<?php echo htmlspecialchars($form_data['mfl_code']); ?>" 
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
                                                    <option value="DISPENSARY" <?php echo $form_data['facility_type'] == 'DISPENSARY' ? 'selected' : ''; ?>>Dispensary</option>
                                                    <option value="HEALTH_CENTER" <?php echo $form_data['facility_type'] == 'HEALTH_CENTER' ? 'selected' : ''; ?>>Health Center</option>
                                                    <option value="SUB_COUNTY_HOSPITAL" <?php echo $form_data['facility_type'] == 'SUB_COUNTY_HOSPITAL' ? 'selected' : ''; ?>>Sub-County Hospital</option>
                                                    <option value="COUNTY_REFERRAL" <?php echo $form_data['facility_type'] == 'COUNTY_REFERRAL' ? 'selected' : ''; ?>>County Referral Hospital</option>
                                                    <option value="NATIONAL_REFERRAL" <?php echo $form_data['facility_type'] == 'NATIONAL_REFERRAL' ? 'selected' : ''; ?>>National Referral Hospital</option>
                                                    <option value="CLINIC" <?php echo $form_data['facility_type'] == 'CLINIC' ? 'selected' : ''; ?>>Clinic</option>
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
                                                    <option value="LEVEL_2" <?php echo $form_data['facility_level'] == 'LEVEL_2' ? 'selected' : ''; ?>>Level 2</option>
                                                    <option value="LEVEL_3" <?php echo $form_data['facility_level'] == 'LEVEL_3' ? 'selected' : ''; ?>>Level 3</option>
                                                    <option value="LEVEL_4" <?php echo $form_data['facility_level'] == 'LEVEL_4' ? 'selected' : ''; ?>>Level 4</option>
                                                    <option value="LEVEL_5" <?php echo $form_data['facility_level'] == 'LEVEL_5' ? 'selected' : ''; ?>>Level 5</option>
                                                    <option value="LEVEL_6" <?php echo $form_data['facility_level'] == 'LEVEL_6' ? 'selected' : ''; ?>>Level 6</option>
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
                                                    <option value="MOH" <?php echo $form_data['ownership'] == 'MOH' ? 'selected' : ''; ?>>Ministry of Health</option>
                                                    <option value="COUNTY" <?php echo $form_data['ownership'] == 'COUNTY' ? 'selected' : ''; ?>>County Government</option>
                                                    <option value="PRIVATE" <?php echo $form_data['ownership'] == 'PRIVATE' ? 'selected' : ''; ?>>Private</option>
                                                    <option value="FAITH_BASED" <?php echo $form_data['ownership'] == 'FAITH_BASED' ? 'selected' : ''; ?>>Faith-Based</option>
                                                    <option value="NGO" <?php echo $form_data['ownership'] == 'NGO' ? 'selected' : ''; ?>>NGO</option>
                                                    <option value="ARMED_FORCES" <?php echo $form_data['ownership'] == 'ARMED_FORCES' ? 'selected' : ''; ?>>Armed Forces</option>
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
                                                            <?php echo $form_data['county'] == $county ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($county); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    <option value="OTHER" <?php echo $form_data['county'] == 'OTHER' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                                <div class="invalid-feedback">
                                                    <?php echo $form_errors['county'] ?? ''; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4 mb-3">
                                                <label for="sub_county" class="form-label required">Sub-County</label>
                                                <input type="text" class="form-control <?php echo isset($form_errors['sub_county']) ? 'is-invalid' : ''; ?>" 
                                                       id="sub_county" name="sub_county" 
                                                       value="<?php echo htmlspecialchars($form_data['sub_county']); ?>" 
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
                                                       value="<?php echo htmlspecialchars($form_data['ward']); ?>" 
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
                                                          rows="2" maxlength="200"><?php echo htmlspecialchars($form_data['physical_address']); ?></textarea>
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
                                                           value="<?php echo htmlspecialchars($form_data['phone']); ?>" 
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
                                                           value="<?php echo htmlspecialchars($form_data['email']); ?>" 
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
                                                       value="<?php echo htmlspecialchars($form_data['sha_facility_code']); ?>" 
                                                       maxlength="30">
                                                <small class="form-text text-muted">Social Health Authority code</small>
                                            </div>
                                            
                                            <div class="col-md-4 mb-3">
                                                <label for="nhif_accreditation_status" class="form-label">NHIF Accreditation</label>
                                                <select class="form-select" id="nhif_accreditation_status" name="nhif_accreditation_status">
                                                    <option value="PENDING" <?php echo $form_data['nhif_accreditation_status'] == 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="ACCREDITED" <?php echo $form_data['nhif_accreditation_status'] == 'ACCREDITED' ? 'selected' : ''; ?>>Accredited</option>
                                                    <option value="SUSPENDED" <?php echo $form_data['nhif_accreditation_status'] == 'SUSPENDED' ? 'selected' : ''; ?>>Suspended</option>
                                                </select>
                                                <small class="form-text text-muted">National Hospital Insurance Fund status</small>
                                            </div>
                                            
                                            <div class="col-md-4 mb-3">
                                                <label for="kra_pin" class="form-label">KRA PIN</label>
                                                <input type="text" class="form-control <?php echo isset($form_errors['kra_pin']) ? 'is-invalid' : ''; ?>" 
                                                       id="kra_pin" name="kra_pin" 
                                                       value="<?php echo htmlspecialchars($form_data['kra_pin']); ?>" 
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
                                                           value="1" <?php echo $form_data['is_active'] == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="is_active">
                                                        Active Facility
                                                    </label>
                                                </div>
                                                <small class="form-text text-muted">Deactivate to hide facility from active lists</small>
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <div class="d-flex align-items-center">
                                                    <span class="me-3">Current Status:</span>
                                                    <span id="statusBadge" class="status-badge <?php echo $form_data['is_active'] == 1 ? 'badge-accredited' : 'badge-suspended'; ?>">
                                                        <?php echo $form_data['is_active'] == 1 ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Form Actions -->
                                    <div class="row mt-4">
                                        <div class="col-md-12">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                                                        <i class="fas fa-trash me-2"></i>Delete Facility
                                                    </button>
                                                </div>
                                                <div>
                                                    <button type="button" class="btn btn-outline-secondary me-2" onclick="window.location.href='facility.php'">
                                                        <i class="fas fa-times me-2"></i>Cancel
                                                    </button>
                                                    <button type="submit" class="btn btn-success">
                                                        <i class="fas fa-save me-2"></i>Update Facility
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar - Facility Info & History -->
                    <div class="col-md-4">
                        <!-- Facility Summary -->
                        <div class="card shadow mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-hospital me-2"></i>Facility Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <h6 class="text-muted mb-2">Current Details</h6>
                                    <dl class="row mb-0">
                                        <dt class="col-sm-5">MFL Code:</dt>
                                        <dd class="col-sm-7">
                                            <code><?php echo htmlspecialchars($facility['mfl_code']); ?></code>
                                        </dd>
                                        
                                        <dt class="col-sm-5">Internal Code:</dt>
                                        <dd class="col-sm-7">
                                            <code><?php echo htmlspecialchars($facility['facility_internal_code']); ?></code>
                                        </dd>
                                        
                                        <dt class="col-sm-5">Type & Level:</dt>
                                        <dd class="col-sm-7">
                                            <?php echo str_replace('_', ' ', $facility['facility_type']); ?> 
                                            (<?php echo str_replace('_', ' ', $facility['facility_level']); ?>)
                                        </dd>
                                        
                                        <dt class="col-sm-5">County:</dt>
                                        <dd class="col-sm-7"><?php echo htmlspecialchars($facility['county']); ?></dd>
                                        
                                        <dt class="col-sm-5">NHIF Status:</dt>
                                        <dd class="col-sm-7">
                                            <span class="badge badge-<?php 
                                                switch($facility['nhif_accreditation_status']) {
                                                    case 'ACCREDITED': echo 'success'; break;
                                                    case 'PENDING': echo 'warning'; break;
                                                    case 'SUSPENDED': echo 'danger'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst(strtolower($facility['nhif_accreditation_status'])); ?>
                                            </span>
                                        </dd>
                                    </dl>
                                </div>
                                
                                <hr>
                                
                                <div class="mb-3">
                                    <h6 class="text-muted mb-2">Timestamps</h6>
                                    <dl class="row mb-0">
                                        <dt class="col-sm-5">Created:</dt>
                                        <dd class="col-sm-7">
                                            <?php echo date('M j, Y H:i', strtotime($facility['created_at'])); ?>
                                            <?php if ($facility['created_by_name']): ?>
                                                <br><small class="text-muted">by <?php echo htmlspecialchars($facility['created_by_name']); ?></small>
                                            <?php endif; ?>
                                        </dd>
                                        
                                        <dt class="col-sm-5">Last Updated:</dt>
                                        <dd class="col-sm-7">
                                            <?php if ($facility['updated_at']): ?>
                                                <?php echo date('M j, Y H:i', strtotime($facility['updated_at'])); ?>
                                                <?php if ($facility['updated_by_name']): ?>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($facility['updated_by_name']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </dd>
                                    </dl>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Changes -->
                        <div class="card shadow">
                            <div class="card-header bg-warning">
                                <h5 class="card-title mb-0 text-white">
                                    <i class="fas fa-history me-2"></i>Recent Changes
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="history-timeline">
                                    <?php
                                    // Get audit log for this facility
                                    $audit_sql = "SELECT al.*, u.user_name 
                                                 FROM audit_log al
                                                 LEFT JOIN users u ON al.user_id = u.user_id
                                                 WHERE al.entity_type = 'facility' 
                                                 AND al.entity_id = ?
                                                 ORDER BY al.created_at DESC
                                                 LIMIT 5";
                                    $audit_stmt = $mysqli->prepare($audit_sql);
                                    $audit_stmt->bind_param("i", $facility_id);
                                    $audit_stmt->execute();
                                    $audit_result = $audit_stmt->get_result();
                                    
                                    if ($audit_result->num_rows > 0):
                                        while ($audit = $audit_result->fetch_assoc()):
                                    ?>
                                        <div class="history-item">
                                            <div class="history-content">
                                                <h6 class="mb-1">
                                                    <?php 
                                                    $action_icons = [
                                                        'CREATE' => 'fa-plus-circle text-success',
                                                        'UPDATE' => 'fa-edit text-warning',
                                                        'DELETE' => 'fa-trash text-danger',
                                                        'VIEW' => 'fa-eye text-info',
                                                        'EXPORT' => 'fa-download text-primary'
                                                    ];
                                                    $icon = $action_icons[$audit['action']] ?? 'fa-info-circle text-secondary';
                                                    ?>
                                                    <i class="fas <?php echo $icon; ?> me-1"></i>
                                                    <?php echo $audit['action']; ?>
                                                </h6>
                                                <p class="mb-1 small"><?php echo htmlspecialchars($audit['description'] ?? ''); ?></p>
                                                <div class="d-flex justify-content-between">
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($audit['user_name'] ?? 'System'); ?>
                                                    </small>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, H:i', strtotime($audit['created_at'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                        <div class="text-center py-3">
                                            <i class="fas fa-history fa-2x text-muted mb-2"></i>
                                            <p class="text-muted">No recent changes</p>
                                        </div>
                                    <?php endif; ?>
                                    $audit_stmt->close();
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="facility_history.php?id=<?php echo $facility_id; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-list me-1"></i>View Full History
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card shadow mt-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-bolt me-2"></i>Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="facility.php?id=<?php echo $facility_id; ?>" class="btn btn-info">
                                        <i class="fas fa-eye me-2"></i>View Details
                                    </a>
                                    <button type="button" class="btn btn-outline-primary" onclick="resetForm()">
                                        <i class="fas fa-undo me-2"></i>Reset Changes
                                    </button>
                                    <button type="button" class="btn btn-outline-warning" onclick="printFacility()">
                                        <i class="fas fa-print me-2"></i>Print Details
                                    </button>
                                    <button type="button" class="btn btn-outline-success" onclick="duplicateFacility()">
                                        <i class="fas fa-copy me-2"></i>Duplicate Facility
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-lightbulb me-2"></i>Editing Tips
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <i class="fas fa-exclamation-triangle text-warning me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Important Note</h6>
                                        <p class="text-muted small mb-0">MFL codes should rarely be changed as they are official government identifiers.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <i class="fas fa-history text-info me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Change History</h6>
                                        <p class="text-muted small mb-0">All changes are logged for audit purposes and can be reviewed in the history.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <i class="fas fa-clone text-success me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Duplication</h6>
                                        <p class="text-muted small mb-0">Use "Duplicate Facility" to create a new facility with similar details.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong><?php echo htmlspecialchars($facility['facility_name']); ?></strong>?</p>
                    <p class="text-danger">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        This action cannot be undone. All facility data will be permanently deleted.
                    </p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirmDeleteCheck">
                        <label class="form-check-label" for="confirmDeleteCheck">
                            I understand this action is irreversible
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                        <i class="fas fa-trash me-2"></i>Delete Facility
                    </button>
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
        
        // Enable/disable delete button based on checkbox
        $('#confirmDeleteCheck').change(function() {
            $('#confirmDeleteBtn').prop('disabled', !$(this).is(':checked'));
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
    });
    
    function isValidEmail(email) {
        const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
        return re.test(String(email).toLowerCase());
    }
    
    function isValidKRAPin(pin) {
        const re = /^[A-Z]{1}[0-9]{9}[A-Z]{1}$/;
        return re.test(pin);
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
    
    function confirmDelete() {
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
    
    $('#confirmDeleteBtn').click(function() {
        window.location.href = 'facility_delete.php?id=<?php echo $facility_id; ?>';
    });
    
    function resetForm() {
        if (confirm('Reset all changes to original values?')) {
            // Reload the page to get original values
            window.location.reload();
        }
    }
    
    function printFacility() {
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Facility Details - <?php echo htmlspecialchars($facility['facility_name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h2 { color: #333; border-bottom: 2px solid #28a745; padding-bottom: 10px; }
                        .section { margin-bottom: 20px; }
                        .section h3 { color: #28a745; font-size: 16px; margin-bottom: 10px; }
                        dl { display: grid; grid-template-columns: max-content auto; gap: 10px; }
                        dt { font-weight: bold; text-align: right; }
                        dd { margin: 0; }
                        code { background: #f5f5f5; padding: 2px 4px; border-radius: 3px; }
                        .badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
                        @media print {
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h2>Facility Details</h2>
                    <p><strong>Generated on:</strong> ${new Date().toLocaleString()}</p>
                    
                    <div class="section">
                        <h3>Basic Information</h3>
                        <dl>
                            <dt>Facility Name:</dt><dd><?php echo htmlspecialchars($facility['facility_name']); ?></dd>
                            <dt>MFL Code:</dt><dd><code><?php echo htmlspecialchars($facility['mfl_code']); ?></code></dd>
                            <dt>Internal Code:</dt><dd><code><?php echo htmlspecialchars($facility['facility_internal_code']); ?></code></dd>
                            <dt>Type:</dt><dd><?php echo str_replace('_', ' ', $facility['facility_type']); ?></dd>
                            <dt>Level:</dt><dd><?php echo str_replace('_', ' ', $facility['facility_level']); ?></dd>
                            <dt>Ownership:</dt><dd><?php echo str_replace('_', ' ', $facility['ownership']); ?></dd>
                        </dl>
                    </div>
                    
                    <div class="section">
                        <h3>Location Information</h3>
                        <dl>
                            <dt>County:</dt><dd><?php echo htmlspecialchars($facility['county']); ?></dd>
                            <dt>Sub-County:</dt><dd><?php echo htmlspecialchars($facility['sub_county']); ?></dd>
                            <dt>Ward:</dt><dd><?php echo htmlspecialchars($facility['ward']); ?></dd>
                            <dt>Address:</dt><dd><?php echo htmlspecialchars($facility['physical_address'] ?: 'Not specified'); ?></dd>
                        </dl>
                    </div>
                    
                    <div class="section">
                        <h3>Contact & Regulatory</h3>
                        <dl>
                            <dt>Phone:</dt><dd><?php echo htmlspecialchars($facility['phone'] ?: 'Not specified'); ?></dd>
                            <dt>Email:</dt><dd><?php echo htmlspecialchars($facility['email'] ?: 'Not specified'); ?></dd>
                            <dt>NHIF Status:</dt><dd><?php echo ucfirst(strtolower($facility['nhif_accreditation_status'])); ?></dd>
                            <dt>KRA PIN:</dt><dd><?php echo htmlspecialchars($facility['kra_pin'] ?: 'Not specified'); ?></dd>
                            <dt>Status:</dt><dd><?php echo $facility['is_active'] == 1 ? 'Active' : 'Inactive'; ?></dd>
                        </dl>
                    </div>
                    
                    <div class="section no-print">
                        <button onclick="window.print()">Print</button>
                        <button onclick="window.close()">Close</button>
                    </div>
                </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    function duplicateFacility() {
        if (confirm('Create a copy of this facility? You will need to provide a new MFL code.')) {
            window.location.href = 'facility_duplicate.php?id=<?php echo $facility_id; ?>';
        }
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
    </script>
</body>
</html>
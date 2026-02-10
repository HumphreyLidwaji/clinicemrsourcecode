<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Check if user has permission to upload files
if (!SimplePermission::any(['patient_files_upload', '*'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "You don't have permission to access this page.";
    header("Location: /clinic/dashboard.php");
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $visit_id = !empty($_POST['visit_id']) ? intval($_POST['visit_id']) : null;
    $file_category = sanitizeInput($_POST['file_category']);
    $file_description = sanitizeInput($_POST['file_description']);
    $file_visibility = sanitizeInput($_POST['file_visibility']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: upload_files.php");
        exit;
    }

    // Validate required fields - Visit is now required
    if (empty($visit_id) || empty($file_category)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a visit and file category.";
        header("Location: upload_files.php");
        exit;
    }

    // Check if files were uploaded
    if (empty($_FILES['patient_files']['name'][0])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select at least one file to upload.";
        header("Location: upload_files.php");
        exit;
    }

    // Get patient_id from the selected visit
    $patient_sql = "SELECT patient_id FROM visits WHERE visit_id = ?";
    $patient_stmt = $mysqli->prepare($patient_sql);
    $patient_stmt->bind_param("i", $visit_id);
    $patient_stmt->execute();
    $patient_result = $patient_stmt->get_result();
    
    if ($patient_result->num_rows === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid visit selected.";
        header("Location: upload_files.php");
        exit;
    }
    
    $visit_data = $patient_result->fetch_assoc();
    $patient_id = $visit_data['patient_id'];

    // File upload configuration
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/patient_files/';
    $allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB

    // Create upload directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            $errors[] = "Failed to create upload directory: $uploadDir";
            error_log("Failed to create upload directory: " . $uploadDir);
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "System error: Could not create upload directory. Please contact administrator.";
            header("Location: upload_files.php");
            exit;
        }
    } else {
        // Directory exists, check if it's writable
        if (!is_writable($uploadDir)) {
            $errors[] = "Upload directory is not writable: $uploadDir";
            if (!chmod($uploadDir, 0755)) {
                error_log("Upload directory not writable and couldn't fix permissions: " . $uploadDir);
            }
        }
    }

    $uploadedFiles = [];
    $errors = [];

    // Process each uploaded file
    foreach ($_FILES['patient_files']['name'] as $key => $name) {
        $fileTmp = $_FILES['patient_files']['tmp_name'][$key];
        $fileSize = $_FILES['patient_files']['size'][$key];
        $fileError = $_FILES['patient_files']['error'][$key];
        
        // Skip if no file was uploaded for this field
        if ($fileError === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        
        // Check for upload errors
        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading file '$name': " . getUploadError($fileError);
            continue;
        }
        
        // Check file size
        if ($fileSize > $maxFileSize) {
            $errors[] = "File '$name' exceeds maximum size limit of 10MB.";
            continue;
        }
        
        // Get file extension and validate type
        $fileExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedTypes)) {
            $errors[] = "File type '$fileExt' is not allowed for file '$name'.";
            continue;
        }
        
        // Generate unique filename
        $newFilename = uniqid() . '_' . time() . '.' . $fileExt;
        $filePath = $uploadDir . $newFilename;
        
        // Move uploaded file
        if (move_uploaded_file($fileTmp, $filePath)) {
            $uploadedFiles[] = [
                'original_name' => $name,
                'saved_name' => $newFilename,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'file_type' => $fileExt
            ];
        } else {
            // Detailed error information
            $uploadErrors = [];
            
            if (!file_exists($fileTmp)) {
                $uploadErrors[] = "Temporary file doesn't exist";
            } elseif (!is_readable($fileTmp)) {
                $uploadErrors[] = "Temporary file not readable";
            }
            
            if (!is_dir($uploadDir)) {
                $uploadErrors[] = "Upload directory doesn't exist";
            } elseif (!is_writable($uploadDir)) {
                $uploadErrors[] = "Upload directory not writable";
            }
            
            $freeSpace = disk_free_space($uploadDir);
            if ($freeSpace !== false && $freeSpace < $fileSize) {
                $uploadErrors[] = "Insufficient disk space";
            }
            
            $lastError = error_get_last();
            if ($lastError) {
                $uploadErrors[] = "PHP error: " . $lastError['message'];
            }
            
            $errorMsg = "Failed to save file '$name'";
            if (!empty($uploadErrors)) {
                $errorMsg .= " - " . implode(", ", $uploadErrors);
            }
            $errors[] = $errorMsg;
            
            error_log("File upload failed: " . $errorMsg . " | Tmp: " . $fileTmp . " | Dest: " . $filePath);
        }
    }
    
    // If there were errors with some files but others succeeded
    if (!empty($errors) && !empty($uploadedFiles)) {
        $_SESSION['alert_type'] = "warning";
        $_SESSION['alert_message'] = "Some files were uploaded successfully, but there were errors: " . implode(' ', $errors);
    } 
    // If all files failed
    elseif (!empty($errors) && empty($uploadedFiles)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "File upload failed: " . implode(' ', $errors);
        header("Location: upload_files.php");
        exit;
    }
    
    // Save file information to database
    if (!empty($uploadedFiles)) {
        $successCount = 0;
        $file_ids = []; // Track uploaded file IDs for audit log
        
        foreach ($uploadedFiles as $file) {
            $file_sql = "INSERT INTO patient_files (
                file_patient_id, file_visit_id, file_category, file_original_name,
                file_saved_name, file_path, file_size, file_type, file_description,
                file_visibility, file_uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $file_stmt = $mysqli->prepare($file_sql);
            $file_stmt->bind_param(
                "iissssisssi",
                $patient_id,
                $visit_id,
                $file_category,
                $file['original_name'],
                $file['saved_name'],
                $file['file_path'],
                $file['file_size'],
                $file['file_type'],
                $file_description,
                $file_visibility,
                $session_user_id
            );
            
            if ($file_stmt->execute()) {
                $file_id = $file_stmt->insert_id;
                $file_ids[] = $file_id;
                $successCount++;
            }
            $file_stmt->close();
        }
        
        if ($successCount > 0) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Successfully uploaded $successCount file(s) to visit #$visit_id.";
            
            // Log activity - your existing logging
            $activity_query = $mysqli->prepare("
                INSERT INTO patient_activities 
                (patient_id, activity_type, activity_description, created_by, created_at) 
                VALUES (?, 'File Upload', ?, ?, NOW())
            ");
            $description = "Uploaded $successCount file(s) to visit #$visit_id in category: $file_category";
            $activity_query->bind_param('isi', $patient_id, $description, $session_user_id);
            $activity_query->execute();
            
            // Also log visit activity
            $visit_activity_query = $mysqli->prepare("
                INSERT INTO visit_activities 
                (visit_id, activity_type, activity_description, created_by, created_at) 
                VALUES (?, 'File Upload', ?, ?, NOW())
            ");
            $visit_description = "Uploaded $successCount file(s) in category: $file_category";
            $visit_activity_query->bind_param('isi', $visit_id, $visit_description, $session_user_id);
            $visit_activity_query->execute();
            
            // AUDIT LOG: Log file upload
            $new_data = [
                'file_patient_id' => $patient_id,
                'file_visit_id' => $visit_id,
                'file_category' => $file_category,
                'file_description' => $file_description,
                'file_visibility' => $file_visibility,
                'file_count' => $successCount,
                'file_ids' => $file_ids,
                'uploaded_files' => array_map(function($file) {
                    return [
                        'original_name' => $file['original_name'],
                        'file_size' => $file['file_size'],
                        'file_type' => $file['file_type']
                    ];
                }, $uploadedFiles)
            ];
            
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE',
                'module'      => 'Patient Files',
                'table_name'  => 'patient_files',
                'entity_type' => 'patient_file',
                'record_id'   => !empty($file_ids) ? implode(',', $file_ids) : null,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Uploaded $successCount patient file(s)",
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => $new_data
            ]);
        }
    }
    
    header("Location: upload_files.php");
    exit;
}

// Get visit_id or patient_id from URL if passed
$selected_visit_id = intval($_GET['visit_id'] ?? 0);
$selected_patient_id = intval($_GET['patient_id'] ?? 0);

// Get data for dropdowns - Updated with new field names
$patients_sql = "SELECT 
                    patient_id, 
                    first_name, 
                    middle_name, 
                    last_name, 
                    patient_mrn,
                    CONCAT(first_name, 
                           CASE WHEN middle_name IS NOT NULL AND middle_name != '' 
                                THEN CONCAT(' ', middle_name) 
                                ELSE '' 
                           END, 
                           ' ', 
                           last_name) as patient_full_name
                 FROM patients 
                 WHERE patient_status != 'ARCHIVED' 
                 ORDER BY first_name, last_name ASC";
$patients_result = $mysqli->query($patients_sql);

// Get visits for dropdown - all active visits or filtered by patient
$visits_sql = "
    SELECT 
        v.visit_id, 
        v.visit_datetime as visit_date, 
        v.visit_type,
        v.visit_status,
        p.patient_id,
        p.first_name,
        p.last_name,
        p.patient_mrn
    FROM visits v
    LEFT JOIN patients p ON v.patient_id = p.patient_id
    WHERE v.visit_status != 'CLOSED'
";

if ($selected_patient_id > 0) {
    $visits_sql .= " AND v.patient_id = ?";
}

$visits_sql .= " ORDER BY v.visit_datetime DESC";

$visits_stmt = $mysqli->prepare($visits_sql);
if ($selected_patient_id > 0) {
    $visits_stmt->bind_param("i", $selected_patient_id);
}
$visits_stmt->execute();
$visits_result = $visits_stmt->get_result();

// Get recent uploads for the sidebar
$recent_uploads_sql = "
    SELECT 
        pf.file_id, 
        pf.file_original_name, 
        pf.file_uploaded_at,
        pf.file_visit_id,
        p.first_name, 
        p.last_name, 
        p.patient_mrn,
        v.visit_type
    FROM patient_files pf
    JOIN patients p ON pf.file_patient_id = p.patient_id
    LEFT JOIN visits v ON pf.file_visit_id = v.visit_id
    WHERE pf.file_archived_at IS NULL
    ORDER BY pf.file_uploaded_at DESC
    LIMIT 5
";
$recent_uploads_result = $mysqli->query($recent_uploads_sql);

// Get today's upload statistics
$today_uploads_sql = "SELECT COUNT(*) as count FROM patient_files WHERE DATE(file_uploaded_at) = CURDATE()";
$today_result = $mysqli->query($today_uploads_sql);
$today_uploads = $today_result->fetch_assoc()['count'];

// Helper function for upload errors
function getUploadError($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return "File exceeds upload_max_filesize directive in php.ini.";
        case UPLOAD_ERR_FORM_SIZE:
            return "File exceeds MAX_FILE_SIZE directive in HTML form.";
        case UPLOAD_ERR_PARTIAL:
            return "File was only partially uploaded.";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing temporary folder.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Failed to write file to disk.";
        case UPLOAD_ERR_EXTENSION:
            return "File upload stopped by extension.";
        default:
            return "Unknown upload error.";
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-upload mr-2"></i>Upload Patient Files
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="/clinic/patient/patient_files.php" class="btn btn-light">
                    <i class="fas fa-folder mr-2"></i>View All Files
                </a>
                <a href="/clinic/patient/visit.php" class="btn btn-light">
                    <i class="fas fa-notes-medical mr-2"></i>Back to Visits
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

        <!-- Upload Information Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-info ml-2">New Upload</span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Uploads Today:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $today_uploads; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Max Size:</strong> 
                            <span class="badge badge-warning ml-2">10MB/file</span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="/clinic/patient/visit.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="uploadFilesForm" class="btn btn-primary">
                            <i class="fas fa-upload mr-2"></i>Upload Files
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" id="uploadFilesForm" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="row">
                <!-- Left Column: Visit & File Details -->
                <div class="col-md-6">
                    <!-- Visit Selection Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-calendar-check mr-2"></i>Visit Selection</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>Filter by Patient (Optional)</label>
                                        <select class="form-control select2" id="patient_filter" name="patient_filter">
                                            <option value="">- All Patients -</option>
                                            <?php 
                                            $patients_result->data_seek(0);
                                            while ($patient = $patients_result->fetch_assoc()): ?>
                                                <option value="<?php echo $patient['patient_id']; ?>" 
                                                    <?php if ($patient['patient_id'] == $selected_patient_id) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($patient['patient_full_name']); ?> 
                                                    (MRN: <?php echo htmlspecialchars($patient['patient_mrn']); ?>)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                        <small class="form-text text-muted">
                                            Filter visits by patient to make selection easier
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label class="required">Select Visit</label>
                                        <select class="form-control select2" id="visit_id" name="visit_id" required data-placeholder="Select a visit">
                                            <option value=""></option>
                                            <?php if ($visits_result && $visits_result->num_rows > 0): 
                                                $visits_result->data_seek(0);
                                                while ($visit = $visits_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $visit['visit_id']; ?>" 
                                                        <?php if ($visit['visit_id'] == $selected_visit_id) echo 'selected'; ?>
                                                        data-patient-id="<?php echo $visit['patient_id']; ?>"
                                                        data-patient-name="<?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?>"
                                                        data-patient-mrn="<?php echo htmlspecialchars($visit['patient_mrn']); ?>">
                                                        Visit #<?php echo $visit['visit_id']; ?> - 
                                                        <?php echo htmlspecialchars($visit['visit_type']); ?> - 
                                                        <?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?> 
                                                        (MRN: <?php echo htmlspecialchars($visit['patient_mrn']); ?>) -
                                                        <?php echo date('M j, Y', strtotime($visit['visit_date'])); ?>
                                                        <small class="text-muted">(<?php echo $visit['visit_status']; ?>)</small>
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <option value="">No visits found</option>
                                            <?php endif; ?>
                                        </select>
                                        <small class="form-text text-muted">
                                            Files will be linked to this visit and automatically associated with the patient
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- File Details Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-file-medical mr-2"></i>File Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">File Category</label>
                                        <select class="form-control select2" id="file_category" name="file_category" required>
                                            <option value="">- Select Category -</option>
                                            <option value="Medical Report">Medical Report</option>
                                            <option value="Lab Results">Lab Results</option>
                                            <option value="Imaging">Imaging (X-Ray, MRI, CT)</option>
                                            <option value="Prescription">Prescription</option>
                                            <option value="Insurance">Insurance Documents</option>
                                            <option value="Consent Form">Consent Form</option>
                                            <option value="Identification">Identification</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>File Visibility</label>
                                        <select class="form-control select2" id="file_visibility" name="file_visibility">
                                            <option value="Private" selected>Private (Staff Only)</option>
                                            <option value="Shared">Shared (Patient Visible)</option>
                                            <option value="Restricted">Restricted (Admin Only)</option>
                                        </select>
                                        <small class="form-text text-muted">
                                            Controls who can view this file
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>File Description</label>
                                        <textarea class="form-control" id="file_description" name="file_description" rows="3" 
                                                  placeholder="Brief description of the files being uploaded..."></textarea>
                                        <small class="form-text text-muted">
                                            Optional description that will apply to all files in this upload
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: File Upload & Preview -->
                <div class="col-md-6">
                    <!-- File Upload Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-cloud-upload-alt mr-2"></i>File Upload</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="required">Select Files</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="patient_files" name="patient_files[]" 
                                           multiple required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt">
                                    <label class="custom-file-label" for="patient_files" id="fileLabel">Choose files</label>
                                </div>
                                <small class="form-text text-muted">
                                    Maximum file size: 10MB per file. Allowed types: PDF, JPG, PNG, GIF, DOC, DOCX, XLS, XLSX, TXT
                                </small>
                            </div>

                            <!-- File Preview -->
                            <div class="mt-3">
                                <div id="filePreview" class="d-none">
                                    <h6>Selected Files:</h6>
                                    <div id="fileList" class="list-group"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Preview Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-info py-2">
                            <h4 class="card-title mb-0 text-white"><i class="fas fa-eye mr-2"></i>Upload Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%" class="text-muted">Visit:</th>
                                        <td id="preview_visit" class="font-weight-bold">-</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Patient:</th>
                                        <td id="preview_patient" class="font-weight-bold">-</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Category:</th>
                                        <td id="preview_category" class="font-weight-bold">-</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Visibility:</th>
                                        <td id="preview_visibility" class="font-weight-bold">-</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Files Selected:</th>
                                        <td id="preview_filecount" class="font-weight-bold">0</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Total Size:</th>
                                        <td id="preview_totalsize" class="font-weight-bold">0 MB</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Uploads Card -->
                    <div class="card">
                        <div class="card-header bg-dark py-2">
                            <h4 class="card-title mb-0 text-white"><i class="fas fa-history mr-2"></i>Recent Uploads</h4>
                        </div>
                        <div class="card-body">
                            <?php if ($recent_uploads_result && $recent_uploads_result->num_rows > 0): 
                                $recent_uploads_result->data_seek(0);
                                ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($upload = $recent_uploads_result->fetch_assoc()): ?>
                                        <div class="list-group-item p-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 small"><?php echo htmlspecialchars($upload['file_original_name']); ?></h6>
                                                <small><?php echo time_elapsed_string($upload['file_uploaded_at']); ?></small>
                                            </div>
                                            <p class="mb-1 small">
                                                <?php echo htmlspecialchars($upload['patient_first_name'] . ' ' . $upload['patient_last_name']); ?>
                                                (MRN: <?php echo htmlspecialchars($upload['patient_mrn']); ?>)
                                            </p>
                                            <?php if ($upload['file_visit_id']): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar-check mr-1"></i>
                                                    Visit #<?php echo $upload['file_visit_id']; ?> (<?php echo $upload['visit_type']; ?>)
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-folder-open fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">No recent uploads</p>
                                </div>
                            <?php endif; ?>
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
                                    <a href="/clinic/patient/visit.php" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary" onclick="resetFileInput()">
                                        <i class="fas fa-redo mr-2"></i>Reset Form
                                    </button>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-upload mr-2"></i>Upload Files
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
        placeholder: "Select...",
        theme: 'bootstrap'
    });

    // Filter visits by patient
    $('#patient_filter').change(function() {
        var patientId = $(this).val();
        if (patientId) {
            window.location.href = 'upload_files.php?patient_id=' + patientId;
        } else {
            window.location.href = 'upload_files.php';
        }
    });

    // Update file label when files are selected
    $('#patient_files').on('change', function() {
        var files = $(this)[0].files;
        var fileNames = [];
        var totalSize = 0;
        
        for (var i = 0; i < files.length; i++) {
            fileNames.push(files[i].name);
            totalSize += files[i].size;
        }
        
        if (files.length > 0) {
            $('#fileLabel').text(files.length + ' file(s) selected');
            $('#filePreview').removeClass('d-none');
            
            // Update file list
            var fileListHtml = '';
            for (var i = 0; i < files.length; i++) {
                var fileSize = (files[i].size / (1024 * 1024)).toFixed(2);
                fileListHtml += '<div class="list-group-item d-flex justify-content-between align-items-center">' +
                               '<span class="small text-truncate mr-2" style="max-width: 70%;">' + files[i].name + '</span>' +
                               '<span class="badge badge-secondary badge-pill">' + fileSize + ' MB</span>' +
                               '</div>';
            }
            $('#fileList').html(fileListHtml);
            
            // Update preview
            $('#preview_filecount').text(files.length);
            $('#preview_totalsize').text((totalSize / (1024 * 1024)).toFixed(2) + ' MB');
        } else {
            $('#fileLabel').text('Choose files');
            $('#filePreview').addClass('d-none');
            $('#preview_filecount').text('0');
            $('#preview_totalsize').text('0 MB');
        }
    });

    // Update preview when visit is selected
    $('#visit_id').change(function() {
        var selectedOption = $(this).find('option:selected');
        if (selectedOption.val()) {
            var visitText = selectedOption.text().split(' - ')[0];
            var patientName = selectedOption.data('patient-name');
            var patientMrn = selectedOption.data('patient-mrn');
            
            $('#preview_visit').text(visitText);
            $('#preview_patient').text(patientName + ' (MRN: ' + patientMrn + ')');
        } else {
            $('#preview_visit').text('-');
            $('#preview_patient').text('-');
        }
    });

    $('#file_category').change(function() {
        $('#preview_category').text($(this).val() || '-');
    });

    $('#file_visibility').change(function() {
        $('#preview_visibility').text($(this).val() || '-');
    });

    // Initialize preview
    $('#preview_category').text($('#file_category').val() || '-');
    $('#preview_visibility').text($('#file_visibility').val() || '-');

    // Form validation
    $('#uploadFilesForm').on('submit', function(e) {
        var isValid = true;
        $('.is-invalid').removeClass('is-invalid');
        
        // Check required fields
        var required = ['visit_id', 'file_category'];
        required.forEach(function(field) {
            if (!$('#' + field).val()) {
                isValid = false;
                $('#' + field).addClass('is-invalid');
                if ($('#' + field).is('select')) {
                    $('#' + field).next('.select2-container').find('.select2-selection').addClass('is-invalid');
                }
            }
        });

        // Check if files are selected
        var files = $('#patient_files')[0].files;
        if (files.length === 0) {
            isValid = false;
            $('#patient_files').addClass('is-invalid');
            $('#fileLabel').addClass('is-invalid');
        } else {
            // Validate file sizes
            var maxSize = 10 * 1024 * 1024; // 10MB
            for (var i = 0; i < files.length; i++) {
                if (files[i].size > maxSize) {
                    isValid = false;
                    alert('File "' + files[i].name + '" exceeds the maximum size limit of 10MB.');
                    break;
                }
            }
        }

        if (!isValid) {
            e.preventDefault();
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#uploadFilesForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields and select at least one valid file.' +
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
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Uploading...').prop('disabled', true);
    });
    
    // Remove invalid class when field is filled
    $('[required]').on('input change', function() {
        if ($(this).val() !== '') {
            $(this).removeClass('is-invalid');
            if ($(this).is('select')) {
                $(this).next('.select2-container').find('.select2-selection').removeClass('is-invalid');
            }
        }
    });
});

// Reset file input
function resetFileInput() {
    $('#patient_files').val('');
    $('#fileLabel').text('Choose files');
    $('#filePreview').addClass('d-none');
    $('#preview_filecount').text('0');
    $('#preview_totalsize').text('0 MB');
    $('.select2').val(null).trigger('change');
    $('#preview_visit').text('-');
    $('#preview_patient').text('-');
    $('#preview_category').text('-');
    $('#preview_visibility').text('-');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P to focus on patient filter
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        $('#patient_filter').select2('open');
    }
    // Ctrl + V to focus on visit field
    if (e.ctrlKey && e.keyCode === 86) {
        e.preventDefault();
        $('#visit_id').select2('open');
    }
    // Ctrl + U to submit form
    if (e.ctrlKey && e.keyCode === 85) {
        e.preventDefault();
        $('#uploadFilesForm').submit();
    }
    // Escape to reset form
    if (e.keyCode === 27) {
        if (confirm('Are you sure you want to reset the form?')) {
            resetFileInput();
        }
    }
});

// Helper function for time display
function time_elapsed_string(datetime) {
    var time = new Date(datetime).getTime();
    var now = new Date().getTime();
    var diff = (now - time) / 1000;
    
    if (diff < 60) {
        return 'just now';
    } else if (diff < 3600) {
        return Math.floor(diff / 60) + ' minutes ago';
    } else if (diff < 86400) {
        return Math.floor(diff / 3600) + ' hours ago';
    } else if (diff < 604800) {
        return Math.floor(diff / 86400) + ' days ago';
    } else {
        return new Date(datetime).toLocaleDateString();
    }
}
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
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
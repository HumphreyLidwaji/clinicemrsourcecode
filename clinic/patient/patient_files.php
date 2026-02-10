<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Add audit functions

// Default Column Sortby/Order Filter
$sort = "file_uploaded_at";
$order = "DESC";

// File Category Filter
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $category_query = "AND (pf.file_category = '" . sanitizeInput($_GET['category']) . "')";
    $category_filter = nullable_htmlentities($_GET['category']);
} else {
    // Default - any
    $category_query = '';
    $category_filter = '';
}

// File Visibility Filter
if (isset($_GET['visibility']) && !empty($_GET['visibility'])) {
    $visibility_query = "AND (pf.file_visibility = '" . sanitizeInput($_GET['visibility']) . "')";
    $visibility_filter = nullable_htmlentities($_GET['visibility']);
} else {
    // Default - any
    $visibility_query = '';
    $visibility_filter = '';
}

// Patient Filter
if (isset($_GET['patient']) && !empty($_GET['patient'])) {
    $patient_id_filter = intval($_GET['patient']);
    $patient_query = "AND (pf.file_patient_id = $patient_id_filter)";
} else {
    $patient_id_filter = 0;
    $patient_query = '';
}

// Date Range for Files
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Handle file actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: patient_files.php");
        exit;
    }

    // Archive file
    if (isset($_POST['archive_file'])) {
        $file_id = intval($_POST['file_id']);
        
        if (SimplePermission::any(['patient_files_delete', '*'])) {
            // First get file details for audit log
            $file_query = $mysqli->prepare("SELECT file_patient_id, file_original_name, file_category FROM patient_files WHERE file_id = ?");
            $file_query->bind_param("i", $file_id);
            $file_query->execute();
            $file_result = $file_query->get_result();
            $file_data = $file_result->fetch_assoc();
            
            $archive_sql = "UPDATE patient_files SET file_archived_at = NOW(), file_archived_by = ? WHERE file_id = ?";
            $archive_stmt = $mysqli->prepare($archive_sql);
            $archive_stmt->bind_param("ii", $session_user_id, $file_id);
            
            if ($archive_stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "File archived successfully.";
                
                // Log activity
                if ($file_data) {
                    $activity_query = $mysqli->prepare("
                        INSERT INTO patient_activities 
                        (patient_id, activity_type, activity_description, created_by, created_at) 
                        VALUES (?, 'File Archive', ?, ?, NOW())
                    ");
                    $description = "Archived file: " . $file_data['file_original_name'];
                    $activity_query->bind_param('isi', $file_data['file_patient_id'], $description, $session_user_id);
                    $activity_query->execute();
                    
                    // AUDIT LOG: File archive
                    audit_log($mysqli, [
                        'user_id'     => $session_user_id,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'DELETE',
                        'module'      => 'Patient Files',
                        'table_name'  => 'patient_files',
                        'entity_type' => 'patient_file',
                        'record_id'   => $file_id,
                        'patient_id'  => $file_data['file_patient_id'],
                        'description' => "Archived file: " . $file_data['file_original_name'],
                        'status'      => 'SUCCESS',
                        'old_values'  => [
                            'file_id' => $file_id,
                            'file_original_name' => $file_data['file_original_name'],
                            'file_category' => $file_data['file_category']
                        ],
                        'new_values'  => ['file_archived_at' => date('Y-m-d H:i:s'), 'file_archived_by' => $session_user_id]
                    ]);
                }
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error archiving file: " . $mysqli->error;
            }
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "You don't have permission to archive files.";
        }
        
        header("Location: patient_files.php");
        exit;
    }

    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['selected_files'])) {
        $bulk_action = sanitizeInput($_POST['bulk_action']);
        $selected_files = $_POST['selected_files'];
        $file_ids = array_map('intval', $selected_files);
        $placeholders = implode(',', array_fill(0, count($file_ids), '?'));
        
        if ($bulk_action === 'archive' && SimplePermission::any(['patient_files_delete', '*'])) {
            // Get file details for audit log before archiving
            $files_sql = "SELECT file_id, file_patient_id, file_original_name FROM patient_files WHERE file_id IN ($placeholders)";
            $files_stmt = $mysqli->prepare($files_sql);
            $types = str_repeat('i', count($file_ids));
            $files_stmt->bind_param($types, ...$file_ids);
            $files_stmt->execute();
            $files_result = $files_stmt->get_result();
            $files_to_archive = [];
            while ($row = $files_result->fetch_assoc()) {
                $files_to_archive[] = $row;
            }
            
            $bulk_sql = "UPDATE patient_files SET file_archived_at = NOW(), file_archived_by = ? WHERE file_id IN ($placeholders)";
            $bulk_stmt = $mysqli->prepare($bulk_sql);
            $types = str_repeat('i', count($file_ids) + 1);
            $params = array_merge([$session_user_id], $file_ids);
            $bulk_stmt->bind_param($types, ...$params);
            
            if ($bulk_stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Successfully archived " . count($file_ids) . " file(s).";
                
                // AUDIT LOG: Bulk file archive
                foreach ($files_to_archive as $file) {
                    audit_log($mysqli, [
                        'user_id'     => $session_user_id,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'DELETE',
                        'module'      => 'Patient Files',
                        'table_name'  => 'patient_files',
                        'entity_type' => 'patient_file',
                        'record_id'   => $file['file_id'],
                        'patient_id'  => $file['file_patient_id'],
                        'description' => "Bulk archived file: " . $file['file_original_name'],
                        'status'      => 'SUCCESS',
                        'old_values'  => [
                            'file_id' => $file['file_id'],
                            'file_original_name' => $file['file_original_name']
                        ],
                        'new_values'  => ['file_archived_at' => date('Y-m-d H:i:s'), 'file_archived_by' => $session_user_id]
                    ]);
                }
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error performing bulk action: " . $mysqli->error;
            }
        } elseif ($bulk_action === 'download' && SimplePermission::any(['patient_files_download', '*'])) {
            // Handle bulk download - this would typically redirect to a download script
            $_SESSION['alert_type'] = "info";
            $_SESSION['alert_message'] = "Bulk download feature would be implemented here.";
        }
        
        header("Location: patient_files.php");
        exit;
    }
}

// Main query for files - UPDATED FIELD NAMES
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        pf.*,
        p.patient_id,
        p.first_name, 
        p.middle_name,
        p.last_name, 
        p.patient_mrn,
        p.date_of_birth,
        p.sex,
        u.user_name as uploaded_by_name,
        v.visit_id,
        v.visit_type,
        v.visit_datetime,
        ub.user_name as archived_by_name
    FROM patient_files pf
    LEFT JOIN patients p ON pf.file_patient_id = p.patient_id
    LEFT JOIN users u ON pf.file_uploaded_by = u.user_id
    LEFT JOIN visits v ON pf.file_visit_id = v.visit_id
    LEFT JOIN users ub ON pf.file_archived_by = ub.user_id
    WHERE (p.first_name LIKE '%$q%' 
           OR p.last_name LIKE '%$q%'
           OR p.patient_mrn LIKE '%$q%' 
           OR pf.file_original_name LIKE '%$q%' 
           OR pf.file_description LIKE '%$q%')
      AND pf.file_archived_at IS NULL
      AND DATE(pf.file_uploaded_at) BETWEEN '$dtf' AND '$dtt'
      $category_query
      $visibility_query
      $patient_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get data for filters
$patients_sql = "SELECT patient_id, first_name, last_name, patient_mrn FROM patients WHERE patient_status != 'ARCHIVED' ORDER BY first_name, last_name";
$patients_result = $mysqli->query($patients_sql);

// Get file statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_files,
        SUM(file_size) as total_size,
        COUNT(DISTINCT file_patient_id) as total_patients,
        COUNT(DISTINCT file_category) as total_categories
    FROM patient_files 
    WHERE file_archived_at IS NULL
";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

?>

<div class="card">
   <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2">
            <i class="fa fa-fw fa-folder mr-2"></i>Patient Files
        </h3>
        <div class="card-tools">
            <?php if (SimplePermission::any("patient_files_upload")) { ?>
                <a href="upload_files.php" class="btn btn-primary">
                    <i class="fas fa-upload mr-2"></i>Upload Files
                </a>
            <?php } ?>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search files, patients, descriptions..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
             
            </div>
            <div 
                class="collapse 
                    <?php 
                    if (
                    isset($_GET['dtf'])
                    || $category_filter
                    || $visibility_filter
                    || $patient_id_filter
                    || ($_GET['canned_date'] ?? '') !== "custom" ) 
                    { 
                        echo "show"; 
                    } 
                    ?>
                "
                id="advancedFilter"
            >
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisyear") { echo "selected"; } ?> value="thisyear">This Year</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastyear") { echo "selected"; } ?> value="lastyear">Last Year</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>File Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php
                                $sql_categories = mysqli_query($mysqli, "SELECT DISTINCT file_category FROM patient_files WHERE file_archived_at IS NULL ORDER BY file_category ASC");
                                while ($row = mysqli_fetch_array($sql_categories)) {
                                    $file_category = nullable_htmlentities($row['file_category']);
                                ?>
                                    <option <?php if ($file_category == $category_filter) { echo "selected"; } ?>><?php echo $file_category; ?></option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>File Visibility</label>
                            <select class="form-control select2" name="visibility" onchange="this.form.submit()">
                                <option value="">- All Visibility -</option>
                                <option value="Private" <?php if ($visibility_filter == "Private") { echo "selected"; } ?>>Private</option>
                                <option value="Shared" <?php if ($visibility_filter == "Shared") { echo "selected"; } ?>>Shared</option>
                                <option value="Restricted" <?php if ($visibility_filter == "Restricted") { echo "selected"; } ?>>Restricted</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Patient</label>
                            <select class="form-control select2" name="patient" onchange="this.form.submit()">
                                <option value="">- All Patients -</option>
                                <?php
                                $sql_patients = mysqli_query($mysqli, "
                                    SELECT 
                                        patient_id, 
                                        first_name, 
                                        middle_name,
                                        last_name, 
                                        patient_mrn 
                                    FROM patients 
                                    WHERE patient_status != 'ARCHIVED' 
                                    ORDER BY first_name, last_name ASC
                                ");
                                while ($row = mysqli_fetch_array($sql_patients)) {
                                    $patient_id = intval($row['patient_id']);
                                    $patient_first_name = nullable_htmlentities($row['first_name']);
                                    $patient_middle_name = nullable_htmlentities($row['middle_name']);
                                    $patient_last_name = nullable_htmlentities($row['last_name']);
                                    $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                                    
                                    // Build full name with optional middle name
                                    $patient_full_name = $patient_first_name;
                                    if (!empty($patient_middle_name)) {
                                        $patient_full_name .= ' ' . $patient_middle_name;
                                    }
                                    $patient_full_name .= ' ' . $patient_last_name;
                                ?>
                                    <option value="<?php echo $patient_id; ?>" <?php if ($patient_id == $patient_id_filter) { echo "selected"; } ?>>
                                        <?php echo "$patient_full_name (MRN: $patient_mrn)"; ?>
                                    </option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Statistics Cards -->
    <div class="card-body border-bottom">
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo number_format($stats['total_files']); ?></h3>
                        <p>Total Files</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-file"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo number_format($stats['total_patients']); ?></h3>
                        <p>Patients with Files</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo number_format($stats['total_categories']); ?></h3>
                        <p>File Categories</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-folder"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-secondary">
                    <div class="inner">
                        <h3><?php echo formatBytes($stats['total_size']); ?></h3>
                        <p>Total Storage Used</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-database"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive-sm">
        <table class="table table-hover mb-0 text-nowrap">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=file_uploaded_at&order=<?php echo $disp; ?>">
                        Upload Date <?php if ($sort == 'file_uploaded_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=file_original_name&order=<?php echo $disp; ?>">
                        File Name <?php if ($sort == 'file_original_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=file_category&order=<?php echo $disp; ?>">
                        Category <?php if ($sort == 'file_category') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Size</th>
                <th>Visibility</th>
                <th>Uploaded By</th>
                <?php if (SimplePermission::any("patient_files_view")) { ?>
                <th class="text-center">Action</th>
                <?php } ?>
            </tr>
            </thead>
            <tbody>
            <?php

            while ($row = mysqli_fetch_array($sql)) {
                $file_id = intval($row['file_id']);
                $file_original_name = nullable_htmlentities($row['file_original_name']);
                $file_saved_name = nullable_htmlentities($row['file_saved_name']);
                $file_category = nullable_htmlentities($row['file_category']);
                $file_description = nullable_htmlentities($row['file_description']);
                $file_visibility = nullable_htmlentities($row['file_visibility']);
                $file_size = intval($row['file_size']);
                $file_type = nullable_htmlentities($row['file_type']);
                $file_uploaded_at = nullable_htmlentities($row['file_uploaded_at']);
                $file_uploaded_by = intval($row['file_uploaded_by']);
                
                $patient_id = intval($row['patient_id']);
                $patient_first_name = nullable_htmlentities($row['first_name']);
                $patient_middle_name = nullable_htmlentities($row['middle_name']);
                $patient_last_name = nullable_htmlentities($row['last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_dob = nullable_htmlentities($row['date_of_birth']);
                $patient_gender = nullable_htmlentities($row['sex']);
                
                $uploaded_by_name = nullable_htmlentities($row['uploaded_by_name']);
                $visit_id = intval($row['visit_id']);
                $visit_type = nullable_htmlentities($row['visit_type']);
                $visit_date = nullable_htmlentities($row['visit_datetime']);

                // Build patient full name
                $patient_full_name = $patient_first_name;
                if (!empty($patient_middle_name)) {
                    $patient_full_name .= ' ' . $patient_middle_name;
                }
                $patient_full_name .= ' ' . $patient_last_name;

                // Visibility badge styling
                $visibility_badge = "";
                switch($file_visibility) {
                    case 'Private':
                        $visibility_badge = "badge-secondary";
                        break;
                    case 'Shared':
                        $visibility_badge = "badge-success";
                        break;
                    case 'Restricted':
                        $visibility_badge = "badge-warning";
                        break;
                    default:
                        $visibility_badge = "badge-light";
                }

                // Format description for display
                $file_description_display = $file_description;
                if (strlen($file_description) > 100) {
                    $file_description_display = substr($file_description, 0, 100) . '...';
                }
                if (empty($file_description_display)) {
                    $file_description_display = "<span class='text-muted'>No description</span>";
                }

                // Calculate patient age from DOB
                $patient_age = "";
                if (!empty($patient_dob)) {
                    $birthDate = new DateTime($patient_dob);
                    $today = new DateTime();
                    $age = $today->diff($birthDate)->y;
                    $patient_age = " ($age yrs)";
                }

                // Get file icon
                $file_icon = getFileIcon($file_type);

                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($file_uploaded_at)); ?></div>
                        <small class="text-muted"><?php echo date('g:i A', strtotime($file_uploaded_at)); ?></small>
                    </td>
                    <td>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-<?php echo $file_icon; ?> text-muted mr-2"></i>
                            <div>
                                <div class="font-weight-bold"><?php echo $file_original_name; ?></div>
                                <small class="text-muted"><?php echo strtoupper($file_type); ?> • <?php echo formatBytes($file_size); ?></small>
                                <?php if (!empty($file_description)) { ?>
                                    <div class="mt-1">
                                        <small data-toggle="tooltip" title="<?php echo htmlspecialchars($file_description); ?>">
                                            <?php echo $file_description_display; ?>
                                        </small>
                                    </div>
                                <?php } ?>
                                <?php if ($visit_id) { ?>
                                    <div class="mt-1">
                                        <small class="badge badge-info">
                                            <i class="fas fa-calendar-check mr-1"></i>
                                            Visit #<?php echo $visit_id; ?> (<?php echo $visit_type; ?>)
                                        </small>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $patient_full_name . $patient_age; ?></div>
                        <small class="text-muted">MRN: <?php echo $patient_mrn; ?></small>
                        <?php if (!empty($patient_gender)) { ?>
                            <div class="mt-1">
                                <small><i class="fas fa-user mr-1"></i><?php echo $patient_gender; ?></small>
                            </div>
                        <?php } ?>
                    </td>
                    <td>
                        <span class="badge badge-secondary"><?php echo $file_category; ?></span>
                    </td>
                    <td>
                        <span class="text-muted"><?php echo formatBytes($file_size); ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $visibility_badge; ?>"><?php echo $file_visibility; ?></span>
                    </td>
                    <td>
                        <div class="small">
                            <div><?php echo $uploaded_by_name; ?></div>
                        </div>
                    </td>

                    <!-- Actions -->
                    <?php if (SimplePermission::any("patient_files_view")) { ?>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <?php if (SimplePermission::any("patient_files_download")) { ?>
                                    <a class="dropdown-item" href="/clinic/patient/download_file.php?file_id=<?php echo $file_id; ?>">
                                        <i class="fas fa-fw fa-download mr-2"></i>Download
                                    </a>
                                    <?php } ?>
                                    <?php if (SimplePermission::any("patient_files_view")) { ?>
                                    <a class="dropdown-item" href="javascript:void(0)" onclick="viewFileDetails(<?php echo $file_id; ?>)">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <?php } ?>
                                    <?php if (SimplePermission::any("patient_files_delete")) { ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger" href="javascript:void(0)" onclick="confirmArchive(<?php echo $file_id; ?>, '<?php echo htmlspecialchars($file_original_name); ?>')">
                                        <i class="fas fa-fw fa-archive mr-2"></i>Archive File
                                    </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </td>
                    <?php } ?>
                </tr>

            </tbody>
             <?php } ?>
        </table>
    </div>
    
    <!-- Bulk Actions Form -->
    <form method="post" id="bulkForm" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="bulk_action" id="bulk_action" value="">
    </form>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
     ?>
    
</div> <!-- End Card -->

<!-- File Details Modal -->
<div class="modal fade" id="fileDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-medical mr-2"></i>File Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="fileDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Archive Confirmation Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-archive mr-2"></i>Archive File</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to archive <strong id="fileNameToArchive"></strong>?</p>
                <p class="text-muted small">Archived files can be restored later if needed.</p>
            </div>
            <div class="modal-footer">
                <form method="post" id="archiveForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="file_id" id="fileIdToArchive">
                    <input type="hidden" name="archive_file" value="1">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Archive File</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();
});

// File details modal
function viewFileDetails(fileId) {
    // Show loading state
    $('#fileDetailsContent').html(`
        <div class="text-center py-4">
            <i class="fas fa-spinner fa-spin fa-2x text-primary mb-3"></i>
            <p>Loading file details...</p>
        </div>
    `);
    $('#fileDetailsModal').modal('show');
    
    console.log('Loading file details for ID:', fileId);
    
    $.ajax({
        url: '/clinic/patient/ajax/get_file_details.php',
        type: 'GET',
        data: { file_id: fileId },
        dataType: 'json',
        success: function(response) {
            console.log('AJAX Response:', response);
            
            if (response.success) {
                $('#fileDetailsContent').html(response.html);
            } else {
                let errorMsg = response.error || 'Error loading file details.';
                if (response.debug) {
                    errorMsg += '<br><small class="text-muted">Debug: ' + JSON.stringify(response.debug) + '</small>';
                }
                $('#fileDetailsContent').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        ${errorMsg}
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            console.error('XHR Response:', xhr.responseText);
            
            $('#fileDetailsContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Network error: ${error}<br>
                    <small class="text-muted">Status: ${status}</small>
                </div>
            `);
        }
    });
}
// Archive confirmation
function confirmArchive(fileId, fileName) {
    $('#fileIdToArchive').val(fileId);
    $('#fileNameToArchive').text(fileName);
    $('#archiveModal').modal('show');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + F to focus on search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'patient_files.php';
    }
});
</script>

<?php
// Helper functions
function getFileIcon($fileType) {
    $icons = [
        'pdf' => 'file-pdf',
        'jpg' => 'file-image',
        'jpeg' => 'file-image',
        'png' => 'file-image',
        'gif' => 'file-image',
        'doc' => 'file-word',
        'docx' => 'file-word',
        'xls' => 'file-excel',
        'xlsx' => 'file-excel',
        'txt' => 'file-alt'
    ];
    return $icons[strtolower($fileType)] ?? 'file';
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
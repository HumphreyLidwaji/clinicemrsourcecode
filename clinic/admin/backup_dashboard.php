<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Define APP_VERSION if not already defined
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Handle backup download
if (isset($_GET['download_backup'])) {
    validateCSRFToken($_GET['csrf_token']);
    
    $timestamp = date('YmdHis');
    $backup_file = "clinicemr_backup_$timestamp.zip";
    
    // Create temporary backup file with proper extension
    $temp_file = tempnam(sys_get_temp_dir(), 'clinicemr_backup_') . '.zip';
    
    $zip = new ZipArchive();
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        
        // 1. Backup database
        $sql_content = "-- ClinicEMR Database Backup - " . date('Y-m-d H:i:s') . "\n\n";
        $sql_content .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        // Get all tables (excluding problematic views)
        $tables = [];
        $result = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        foreach ($tables as $table) {
            try {
                // Table structure
                $create_result = $mysqli->query("SHOW CREATE TABLE `$table`");
                if ($create_result && $create_row = $create_result->fetch_assoc()) {
                    if (isset($create_row['Create Table'])) {
                        $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
                        $sql_content .= $create_row['Create Table'] . ";\n\n";
                        
                        // Table data
                        $data_result = $mysqli->query("SELECT * FROM `$table`");
                        if ($data_result && $data_result->num_rows > 0) {
                            $sql_content .= "-- Data for table `$table`\n";
                            while ($row = $data_result->fetch_assoc()) {
                                $columns = array_map(fn($col) => "`$col`", array_keys($row));
                                $values = array_map(function($val) use ($mysqli) {
                                    return $val === null ? "NULL" : "'" . $mysqli->real_escape_string($val) . "'";
                                }, array_values($row));
                                $sql_content .= "INSERT INTO `$table` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ");\n";
                            }
                            $sql_content .= "\n";
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Backup error for table $table: " . $e->getMessage());
                $sql_content .= "-- Error backing up table $table: " . $e->getMessage() . "\n\n";
            }
        }
        
        // Backup views separately (if they exist and are valid)
        $views_sql = "SHOW FULL TABLES WHERE Table_type = 'VIEW'";
        $views_result = $mysqli->query($views_sql);
        if ($views_result) {
            $views = [];
            while ($row = $views_result->fetch_row()) {
                $views[] = $row[0];
            }
            
            foreach ($views as $view) {
                try {
                    $create_result = $mysqli->query("SHOW CREATE VIEW `$view`");
                    if ($create_result && $create_row = $create_result->fetch_assoc()) {
                        if (isset($create_row['Create View'])) {
                            $sql_content .= "-- View: $view\n";
                            $sql_content .= "DROP VIEW IF EXISTS `$view`;\n";
                            $sql_content .= $create_row['Create View'] . ";\n\n";
                        }
                    }
                } catch (Exception $e) {
                    error_log("Backup error for view $view: " . $e->getMessage());
                    $sql_content .= "-- Error backing up view $view: " . $e->getMessage() . "\n\n";
                }
            }
        }
        
        $sql_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $zip->addFromString("database.sql", $sql_content);
        
        // 2. Backup uploads folder (if it exists)
        $uploads_path = "../uploads";
        if (is_dir($uploads_path)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads_path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    try {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($uploads_path) + 1);
                        $zip->addFile($filePath, "uploads/" . $relativePath);
                    } catch (Exception $e) {
                        error_log("Backup error for file $filePath: " . $e->getMessage());
                    }
                }
            }
        }
        
        // 3. Add version info
        $version_info = "ClinicEMR Backup\n";
        $version_info .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $version_info .= "Generated By: $session_name\n";
        $version_info .= "Database: " . $mysqli->host_info . "\n";
        $version_info .= "Version: " . APP_VERSION . "\n";
        
        $zip->addFromString("backup_info.txt", $version_info);
        
        $zip->close();
        
        // Serve the file
        if (file_exists($temp_file)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $backup_file . '"');
            header('Content-Length: ' . filesize($temp_file));
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            
            readfile($temp_file);
            unlink($temp_file);
            
            logAction("Backup", "Download", "$session_name downloaded system backup");
            exit;
        } else {
            throw new Exception("Backup file was not created");
        }
        
    } else {
        throw new Exception("Failed to create backup file");
    }
}

// Handle file upload for restore
if (isset($_POST['restore_backup'])) {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: backup_restore.php");
        exit;
    }
    
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $backup_file = $_FILES['backup_file']['tmp_name'];
        
        // Verify it's a ZIP file
        $file_type = mime_content_type($backup_file);
        if ($file_type !== 'application/zip') {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Invalid file type. Please upload a ZIP backup file.";
            header("Location: backup_restore.php");
            exit;
        }
        
        // Extract to temporary directory
        $extract_path = tempnam(sys_get_temp_dir(), 'clinicemr_restore_');
        unlink($extract_path);
        mkdir($extract_path);
        
        $zip = new ZipArchive();
        if ($zip->open($backup_file) === TRUE) {
            $zip->extractTo($extract_path);
            $zip->close();
            
            // Check if database.sql exists
            $sql_file = $extract_path . '/database.sql';
            if (file_exists($sql_file)) {
                // Read and execute SQL file with error handling
                $sql_content = file_get_contents($sql_file);
                $queries = array_filter(array_map('trim', explode(';', $sql_content)));
                
                $success_count = 0;
                $error_count = 0;
                $errors = [];
                
                foreach ($queries as $query) {
                    if (!empty($query) && !str_starts_with(trim($query), '--')) {
                        try {
                            if ($mysqli->query($query)) {
                                $success_count++;
                            } else {
                                $error_count++;
                                $errors[] = "Query failed: " . $mysqli->error;
                                error_log("Restore SQL Error: " . $mysqli->error . " - Query: " . substr($query, 0, 100));
                            }
                        } catch (Exception $e) {
                            $error_count++;
                            $errors[] = "Query error: " . $e->getMessage();
                            error_log("Restore SQL Exception: " . $e->getMessage());
                        }
                    }
                }
                
                // Restore uploads if they exist
                $uploads_backup = $extract_path . '/uploads';
                if (is_dir($uploads_backup)) {
                    // Clear existing uploads
                    $existing_uploads = "../uploads";
                    if (is_dir($existing_uploads)) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($existing_uploads, FilesystemIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::CHILD_FIRST
                        );
                        
                        foreach ($files as $file) {
                            try {
                                if ($file->isDir()) {
                                    rmdir($file->getRealPath());
                                } else {
                                    unlink($file->getRealPath());
                                }
                            } catch (Exception $e) {
                                error_log("Cleanup error: " . $e->getMessage());
                            }
                        }
                    }
                    
                    // Copy backup uploads
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($uploads_backup, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    
                    foreach ($files as $file) {
                        try {
                            $target_path = $existing_uploads . DIRECTORY_SEPARATOR . $files->getSubPathName();
                            if ($file->isDir()) {
                                if (!is_dir($target_path)) {
                                    mkdir($target_path, 0755, true);
                                }
                            } else {
                                copy($file->getRealPath(), $target_path);
                            }
                        } catch (Exception $e) {
                            error_log("File restore error: " . $e->getMessage());
                        }
                    }
                }
                
                // Cleanup
                try {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($extract_path, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    
                    foreach ($files as $file) {
                        if ($file->isDir()) {
                            rmdir($file->getRealPath());
                        } else {
                            unlink($file->getRealPath());
                        }
                    }
                    rmdir($extract_path);
                } catch (Exception $e) {
                    error_log("Cleanup error: " . $e->getMessage());
                }
                
                if ($error_count === 0) {
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Backup restored successfully! Executed $success_count SQL queries.";
                } else {
                    $_SESSION['alert_type'] = "warning";
                    $_SESSION['alert_message'] = "Backup restored with $error_count errors. $success_count queries executed successfully.";
                    // Log detailed errors
                    foreach (array_slice($errors, 0, 5) as $error) {
                        error_log("Restore Error: " . $error);
                    }
                }
                
                logAction("Backup", "Restore", "$session_name restored system backup ($success_count queries executed, $error_count errors)");
                
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Backup file is missing database.sql";
            }
            
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Failed to open backup file.";
        }
        
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a backup file to restore.";
    }
    
    header("Location: backup_restore.php");
    exit;
}

// Get backup statistics
$backup_stats = [];
$backup_stats['database_size'] = 0;
$backup_stats['uploads_size'] = 0;

// Calculate database size
try {
    $size_sql = "SELECT 
        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_type = 'BASE TABLE'";
    $size_result = $mysqli->query($size_sql);
    if ($size_result) {
        $backup_stats['database_size'] = $size_result->fetch_assoc()['size_mb'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Database size calculation error: " . $e->getMessage());
    $backup_stats['database_size'] = 0;
}

// Calculate uploads size
$uploads_path = "../uploads";
if (is_dir($uploads_path)) {
    $backup_stats['uploads_size'] = round(dirSize($uploads_path) / 1024 / 1024, 2);
} else {
    $backup_stats['uploads_size'] = 0;
}

$backup_stats['total_size'] = $backup_stats['database_size'] + $backup_stats['uploads_size'];

// Get recent backup activity
try {
    $logs_sql = "SELECT * FROM logs 
                WHERE log_action LIKE '%Backup%' OR log_action LIKE '%Restore%'
                ORDER BY log_id DESC 
                LIMIT 10";
    $logs_result = $mysqli->query($logs_sql);
    $recent_activity = [];
    while ($log = $logs_result->fetch_assoc()) {
        $recent_activity[] = $log;
    }
} catch (Exception $e) {
    error_log("Log retrieval error: " . $e->getMessage());
    $recent_activity = [];
}

// Helper function to calculate directory size
function dirSize($directory) {
    $size = 0;
    try {
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file){
            $size += $file->getSize();
        }
    } catch (Exception $e) {
        error_log("Directory size calculation error: " . $e->getMessage());
    }
    return $size;
}
?>

<!-- Rest of your HTML remains the same -->
<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-database mr-2"></i>Backup & Restore
            </h3>
            <div class="card-tools">
                <a href="admin.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Admin
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

        <!-- Statistics Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="info-box bg-primary">
                    <span class="info-box-icon"><i class="fas fa-database"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Database Size</span>
                        <span class="info-box-number"><?php echo $backup_stats['database_size']; ?> MB</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-folder"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Uploads Size</span>
                        <span class="info-box-number"><?php echo $backup_stats['uploads_size']; ?> MB</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-info">
                    <span class="info-box-icon"><i class="fas fa-hdd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Size</span>
                        <span class="info-box-number"><?php echo $backup_stats['total_size']; ?> MB</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-secondary">
                    <span class="info-box-icon"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last Activity</span>
                        <span class="info-box-number">
                            <?php echo !empty($recent_activity) ? date('M j, Y', strtotime($recent_activity[0]['log_created_at'])) : 'Never'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Backup Section -->
            <div class="col-md-6">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-download mr-2"></i>Backup System</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            This will create a complete backup including:
                            <ul class="mb-0 mt-2">
                                <li>All database tables and data</li>
                                <li>Uploaded files and documents</li>
                                <li>System configuration</li>
                            </ul>
                        </div>
                        
                        <div class="text-center p-4">
                            <i class="fas fa-database fa-4x text-primary mb-3"></i>
                            <h5>Download Complete Backup</h5>
                            <p class="text-muted">Estimated size: <?php echo $backup_stats['total_size']; ?> MB</p>
                            <a class="btn btn-primary btn-lg" href="?download_backup&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                <i class="fas fa-download mr-2"></i>Download Backup
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Restore Section -->
            <div class="col-md-6">
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-upload mr-2"></i>Restore System</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Warning:</strong> This will overwrite all current data with the backup contents.
                            This action cannot be undone.
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="restoreForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="form-group">
                                <label for="backup_file">Select Backup File</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="backup_file" name="backup_file" accept=".zip" required>
                                    <label class="custom-file-label" for="backup_file">Choose backup file (.zip)</label>
                                </div>
                                <small class="form-text text-muted">Select a previously created ITFlow backup file</small>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="confirm_restore" required>
                                    <label class="form-check-label text-danger" for="confirm_restore">
                                        I understand this will overwrite all current data
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning btn-block" name="restore_backup" id="restoreBtn">
                                <i class="fas fa-upload mr-2"></i>Restore from Backup
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card card-secondary">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Recent Activity</h3>
                        <span class="badge badge-primary"><?php echo count($recent_activity); ?> events</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Action</th>
                                        <th>User</th>
                                        <th>Date</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_activity)): ?>
                                        <?php foreach ($recent_activity as $activity): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo strpos($activity['log_action'], 'Download') !== false ? 'primary' : 'warning';
                                                    ?>">
                                                        <?php echo htmlspecialchars($activity['log_action']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($activity['log_user_name'] ?? 'N/A'); ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo date('M j, H:i', strtotime($activity['log_created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo htmlspecialchars($activity['log_description'] ?? ''); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">
                                                <i class="fas fa-history fa-2x mb-2"></i>
                                                <p>No backup activity yet</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // File input label update
    $('#backup_file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').text(fileName || 'Choose backup file (.zip)');
    });

    // Restore form confirmation
    $('#restoreForm').on('submit', function(e) {
        if (!confirm('WARNING: This will overwrite ALL current data with the backup. This cannot be undone. Continue?')) {
            e.preventDefault();
            return false;
        }
        
        // Show loading state
        $('#restoreBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Restoring...').prop('disabled', true);
    });

    // Backup download confirmation
    $('a[href*="download_backup"]').click(function(e) {
        if (!confirm('This will download a complete system backup. The file may be large. Continue?')) {
            e.preventDefault();
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + B for backup
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = '?download_backup&csrf_token=<?php echo $_SESSION['csrf_token']; ?>';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Define APP_VERSION if not already defined
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/database_version.php';


$updates = fetchUpdates();

$latest_version = $updates->latest_version;
$current_version = $updates->current_version;
$result = $updates->result;

$git_log = shell_exec("git log $repo_branch..origin/$repo_branch --pretty=format:'<tr><td>%h</td><td>%ar</td><td>%s</td></tr>'");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (isset($_POST['create_backup'])) {
        validateCSRFToken($csrf_token);
        
        // Create backup
        $backup_result = createProjectBackup();
        
        if ($backup_result['success']) {
            flash_alert("Backup created successfully: " . $backup_result['filename']);
        } else {
            flash_alert("Backup failed: " . $backup_result['error'], 'error');
        }
        
        header("Location: update.php");
        exit;
        
    } elseif (isset($_POST['update_db'])) {
        validateCSRFToken($csrf_token);
        
        // Create backup before update
        $backup_result = createProjectBackup();
        
        if ($backup_result['success']) {
            // Proceed with database update
            header("Location: post.php?update_db");
            exit;
        } else {
            flash_alert("Update aborted: Backup failed - " . $backup_result['error'], 'error');
            header("Location: update.php");
            exit;
        }
        
    } elseif (isset($_POST['update_app'])) {
        validateCSRFToken($csrf_token);
        
        // Create backup before update
        $backup_result = createProjectBackup();
        
        if ($backup_result['success']) {
            // Proceed with app update
            header("Location: post.php?update");
            exit;
        } else {
            flash_alert("Update aborted: Backup failed - " . $backup_result['error'], 'error');
            header("Location: update.php");
            exit;
        }
        
    } elseif (isset($_POST['force_update_app'])) {
        validateCSRFToken($csrf_token);
        
        // Create backup before force update
        $backup_result = createProjectBackup();
        
        if ($backup_result['success']) {
            // Proceed with force update
            header("Location: post.php?update&force_update=1");
            exit;
        } else {
            flash_alert("Update aborted: Backup failed - " . $backup_result['error'], 'error');
            header("Location: update.php");
            exit;
        }
    }
}

// Function to create project backup
function createProjectBackup() {
    global $mysqli;
    
    $backup_dir = "../backups/";
    $timestamp = date('Y-m-d_H-i-s');
    $backup_filename = "itflow_backup_{$timestamp}.sql";
    $backup_path = $backup_dir . $backup_filename;
    
    // Create backups directory if it doesn't exist
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // Get database configuration
    $db_host = DB_HOST;
    $db_name = DB_NAME;
    $db_user = DB_USER;
    $db_pass = DB_PASS;
    
    // Create backup command
    $command = "mysqldump --host={$db_host} --user={$db_user} --password={$db_pass} {$db_name} > {$backup_path} 2>&1";
    
    // Execute backup
    exec($command, $output, $return_var);
    
    if ($return_var === 0 && file_exists($backup_path) && filesize($backup_path) > 0) {
        // Log backup action
        logAction("Backup", "Create", "Automatic backup created before update: {$backup_filename}");
        
        return [
            'success' => true,
            'filename' => $backup_filename,
            'path' => $backup_path,
            'size' => filesize($backup_path)
        ];
    } else {
        // Try alternative method using PHP
        $alternative_backup = createAlternativeBackup($backup_path);
        
        if ($alternative_backup['success']) {
            return $alternative_backup;
        }
        
        return [
            'success' => false,
            'error' => "MySQL dump failed. Return code: {$return_var}. Output: " . implode("\n", $output)
        ];
    }
}

// Alternative backup method using PHP
function createAlternativeBackup($backup_path) {
    global $mysqli;
    
    try {
        // Get all tables
        $tables_result = mysqli_query($mysqli, "SHOW TABLES");
        $tables = [];
        
        while ($row = mysqli_fetch_row($tables_result)) {
            $tables[] = $row[0];
        }
        
        $backup_content = "-- ITFlow Backup\n";
        $backup_content .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $backup_content .= "-- Database: " . DB_NAME . "\n\n";
        
        foreach ($tables as $table) {
            // Add table structure
            $backup_content .= "--\n-- Table structure for table `{$table}`\n--\n\n";
            $backup_content .= "DROP TABLE IF EXISTS `{$table}`;\n";
            
            $create_table = mysqli_fetch_row(mysqli_query($mysqli, "SHOW CREATE TABLE `{$table}`"));
            $backup_content .= $create_table[1] . ";\n\n";
            
            // Add table data
            $backup_content .= "--\n-- Dumping data for table `{$table}`\n--\n\n";
            
            $data_result = mysqli_query($mysqli, "SELECT * FROM `{$table}`");
            $num_fields = mysqli_num_fields($data_result);
            
            while ($row = mysqli_fetch_row($data_result)) {
                $backup_content .= "INSERT INTO `{$table}` VALUES(";
                
                for ($j = 0; $j < $num_fields; $j++) {
                    $row[$j] = addslashes($row[$j] ?? '');
                    $row[$j] = str_replace("\n", "\\n", $row[$j]);
                    
                    if (isset($row[$j])) {
                        $backup_content .= "'" . $row[$j] . "'";
                    } else {
                        $backup_content .= "''";
                    }
                    
                    if ($j < ($num_fields - 1)) {
                        $backup_content .= ',';
                    }
                }
                
                $backup_content .= ");\n";
            }
            
            $backup_content .= "\n";
        }
        
        // Write backup file
        if (file_put_contents($backup_path, $backup_content) !== false) {
            logAction("Backup", "Create", "Alternative backup created: " . basename($backup_path));
            
            return [
                'success' => true,
                'filename' => basename($backup_path),
                'path' => $backup_path,
                'size' => filesize($backup_path),
                'method' => 'php'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Could not write backup file'
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Check recent backups
$backup_dir = "../backups/";
$recent_backups = [];
if (is_dir($backup_dir)) {
    $backup_files = glob($backup_dir . "clinicemr_backup_*.sql");
    usort($backup_files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $recent_backups = array_slice($backup_files, 0, 5); // Last 5 backups
}
?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-download mr-2"></i>System Update</h3>
    </div>
    <div class="card-body" style="text-align: center;">

        <!-- Check if git fetch result was successful (0), if not show a warning -->
        <?php if ($result !== 0) { ?>
            <div class="alert alert-danger">
                <strong>WARNING: Could not execute 'git fetch'.</strong>
                <br><br>
                <i>Error details:- <?php echo shell_exec("git fetch 2>&1"); ?></i>
                <br>
                <br>Things to check: Is Git installed? Is the Git origin/remote correct? Are web server file permissions too strict?
                <br>Seek support on the <a href="https://forum.clinicemr.org">Forum</a> if required - include relevant PHP error logs & ITFlow debug output
            </div>
        <?php } ?>

        <!-- Backup Section -->
        <div class="card card-info mb-4">
            <div class="card-header">
                <h4 class="card-title"><i class="fas fa-fw fa-database mr-2"></i>Automatic Backup</h4>
            </div>
            <div class="card-body">
                <p class="text-muted">A backup will be automatically created before any update. You can also create a manual backup below.</p>
                
                <form action="" method="post" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="create_backup" class="btn btn-info">
                        <i class="fas fa-fw fa-save mr-2"></i>Create Manual Backup
                    </button>
                </form>

                <?php if (!empty($recent_backups)) { ?>
                    <div class="mt-3">
                        <h6>Recent Backups:</h6>
                        <div class="list-group">
                            <?php foreach ($recent_backups as $backup_file) { ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <small><?php echo basename($backup_file); ?></small>
                                    <small class="text-muted"><?php echo date('Y-m-d H:i:s', filemtime($backup_file)); ?></small>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php if (LATEST_DATABASE_VERSION > CURRENT_DATABASE_VERSION) { ?>
            <div class="alert alert-danger">
                <h1 class="font-weight-bold text-center">⚠️ DATABASE UPDATE REQUIRED ⚠️</h1>
                <h2 class="font-weight-bold text-center">Database schema update available</h2>
                <p class="text-center">A backup will be automatically created before proceeding with the update.</p>
            </div>
            
            <form action="" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                <button type="submit" name="update_db" class="btn btn-dark btn-lg my-4 confirm-link" 
                        onclick="return confirm('WARNING: This will update your database schema. A backup will be created first. Continue?')">
                    <i class="fas fa-fw fa-4x fa-database mb-1"></i>
                    <h5>Update Database</h5>
                </button>
            </form>
            
            <br>
            <small class="text-secondary">Current DB Version: <?php echo CURRENT_DATABASE_VERSION; ?></small>
            <br>
            <small class="text-secondary">Latest DB Version: <?php echo LATEST_DATABASE_VERSION; ?></small>
            <br>
            <hr>

        <?php } else {
            if (!empty($git_log)) { ?>
                <div class="alert alert-warning">
                    <h1 class="font-weight-bold text-center">⚠️ APPLICATION UPDATE AVAILABLE ⚠️</h1>
                    <h2 class="font-weight-bold text-center">New code changes are available</h2>
                    <p class="text-center">A backup will be automatically created before proceeding with the update.</p>
                </div>

                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <form action="" method="post" class="mb-3">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                            <button type="submit" name="update_app" class="btn btn-primary btn-lg btn-block confirm-link" 
                                    onclick="return confirm('WARNING: This will update the application code. A backup will be created first. Continue?')">
                                <i class="fas fa-fw fa-3x fa-download mb-2"></i>
                                <h5>Update Application</h5>
                            </button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form action="" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                            <button type="submit" name="force_update_app" class="btn btn-danger btn-lg btn-block confirm-link" 
                                    onclick="return confirm('DANGER: This will FORCE update the application, overwriting local changes. A backup will be created first. Continue?')">
                                <i class="fas fa-fw fa-3x fa-hammer mb-2"></i>
                                <h5>FORCE Update</h5>
                            </button>
                        </form>
                    </div>
                </div>

            <?php } else { ?>
                <div class="alert alert-success">
                    <h4 class="font-weight-bold text-center">✅ SYSTEM IS UP TO DATE</h4>
                </div>
                
                <p><strong>Application Release Version:<br><strong class="text-dark"><?php echo APP_VERSION; ?></strong></p>
                <p class="text-secondary">Database Version:<br><strong class="text-dark"><?php echo CURRENT_DATABASE_VERSION; ?></strong></p>
                <p class="text-secondary">Code Commit:<br><strong class="text-dark"><?php echo $current_version; ?></strong></p>
                <p class="text-muted">You are up to date!<br>Everything is going to be alright</p>
                <i class="far fa-3x text-dark fa-smile-wink"></i><br>

                <?php if (rand(1,10) == 1) { ?>
                    <br>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        You're up to date, but when was the last time you checked your ITFlow backup works?
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php } ?>

            <?php }
        }

        if (!empty($git_log)) { ?>
            <div class="mt-4">
                <h5>Pending Changes:</h5>
                <table class="table table-sm table-striped">
                    <thead class="thead-dark">
                    <tr>
                        <th>Commit</th>
                        <th>When</th>
                        <th>Description</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php echo $git_log; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
        ?>

    </div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

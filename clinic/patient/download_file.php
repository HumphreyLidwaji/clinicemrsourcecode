<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

class FileDownloader {
    private $mysqli;
    private $file_id;
    private $session_user_id;
    
    public function __construct($mysqli, $file_id, $session_user_id) {
        $this->mysqli = $mysqli;
        $this->file_id = $file_id;
        $this->session_user_id = $session_user_id;
    }
    
    public function download() {
        try {
            // Validate permissions
            $this->validatePermissions();
            
            // Get file data
            $file = $this->getFileData();
            
            // Validate file exists
            $this->validateFileExists($file);
            
            // Log download activity
            $this->logDownloadActivity($file);
            
            // Update download statistics
            $this->updateDownloadStats($file);
            
            // Serve the file
            $this->serveFile($file);
            
        } catch (Exception $e) {
            $this->handleError($e->getMessage());
        }
    }
    
    private function validatePermissions() {
        if (!SimplePermission::any(['patient_files_download', '*'])) {
            throw new Exception('You do not have permission to download files.');
        }
        
        if ($this->file_id <= 0) {
            throw new Exception('Invalid file ID.');
        }
    }
    
    private function getFileData() {
        $sql = "
            SELECT 
                pf.*,
                p.patient_first_name,
                p.patient_last_name,
                p.patient_mrn,
                p.patient_id,
                u.user_name as uploaded_by_name
            FROM patient_files pf
            LEFT JOIN patients p ON pf.file_patient_id = p.patient_id
            LEFT JOIN users u ON pf.file_uploaded_by = u.user_id
            WHERE pf.file_id = ?
            AND pf.file_archived_at IS NULL
        ";
        
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database error: Failed to prepare statement.');
        }
        
        $stmt->bind_param("i", $this->file_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Database error: Failed to execute query.');
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('File not found or has been archived.');
        }
        
        return $result->fetch_assoc();
    }
    
    private function validateFileExists($file) {
        if (!file_exists($file['file_path'])) {
            throw new Exception('File not found on server: ' . $file['file_original_name']);
        }
        
        if (!is_readable($file['file_path'])) {
            throw new Exception('File is not accessible: ' . $file['file_original_name']);
        }
        
        // Additional security check - verify file size matches database
        $actual_size = filesize($file['file_path']);
        if ($actual_size != $file['file_size']) {
            throw new Exception('File integrity check failed.');
        }
    }
    
    private function logDownloadActivity($file) {
        $activity_query = $this->mysqli->prepare("
            INSERT INTO patient_activities 
            (patient_id, activity_type, activity_description, activity_created_by, activity_datetime) 
            VALUES (?, 'File Download', ?, ?, NOW())
        ");
        $description = "Downloaded file: " . $file['file_original_name'] . " (" . $this->formatBytes($file['file_size']) . ")";
        $activity_query->bind_param('isi', $file['patient_id'], $description, $this->session_user_id);
        $activity_query->execute();
    }
    
    private function updateDownloadStats($file) {
        // Update download count
        $update_sql = "UPDATE patient_files SET file_download_count = COALESCE(file_download_count, 0) + 1 WHERE file_id = ?";
        $update_stmt = $this->mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $this->file_id);
        $update_stmt->execute();
        
        // Log detailed download history (optional)
        $history_sql = "
            INSERT INTO file_download_history 
            (file_id, downloaded_by, downloaded_at, user_ip, user_agent) 
            VALUES (?, ?, NOW(), ?, ?)
        ";
        $history_stmt = $this->mysqli->prepare($history_sql);
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $history_stmt->bind_param("iiss", $this->file_id, $this->session_user_id, $user_ip, $user_agent);
        $history_stmt->execute();
    }
    
    private function serveFile($file) {
        // Get MIME type
        $mime_type = $this->getMimeType($file['file_type']);
        
        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $this->sanitizeFilename($file['file_original_name']) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . $file['file_size']);
        
        // Additional security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        
        // Clear any output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Read the file and output it
        readfile($file['file_path']);
        exit;
    }
    
    private function getMimeType($file_type) {
        $mime_types = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain'
        ];
        
        return $mime_types[strtolower($file_type)] ?? 'application/octet-stream';
    }
    
    private function sanitizeFilename($filename) {
        // Remove any path information
        $filename = basename($filename);
        
        // Replace spaces with underscores
        $filename = str_replace(' ', '_', $filename);
        
        // Remove any non-alphanumeric characters except dots, hyphens, and underscores
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        
        return $filename;
    }
    
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    private function handleError($error_message) {
        error_log("File download error: " . $error_message . " | File ID: " . $this->file_id . " | User: " . $this->session_user_id);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Download failed: " . $error_message;
        header("Location: /clinic/patient/patient_files.php");
        exit;
    }
}

// Main execution
$file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;

$downloader = new FileDownloader($mysqli, $file_id, $session_user_id);
$downloader->download();
?>

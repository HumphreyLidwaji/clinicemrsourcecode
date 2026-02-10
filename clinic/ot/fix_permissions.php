<?php
// fix_permissions.php
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/surgical_documents/';

if (!file_exists($uploadDir)) {
    if (mkdir($uploadDir, 0777, true)) {
        echo "Directory created: $uploadDir<br>";
    } else {
        echo "Failed to create directory<br>";
    }
}

if (is_dir($uploadDir)) {
    echo "Directory exists<br>";
    echo "Permissions: " . decoct(fileperms($uploadDir) & 0777) . "<br>";
    
    // Try to set permissions
    if (chmod($uploadDir, 0755)) {
        echo "Permissions set to 0755<br>";
    } else {
        echo "Failed to set permissions<br>";
    }
    
    // Test write
    $testFile = $uploadDir . 'test.txt';
    if (file_put_contents($testFile, 'test')) {
        echo "Write test successful<br>";
        unlink($testFile);
    } else {
        echo "Write test failed<br>";
    }
}
?>
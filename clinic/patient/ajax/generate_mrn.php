<?php
// ajax/generate_mrn.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$facilityCode = "HOSP"; // Get from settings/session

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php'; // Where generateMRN function is
    $mrn = generateMRN($mysqli, $facilityCode);
    echo json_encode(['success' => true, 'mrn' => $mrn]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
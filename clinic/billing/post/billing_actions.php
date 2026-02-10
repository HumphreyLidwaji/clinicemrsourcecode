<?php
// post/billing_actions.php - MAIN ROUTER
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/configz.php';

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF token validation failed");
}

$action = $_POST['action'] ?? '';

// Define which handler to use based on action
$handlers = [
    // Cash Register Actions
    'open_register' => 'CashRegisterHandler',
    'close_register' => 'CashRegisterHandler',
    'suspend_register' => 'CashRegisterHandler',
    'resume_register' => 'CashRegisterHandler',
    'add_cash_transaction' => 'CashRegisterHandler',
    
    // Payment Processing Actions
    'process_payment' => 'PaymentHandler',
    'process_partial_insurance' => 'PaymentHandler',
];

if (!isset($handlers[$action])) {
    $_SESSION['alert_type'] = 'danger';
    $_SESSION['alert_message'] = 'Invalid action';
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

$handlerClass = $handlers[$action];
$handlerFile = $_SERVER['DOCUMENT_ROOT'] . "/post/billing_handlers/{$handlerClass}.php";

if (!file_exists($handlerFile)) {
    $_SESSION['alert_type'] = 'danger';
    $_SESSION['alert_message'] = "Handler not found: {$handlerClass}";
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

require_once $handlerFile;

// Execute the action
try {
    $handler = new $handlerClass($mysqli, $user_id);
    $handler->execute($action, $_POST);
} catch (Exception $e) {
    $_SESSION['alert_type'] = 'danger';
    $_SESSION['alert_message'] = "Action failed: " . $e->getMessage();
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}
?>
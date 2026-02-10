<?php
require_once '../../includes/inc_all.php';

header('Content-Type: application/json');

$petty_cash_sql = "SELECT a.current_balance, a.account_currency_code 
                   FROM accounts a 
                   WHERE a.account_name LIKE '%petty cash%' 
                   AND a.account_archived_at IS NULL 
                   LIMIT 1";
$result = $mysqli->query($petty_cash_sql);
$account = $result->fetch_assoc();

$settings_sql = "SELECT setting_value FROM system_settings 
                WHERE setting_key = 'petty_cash_replenish_threshold'";
$settings_result = $mysqli->query($settings_sql);
$threshold = $settings_result->fetch_assoc()['setting_value'] ?? 100.00;

echo json_encode([
    'balance' => floatval($account['current_balance']),
    'balance_formatted' => numfmt_format_currency($currency_format, $account['current_balance'], $account['account_currency_code']),
    'threshold' => floatval($threshold)
]);
?>
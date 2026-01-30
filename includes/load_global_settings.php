<?php

// Query Settings
$sql_settings = mysqli_query($mysqli, "SELECT * FROM settings WHERE company_id = 1");
$row = mysqli_fetch_array($sql_settings);

// Database version
DEFINE("CURRENT_DATABASE_VERSION", $row['config_current_database_version']);

// Microsoft OAuth
$config_azure_client_id = $row['config_azure_client_id'];
$config_azure_client_secret = $row['config_azure_client_secret'];

// Mail - SMTP
$config_smtp_provider = $row['config_smtp_provider'];
$config_smtp_host = $row['config_smtp_host'];
$config_smtp_port = intval($row['config_smtp_port']);
$config_smtp_encryption = $row['config_smtp_encryption'];
$config_smtp_username = $row['config_smtp_username'];
$config_smtp_password = $row['config_smtp_password'];
$config_mail_from_email = $row['config_mail_from_email'];
$config_mail_from_name = $row['config_mail_from_name'];

// Mail - IMAP
$config_imap_provider = $row['config_imap_provider'];
$config_imap_host = $row['config_imap_host'];
$config_imap_port = intval($row['config_imap_port']);
$config_imap_encryption = $row['config_imap_encryption'];
$config_imap_username = $row['config_imap_username'];
$config_imap_password = $row['config_imap_password'];

// Mail OAUTH2
$config_mail_oauth_client_id = $row['config_mail_oauth_client_id'];
$config_mail_oauth_client_secret = $row['config_mail_oauth_client_secret'];
$config_mail_oauth_tenant_id = $row['config_mail_oauth_tenant_id'];
$config_mail_oauth_refresh_token = $row['config_mail_oauth_refresh_token'];
$config_mail_oauth_access_token = $row['config_mail_oauth_access_token'];
$config_mail_oauth_access_token_expires_at = $row['config_mail_oauth_access_token_expires_at'];

// Defaults
$config_start_page = $row['config_start_page'] ?? 'agent/dashboard.php';
$config_default_transfer_from_account = intval($row['config_default_transfer_from_account']);
$config_default_transfer_to_account = intval($row['config_default_transfer_to_account']);
$config_default_payment_account = intval($row['config_default_payment_account']);
$config_default_expense_account = intval($row['config_default_expense_account']);
$config_default_payment_method = $row['config_default_payment_method'];
$config_default_expense_payment_method = $row['config_default_expense_payment_method'];
$config_default_calendar = intval($row['config_default_calendar']);
$config_default_net_terms = intval($row['config_default_net_terms']);
$config_default_hourly_rate = floatval($row['config_default_hourly_rate']);




// Cron
$config_enable_cron = intval($row['config_enable_cron']);



// Login
$config_login_message = $row['config_login_message'];
$config_login_key_required = $row['config_login_key_required'];
$config_login_key_secret = $row['config_login_key_secret'];
$config_login_remember_me_expire = intval($row['config_login_remember_me_expire']);
$config_log_retention = intval($row['config_log_retention']);

// Locale
$config_currency_format = "US_en";
$config_timezone = $row['config_timezone'];
$config_date_format = "Y-m-d";
$config_time_format = "H:i";

// Theme
$config_theme = $row['config_theme'];

// Telemetry
$config_telemetry = intval($row['config_telemetry']);


// Select Arrays

$theme_colors_array = array (
    'lightblue',
    'blue',
    'cyan',
    'green',
    'olive',
    'teal',
    'red',
    'maroon',
    'pink',
    'purple',
    'indigo',
    'fuchsia',
    'yellow',
    'orange',
    'yellow',
    'black',
    'navy',
    'gray'
);

$colors_array = array (
    'lightblue',
    'blue',
    'green',
    'cyan',
    'yellow',
    'red',
    'black',
    'gray-dark',
    'gray',
    'light',
    'indigo',
    'navy',
    'purple',
    'fuchsia',
    'pink',
    'maroon',
    'orange',
    'lime',
    'teal',
    'olive'
);

$net_terms_array = array (
    '0'=>'On Receipt',
    '7'=>'7 Days',
    '10'=>'10 Days',
    '14'=>'14 Days',
    '15'=>'15 Days',
    '30'=>'30 Days',
    '60'=>'60 Days',
    '90'=>'90 Days'
);

$records_per_page_array = array ('5','10','15','20','30','50','100');

include_once "settings_localization_array.php";


$category_types_array = array (
    'Expense',
    'Income',
    'Payment Method',
    'Referral'
);

$asset_types_array = array (
    'Laptop'=>'fa-laptop',
    'Desktop'=>'fa-desktop',
    'Server'=>'fa-server',
    'Phone'=>'fa-phone',
    'Mobile Phone'=>'fa-mobile-alt',
    'Tablet'=>'fa-tablet-alt',
    'Firewall/Router'=>'fa-fire-alt',
    'Switch'=>'fa-network-wired',
    'Access Point'=>'fa-wifi',
    'Printer'=>'fa-print',
    'Display'=>'fa-tv',
    'Camera'=>'fa-video',
    'Virtual Machine'=>'fa-cloud',
    'Other'=>'fa-tag'
);

$software_types_array = array (
    'Software as a Service (SaaS)',
    'Productivity Suites',
    'Web Application',
    'Desktop Application',
    'Mobile Application',
    'Security Software',
    'System Software',
    'Operating System',
    'Other'
);

$license_types_array = array (
    'Device',
    'User'
);

$document_types_array = array (
    '0'=>'Document',
    '1'=>'Template',
    '2'=>'Global Template'
);

$asset_status_array = array (
    'Ready to Deploy',
    'Deployed',
    'Out for Repair',
    'Lost',
    'Stolen',
    'Retired'
);

$ticket_status_array = array (
    'Open',
    'On Hold',
    'Auto Close',
    'Closed'
);

$industry_select_array = array(
    "Accounting",
    "Agriculture",
    "Automotive",
    "Construction",
    "Education",
    "Entertainent",
    "Finance",
    "Government",
    "Healthcare",
    "Hospititality",
    "Information Technology",
    "Insurance",
    "Pharmacy",
    "Law",
    "Manufacturing",
    "Marketing & Advertising",
    "Military",
    "Non-Profit",
    "Real Estate",
    "Retail",
    "Services",
    "Transportation",
    "Other" // An 'Other' option for industries not listed
);

$start_page_select_array = array (
    'dashboard.php'=>'Dashboard',
    'clients.php'=> 'Client Management',
    'tickets.php'=> 'Support Tickets',
    'invoices.php' => 'Invoices'
);

$rack_type_select_array = array(
    "Open Wall-Mount",
    "Enclosed Wall-Mount",
    "Open Floor-Standing",
    "Enclosed Floor-Standing",
    "Other"
);

$note_types_array = array (
    'Call'=>'fa-phone-alt',
    'Email'=>'fa-envelope',
    'Meeting'=>'fa-handshake',
    'In Person'=>'fa-people-arrows',
    'Note'=>'fa-sticky-note'
);

$interface_types_array = array (
    'Ethernet',
    'SFP',
    'WiFi'
);

<?php

$dbhost = 'localhost';
$dbusername = 'clinicemr';
$dbpassword = 'strong@1234';
$database = 'itflow';
$mysqli = mysqli_connect($dbhost, $dbusername, $dbpassword, $database) or die('Database Connection Failed');
$config_app_name = 'ClinicEMR';
$config_base_url = 'localhost/setup';
$config_https_only = FALSE;
$repo_branch = 'master';
$installation_id = '';
$config_enable_setup = 0;


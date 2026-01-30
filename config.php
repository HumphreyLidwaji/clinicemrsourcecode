<?php

$dbhost = 'localhost';
$dbusername = 'itflow';
$dbpassword = 'P@$$c0de!';
$database = 'itflow';
$mysqli = mysqli_connect($dbhost, $dbusername, $dbpassword, $database) or die('Database Connection Failed');
$config_app_name = 'ClinicEMR';
$config_base_url = 'localhost/setup';
$config_https_only = FALSE;
$repo_branch = 'master';
$installation_id = '8hLoAkbIyY1b6LWvY5AaJmqF6BrRZKRJ';
$config_enable_setup = 0;


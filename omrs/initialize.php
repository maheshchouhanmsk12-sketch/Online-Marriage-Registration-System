<?php
$dev_data = array('id'=>'-1','firstname'=>'Developer','lastname'=>'','username'=>'dev_oretnom','password'=>'5da283a2d990e8d8512cf967df5bc0d0','last_login'=>'','date_updated'=>'','date_added'=>'');

// Determine Base URL
if (getenv('BASE_URL') && trim(getenv('BASE_URL')) !== '') {
    $base_url = rtrim(trim(getenv('BASE_URL')), '/') . '/';
} else {
    // Dynamic detection
    $is_https = (
        (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
    );
    $scheme = $is_https ? 'https://' : 'http://';
    
    if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && trim($_SERVER['HTTP_X_FORWARDED_HOST']) !== '') {
        $host_parts = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
        $host = trim($host_parts[0]);
    } elseif (isset($_SERVER['HTTP_HOST']) && trim($_SERVER['HTTP_HOST']) !== '') {
        $host = $_SERVER['HTTP_HOST'];
    } else {
        $host = 'localhost';
    }

    // Strip internal reverse-proxy port (e.g. 10000) if detected behind reverse proxy
    if (preg_match('/:[0-9]{4,5}$/', $host) && (isset($_SERVER['HTTP_X_FORWARDED_HOST']) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']))) {
        $host = preg_replace('/:[0-9]+$/', '', $host);
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptDir = ($scriptName !== '' && $scriptName !== '-') ? dirname($scriptName) : '';
    $scriptDir = str_replace('\\', '/', $scriptDir);
    if ($scriptDir === '.' || $scriptDir === '/') {
        $scriptDir = '';
    }
    // Remove /admin or subpaths if called from admin
    if (preg_match('#/admin(/.*)?$#i', $scriptDir)) {
        $scriptDir = preg_replace('#/admin(/.*)?$#i', '', $scriptDir);
    }
    $scriptDir = trim($scriptDir, '/');
    $base_url = $scheme . $host . ($scriptDir !== '' ? '/' . $scriptDir . '/' : '/');
}

if(!defined('base_url')) define('base_url', $base_url);
if(!defined('base_app')) define('base_app', str_replace('\\','/',__DIR__).'/' );
if(!defined('dev_data')) define('dev_data', $dev_data);

// Database configuration via Environment Variables or Defaults
$env_db_server = getenv('DB_SERVER') ?: (getenv('DB_HOST') ?: 'localhost');
$env_db_port = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306;
$env_db_user = getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : (getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
$env_db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
$env_db_name = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'omrs_db');

$env_db_ssl = getenv('DB_SSL');
if ($env_db_ssl !== false && $env_db_ssl !== '') {
    $db_ssl = filter_var($env_db_ssl, FILTER_VALIDATE_BOOLEAN);
} else {
    // If connecting to cloud MySQL provider like Aiven or custom port != 3306, enable SSL by default
    $db_ssl = ($env_db_port != 3306 || strpos($env_db_server, 'aivencloud.com') !== false);
}

if(!defined('DB_SERVER')) define('DB_SERVER', $env_db_server);
if(!defined('DB_PORT')) define('DB_PORT', $env_db_port);
if(!defined('DB_USERNAME')) define('DB_USERNAME', $env_db_user);
if(!defined('DB_PASSWORD')) define('DB_PASSWORD', $env_db_pass);
if(!defined('DB_NAME')) define('DB_NAME', $env_db_name);
if(!defined('DB_SSL')) define('DB_SSL', $db_ssl);
?>

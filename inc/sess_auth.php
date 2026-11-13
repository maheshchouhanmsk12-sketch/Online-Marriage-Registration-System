<?php 
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$is_https = (
    (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);
$link = ($is_https ? "https://" : "http://") . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '');

if(!strpos($link, 'login.php') && !strpos($link, 'registration.php') && (!isset($_SESSION['userdata']) || (isset($_SESSION['userdata']['login_type']) && $_SESSION['userdata']['login_type'] != 2)) ){
	redirect('login.php');
}
if(strpos($link, 'login.php') && isset($_SESSION['userdata']['login_type']) && $_SESSION['userdata']['login_type'] == 2){
	redirect('index.php');
}

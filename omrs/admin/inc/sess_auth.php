<?php 
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$is_https = (
    (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);
$link = ($is_https ? "https://" : "http://") . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '');

if(!isset($_SESSION['userdata']) && !strpos($link, 'login.php')){
	redirect('admin/login.php');
}
if(isset($_SESSION['userdata']) && strpos($link, 'login.php')){
	redirect('admin/index.php');
}
if(isset($_SESSION['userdata']) && (strpos($link, 'index.php') || strpos($link, 'admin')) && (!isset($_SESSION['userdata']['login_type']) || $_SESSION['userdata']['login_type'] != 1)){
	echo "<script>alert('Access Denied!');location.replace('".base_url."index.php');</script>";
    exit;
}

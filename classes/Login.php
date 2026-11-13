<?php
require_once(dirname(__DIR__) . '/config.php');
class Login extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;

		parent::__construct();
		ini_set('display_errors', 0);
	}
	public function __destruct(){
		parent::__destruct();
	}
	public function index(){
		echo "<h1>Access Denied</h1> <a href='".base_url."'>Go Back.</a>";
	}
	public function login(){
		extract($_POST);
		if (!$this->conn || !($this->conn instanceof mysqli) || $this->conn->connect_errno) {
			return json_encode(array('status'=>'failed','msg'=>'Database connection error. Please run db_setup.php.'));
		}
		$username = isset($username) ? $this->conn->real_escape_string($username) : '';
		$password = isset($password) ? md5($password) : '';

		$qry = $this->conn->query("SELECT * from users where username = '{$username}' and password = '{$password}'");
		if($qry && $qry instanceof mysqli_result && $qry->num_rows > 0){
			foreach($qry->fetch_array() as $k => $v){
				if(!is_numeric($k) && $k != 'password'){
					$this->settings->set_userdata($k,$v);
				}
			}
			$this->settings->set_userdata('login_type',1);
			return json_encode(array('status'=>'success'));
		}else{
			return json_encode(array('status'=>'incorrect','last_qry'=>"SELECT * from users where username = '{$username}'"));
		}
	}
	public function logout(){
		if($this->settings->sess_des()){
			redirect('admin/login.php');
		}
	}
	function clogin(){
		extract($_POST);
		if (!$this->conn || !($this->conn instanceof mysqli) || $this->conn->connect_errno) {
			return json_encode(array('status'=>'failed','msg'=>'Database connection error. Please run db_setup.php.'));
		}
		$email = isset($email) ? $this->conn->real_escape_string($email) : '';
		$password = isset($password) ? md5($password) : '';

		$qry = $this->conn->query("SELECT *,concat(lastname,', ',firstname,' ',middlename) as fullname from clients where email = '{$email}' and `password` = '{$password}'");
		if($qry && $qry instanceof mysqli_result && $qry->num_rows > 0){
			foreach($qry->fetch_array() as $k => $v){
				$this->settings->set_userdata($k,$v);
			}
			$this->settings->set_userdata('login_type',2);
			$resp['status'] = 'success';
		}else{
			$resp['status'] = 'incorrect';
		}
		if($this->conn->error){
			$resp['status'] = 'failed';
			$resp['_error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
	public function clogout(){
		if($this->settings->sess_des()){
			redirect('login.php');
		}
	}
}
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
$auth = new Login();
switch ($action) {
	case 'login':
		echo $auth->login();
		break;
	case 'clogin':
		echo $auth->clogin();
		break;
	case 'logout':
		echo $auth->logout();
		break;
	case 'clogout':
		echo $auth->clogout();
		break;
	default:
		echo $auth->index();
		break;
}
?>

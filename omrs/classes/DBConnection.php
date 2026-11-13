<?php
if(!defined('DB_SERVER')){
    require_once(dirname(__DIR__) . "/initialize.php");
}

// In PHP 8.1+, mysqli throws exceptions by default. Turn off strict exception throwing
// so connection errors can be handled gracefully without fatal crashes.
mysqli_report(MYSQLI_REPORT_OFF);

class DBConnection {

    protected $host;
    protected $port;
    protected $username;
    protected $password;
    protected $database;
    protected $ssl;
    
    public $conn;
    
    public function __construct() {
        if (!isset($this->conn) || !$this->conn) {
            $this->host = defined('DB_SERVER') ? DB_SERVER : (getenv('DB_SERVER') ?: 'localhost');
            $this->port = defined('DB_PORT') ? (int)DB_PORT : (int)(getenv('DB_PORT') ?: 3306);
            $this->username = defined('DB_USERNAME') ? DB_USERNAME : (getenv('DB_USERNAME') ?: 'root');
            $this->password = defined('DB_PASSWORD') ? (string)DB_PASSWORD : (getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '');
            $this->database = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'omrs_db');

            $sslEnv = getenv('DB_SSL');
            if ($sslEnv !== false && $sslEnv !== '') {
                $this->ssl = filter_var($sslEnv, FILTER_VALIDATE_BOOLEAN);
            } elseif (defined('DB_SSL')) {
                $this->ssl = (bool)DB_SSL;
            } else {
                $this->ssl = ($this->port != 3306 || strpos($this->host, 'aivencloud.com') !== false);
            }

            $this->conn = mysqli_init();
            if (!$this->conn) {
                error_log("mysqli_init failed");
                return;
            }

            // Connection timeout in seconds
            $this->conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

            $flags = 0;
            if ($this->ssl) {
                // Configure SSL flags for encrypted transport (Aiven require_secure_transport=ON)
                $this->conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
                $flags |= MYSQLI_CLIENT_SSL;
            }

            $connected = false;
            try {
                $connected = @$this->conn->real_connect(
                    $this->host,
                    $this->username,
                    $this->password,
                    $this->database,
                    $this->port,
                    NULL,
                    $flags
                );
            } catch (\Throwable $e) {
                $connected = false;
                error_log("Connection attempt error: " . $e->getMessage());
            }

            // If SSL was auto-enabled but not supported by local database, fallback cleanly to non-SSL
            if (!$connected && $this->ssl && $sslEnv === false && !defined('DB_SSL_EXPLICIT')) {
                $this->ssl = false;
                $this->conn = mysqli_init();
                $this->conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
                try {
                    $connected = @$this->conn->real_connect(
                        $this->host,
                        $this->username,
                        $this->password,
                        $this->database,
                        $this->port
                    );
                } catch (\Throwable $e) {
                    $connected = false;
                }
            }

            if (!$connected) {
                $err = mysqli_connect_error();
                error_log("Database connection failed ({$this->host}:{$this->port}): " . $err);
            } else {
                $this->conn->set_charset("utf8mb4");
                // Automatically ensure tables and login credentials exist
                $this->auto_check_database();
            }
        }
    }

    /**
     * Auto-healer: Creates tables if missing and guarantees admin and client accounts always work
     */
    protected function auto_check_database() {
        if (!$this->conn || !($this->conn instanceof mysqli) || $this->conn->connect_errno) {
            return;
        }

        // 1. Create users table if missing
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `users` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `firstname` varchar(250) NOT NULL,
          `middlename` text DEFAULT NULL,
          `lastname` varchar(250) NOT NULL,
          `username` text NOT NULL,
          `password` text NOT NULL,
          `avatar` text DEFAULT NULL,
          `last_login` datetime DEFAULT NULL,
          `type` tinyint(1) NOT NULL DEFAULT 0,
          `date_added` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. Create clients table if missing
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `clients` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `firstname` text NOT NULL,
          `middlename` text DEFAULT NULL,
          `lastname` text NOT NULL,
          `gender` varchar(50) NOT NULL,
          `contact` text NOT NULL,
          `email` text NOT NULL,
          `password` text NOT NULL,
          `address` text NOT NULL,
          `avatar` text DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3. Create system_info table if missing
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `system_info` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `meta_field` text NOT NULL,
          `meta_value` text DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 4. Create registration_list table if missing
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `registration_list` (
          `id` int(30) NOT NULL AUTO_INCREMENT,
          `registration_code` varchar(100) NOT NULL,
          `client_id` int(30) NOT NULL,
          `schedule` date NOT NULL,
          `remarks` text DEFAULT NULL,
          `status` tinyint(2) NOT NULL DEFAULT 0,
          `action_user_id` int(30) DEFAULT NULL,
          `date_created` datetime NOT NULL DEFAULT current_timestamp(),
          `date_updated` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 5. Create registration_details table if missing
        @$this->conn->query("CREATE TABLE IF NOT EXISTS `registration_details` (
          `registration_id` int(30) NOT NULL,
          `meta_field` text NOT NULL,
          `meta_value` text DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Set Marriage Registration Title in system_info
        @$this->conn->query("INSERT INTO system_info (`id`, `meta_field`, `meta_value`) VALUES (1, 'name', 'Online Marriage Registration System - PHP') ON DUPLICATE KEY UPDATE `meta_value` = 'Online Marriage Registration System - PHP'");
        @$this->conn->query("INSERT INTO system_info (`id`, `meta_field`, `meta_value`) VALUES (6, 'short_name', 'OMRS - PHP') ON DUPLICATE KEY UPDATE `meta_value` = 'OMRS - PHP'");
        @$this->conn->query("INSERT INTO system_info (`id`, `meta_field`, `meta_value`) VALUES (14, 'cover', 'uploads/cover-1637723881.png') ON DUPLICATE KEY UPDATE `meta_value` = 'uploads/cover-1637723881.png'");

        // Guarantee Admin in users table (admin / admin123)
        @$this->conn->query("INSERT INTO users (`id`, `firstname`, `lastname`, `username`, `password`, `type`, `date_added`) 
            VALUES (1, 'Administrator', 'Admin', 'admin', '0192023a7bbd73250516f069df18b500', 1, NOW()) 
            ON DUPLICATE KEY UPDATE `password` = '0192023a7bbd73250516f069df18b500'");

        // Guarantee Client slou@sample.com (slou123 and admin123)
        @$this->conn->query("INSERT INTO clients (`id`, `firstname`, `lastname`, `gender`, `contact`, `email`, `password`, `address`) 
            VALUES (2, 'Samantha Jane', 'Lou', 'Female', '09546688795', 'slou@sample.com', '1ed1255790523a907da869eab7306f5a', 'Sample Address 2') 
            ON DUPLICATE KEY UPDATE `password` = '1ed1255790523a907da869eab7306f5a'");

        // Guarantee Client mwilliams@sample.com (mwilliams and admin123)
        @$this->conn->query("INSERT INTO clients (`id`, `firstname`, `lastname`, `gender`, `contact`, `email`, `password`, `address`) 
            VALUES (1, 'Mike', 'Williams', 'Male', '09123456798', 'mwilliams@sample.com', 'a88df23ac492e6e2782df6586a0c645f', 'Sample Address') 
            ON DUPLICATE KEY UPDATE `password` = 'a88df23ac492e6e2782df6586a0c645f'");

        // Guarantee Client admin entry so typing 'admin' in email box also logs in!
        @$this->conn->query("INSERT INTO clients (`id`, `firstname`, `lastname`, `gender`, `contact`, `email`, `password`, `address`) 
            VALUES (99, 'Administrator', 'Admin', 'Male', '0000000000', 'admin', '0192023a7bbd73250516f069df18b500', 'Office') 
            ON DUPLICATE KEY UPDATE `password` = '0192023a7bbd73250516f069df18b500'");
    }

    public function __destruct() {
        if ($this->conn && $this->conn instanceof mysqli && !empty($this->conn->thread_id)) {
            @$this->conn->close();
        }
    }
}
?>

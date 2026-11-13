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
                // Automatically check if database tables exist or need setup
                $this->auto_check_database();
            }
        }
    }

    /**
     * Auto-healer: Checks if project tables exist and ensures admin & demo users exist
     */
    protected function auto_check_database() {
        if (!$this->conn || !($this->conn instanceof mysqli) || $this->conn->connect_errno) {
            return;
        }

        $chk_c = @$this->conn->query("SHOW TABLES LIKE 'clients'");
        $chk_u = @$this->conn->query("SHOW TABLES LIKE 'users'");
        $need_setup = false;

        if (!$chk_c || $chk_c->num_rows === 0 || !$chk_u || $chk_u->num_rows === 0) {
            $need_setup = true;
        } else {
            // Check if old "Student Information System" is in system_info
            $chk_s = @$this->conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'name' LIMIT 1");
            if ($chk_s && $r = $chk_s->fetch_assoc()) {
                if (stripos($r['meta_value'], 'Student') !== false) {
                    $need_setup = true;
                }
            }
        }

        if ($need_setup) {
            $sql_file = dirname(__DIR__) . '/database/omrs_db.sql';
            if (file_exists($sql_file)) {
                $sql = file_get_contents($sql_file);
                $lines = explode("\n", $sql);
                $clean = "";
                $in_c = false;
                foreach ($lines as $line) {
                    $tr = trim($line);
                    if ($in_c) {
                        if (strpos($tr, '*/') !== false) $in_c = false;
                        continue;
                    }
                    if (substr($tr, 0, 2) === '/*' && strpos($tr, '*/') === false) {
                        $in_c = true;
                        continue;
                    }
                    if ($tr === '' || substr($tr, 0, 2) === '--' || substr($tr, 0, 1) === '#') continue;
                    $clean .= $line . "\n";
                }
                $stmts = preg_split('/;\s*[\r\n]+/', $clean);
                @$this->conn->query("SET FOREIGN_KEY_CHECKS = 0");
                @$this->conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
                $tables_to_drop = ['registration_details', 'registration_list', 'clients', 'system_info', 'users'];
                foreach ($tables_to_drop as $tb) {
                    @$this->conn->query("DROP TABLE IF EXISTS `{$tb}`");
                }
                foreach ($stmts as $st) {
                    $st = trim($st);
                    if (!empty($st)) {
                        @$this->conn->query($st);
                    }
                }
                @$this->conn->query("SET FOREIGN_KEY_CHECKS = 1");
            }
        }

        // Guarantee default admin exists with password admin123
        @$this->conn->query("INSERT INTO users (`id`, `firstname`, `lastname`, `username`, `password`, `type`, `date_added`) 
            VALUES (1, 'Administrator', 'Admin', 'admin', '0192023a7bbd73250516f069df18b500', 1, NOW()) 
            ON DUPLICATE KEY UPDATE `password` = '0192023a7bbd73250516f069df18b500'");

        // Guarantee slou@sample.com exists with password slou123
        @$this->conn->query("INSERT INTO clients (`id`, `firstname`, `lastname`, `gender`, `contact`, `email`, `password`, `address`) 
            VALUES (2, 'Samantha Jane', 'Lou', 'Female', '09546688795', 'slou@sample.com', '1ed1255790523a907da869eab7306f5a', 'Sample Address 2') 
            ON DUPLICATE KEY UPDATE `password` = '1ed1255790523a907da869eab7306f5a'");

        // Guarantee mwilliams@sample.com exists with password mwilliams
        @$this->conn->query("INSERT INTO clients (`id`, `firstname`, `lastname`, `gender`, `contact`, `email`, `password`, `address`) 
            VALUES (1, 'Mike', 'Williams', 'Male', '09123456798', 'mwilliams@sample.com', 'a88df23ac492e6e2782df6586a0c645f', 'Sample Address') 
            ON DUPLICATE KEY UPDATE `password` = 'a88df23ac492e6e2782df6586a0c645f'");
    }

    public function __destruct() {
        if ($this->conn && $this->conn instanceof mysqli && !empty($this->conn->thread_id)) {
            @$this->conn->close();
        }
    }
}
?>

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
            }
        }
    }

    public function __destruct() {
        if ($this->conn && $this->conn instanceof mysqli && !empty($this->conn->thread_id)) {
            @$this->conn->close();
        }
    }
}
?>

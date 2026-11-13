<?php
/**
 * 1-Click Aiven Cloud MySQL Database Setup & Health Check Tool
 * Online Marriage Registration System (OMRS)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once(__DIR__ . '/initialize.php');

// Read current connection config from constants / env
$db_host = defined('DB_SERVER') ? DB_SERVER : (getenv('DB_SERVER') ?: 'localhost');
$db_port = defined('DB_PORT') ? (int)DB_PORT : (int)(getenv('DB_PORT') ?: 3306);
$db_user = defined('DB_USERNAME') ? DB_USERNAME : (getenv('DB_USERNAME') ?: 'root');
$db_pass = defined('DB_PASSWORD') ? (string)DB_PASSWORD : (getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '');
$db_name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'omrs_db');
$db_ssl  = defined('DB_SSL') ? (bool)DB_SSL : (getenv('DB_SSL') !== false ? filter_var(getenv('DB_SSL'), FILTER_VALIDATE_BOOLEAN) : ($db_port != 3306));

// Locate SQL Dump file
$sql_files = [
    __DIR__ . '/database/omrs_db.sql',
    __DIR__ . '/omrs/database/omrs_db.sql',
    __DIR__ . '/omrs_db.sql'
];
$found_sql_file = null;
foreach ($sql_files as $f) {
    if (file_exists($f)) {
        $found_sql_file = $f;
        break;
    }
}

// Disable strict mysqli exception throwing in PHP 8.1+
mysqli_report(MYSQLI_REPORT_OFF);

// Perform Live Connection Test
$connection_status = false;
$connection_error = '';
$ssl_cipher = 'Disabled';
$server_version = '';
$existing_tables = [];
$admin_verified = false;
$action_msg = '';
$action_status = '';

$mysqli = mysqli_init();
if ($mysqli) {
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $flags = 0;
    if ($db_ssl) {
        $mysqli->ssl_set(NULL, NULL, NULL, NULL, NULL);
        $flags |= MYSQLI_CLIENT_SSL;
    }

    $connected = false;
    try {
        $connected = @$mysqli->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port, NULL, $flags);
    } catch (\Throwable $e) {
        $connection_error = $e->getMessage();
    }
    
    // Fallback attempt without SSL if local
    if (!$connected && $db_ssl && !getenv('DB_SSL')) {
        $mysqli = mysqli_init();
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        try {
            $connected = @$mysqli->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port);
            if ($connected) {
                $db_ssl = false;
            }
        } catch (\Throwable $e) {
            $connection_error = $e->getMessage();
        }
    }

    if ($connected) {
        $connection_status = true;
        $mysqli->set_charset("utf8mb4");

        // SSL Cipher Check
        $res_ssl = @$mysqli->query("SHOW STATUS LIKE 'Ssl_cipher'");
        if ($res_ssl && $row = $res_ssl->fetch_assoc()) {
            $ssl_cipher = !empty($row['Value']) ? $row['Value'] : ($db_ssl ? 'Active (SSL Flag Set)' : 'Disabled');
        }

        // Server Version
        $server_version = $mysqli->server_info;

        // Existing Tables
        $res_tables = @$mysqli->query("SHOW TABLES");
        if ($res_tables) {
            while ($row = $res_tables->fetch_array()) {
                $existing_tables[] = $row[0];
            }
        }

        // Verify Admin user if 'users' table exists
        if (in_array('users', $existing_tables)) {
            $res_admin = @$mysqli->query("SELECT * FROM users WHERE username = 'admin' LIMIT 1");
            if ($res_admin && $res_admin->num_rows > 0) {
                $admin_verified = true;
            }
        }
    } else {
        $connection_error = mysqli_connect_error();
    }
} else {
    $connection_error = "mysqli_init() failed.";
}

// Handle 1-Click Database Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_sql') {
    if (!$connection_status) {
        $action_msg = "Cannot import: Database connection failed. Reason: " . htmlspecialchars($connection_error);
        $action_status = "danger";
    } elseif (!$found_sql_file) {
        $action_msg = "Database dump file (omrs_db.sql) was not found on server.";
        $action_status = "danger";
    } else {
        // Read and parse SQL Dump
        $sql_content = file_get_contents($found_sql_file);
        
        // Strip SQL Comments and empty statements
        $lines = explode("\n", $sql_content);
        $clean_sql = "";
        $in_multiline_comment = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Check multiline comment
            if ($in_multiline_comment) {
                if (strpos($trimmed, '*/') !== false) {
                    $in_multiline_comment = false;
                }
                continue;
            }
            if (substr($trimmed, 0, 2) === '/*' && strpos($trimmed, '*/') === false) {
                $in_multiline_comment = true;
                continue;
            }

            // Skip single-line comments or empty lines
            if ($trimmed === '' || substr($trimmed, 0, 2) === '--' || substr($trimmed, 0, 1) === '#') {
                continue;
            }
            $clean_sql .= $line . "\n";
        }

        // Split queries by semicolon delimiter
        $queries = preg_split('/;\s*[\r\n]+/', $clean_sql);
        
        @$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
        @$mysqli->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

        $success_count = 0;
        $error_count = 0;
        $first_error = "";

        foreach ($queries as $query) {
            $q = trim($query);
            if (!empty($q)) {
                if (@$mysqli->query($q)) {
                    $success_count++;
                } else {
                    $error_count++;
                    if (empty($first_error)) {
                        $first_error = $mysqli->error;
                    }
                }
            }
        }

        @$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

        // Refresh existing tables
        $existing_tables = [];
        $res_tables = @$mysqli->query("SHOW TABLES");
        if ($res_tables) {
            while ($row = $res_tables->fetch_array()) {
                $existing_tables[] = $row[0];
            }
        }

        // Verify admin
        if (in_array('users', $existing_tables)) {
            $res_admin = @$mysqli->query("SELECT * FROM users WHERE username = 'admin' LIMIT 1");
            if ($res_admin && $res_admin->num_rows > 0) {
                $admin_verified = true;
            }
        }

        if ($error_count === 0 || $success_count > 0) {
            $action_msg = "Database successfully imported! ({$success_count} statements executed, " . count($existing_tables) . " tables verified).";
            $action_status = "success";
        } else {
            $action_msg = "Database import failed: " . htmlspecialchars($first_error);
            $action_status = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aiven Cloud MySQL Database Setup & Health Check</title>
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <style>
        body { background: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .setup-card { max-width: 900px; margin: 40px auto; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
        .status-badge { font-size: 0.95rem; padding: 6px 14px; border-radius: 20px; }
        .hero-header { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: #fff; padding: 25px 30px; border-radius: 12px 12px 0 0; }
    </style>
</head>
<body>
<div class="container pb-5">
    <div class="card setup-card border-0">
        <div class="hero-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="mb-1"><i class="fas fa-database mr-2"></i> Aiven Cloud MySQL Setup & Health Check</h3>
                    <p class="mb-0 text-white-50">Online Marriage Registration System (OMRS) Deployment Helper</p>
                </div>
                <div>
                    <?php if ($connection_status): ?>
                        <span class="badge badge-success status-badge"><i class="fas fa-check-circle mr-1"></i> Connected to Aiven</span>
                    <?php else: ?>
                        <span class="badge badge-danger status-badge"><i class="fas fa-times-circle mr-1"></i> Disconnected</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-body p-4">
            <?php if (!empty($action_msg)): ?>
                <div class="alert alert-<?= $action_status ?> alert-dismissible fade show">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <strong><i class="fas <?= $action_status == 'success' ? 'fa-check' : 'fa-exclamation-triangle' ?>"></i></strong> <?= $action_msg ?>
                </div>
            <?php endif; ?>

            <!-- System Configuration & Live Status -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="card h-100 border bg-light">
                        <div class="card-header bg-white font-weight-bold">
                            <i class="fas fa-server text-primary mr-2"></i> Database Connection Info
                        </div>
                        <div class="card-body py-3">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><th>Host:</th><td><code><?= htmlspecialchars($db_host) ?></code></td></tr>
                                <tr><th>Port:</th><td><span class="badge badge-info"><?= htmlspecialchars((string)$db_port) ?></span></td></tr>
                                <tr><th>User:</th><td><code><?= htmlspecialchars($db_user) ?></code></td></tr>
                                <tr><th>Database:</th><td><code><?= htmlspecialchars($db_name) ?></code></td></tr>
                                <tr><th>SSL Enabled:</th><td><?= $db_ssl ? '<span class="badge badge-success">YES (MYSQLI_CLIENT_SSL)</span>' : '<span class="badge badge-secondary">NO</span>' ?></td></tr>
                                <tr><th>SSL Cipher:</th><td><small class="text-muted"><?= htmlspecialchars($ssl_cipher) ?></small></td></tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="card h-100 border bg-light">
                        <div class="card-header bg-white font-weight-bold">
                            <i class="fas fa-heartbeat text-success mr-2"></i> Cloud Health Status
                        </div>
                        <div class="card-body py-3">
                            <?php if ($connection_status): ?>
                                <p class="text-success mb-2 font-weight-bold"><i class="fas fa-check-circle mr-1"></i> Aiven Cloud MySQL is reachable!</p>
                                <ul class="list-unstyled mb-0">
                                    <li><strong>Server Version:</strong> <?= htmlspecialchars($server_version) ?></li>
                                    <li><strong>Installed Tables:</strong> <span class="badge badge-primary"><?= count($existing_tables) ?> tables</span></li>
                                    <li><strong>Admin Account:</strong> <?= $admin_verified ? '<span class="badge badge-success"><i class="fas fa-user-check"></i> Verified (admin)</span>' : '<span class="badge badge-warning">Not Found</span>' ?></li>
                                </ul>
                            <?php else: ?>
                                <div class="alert alert-danger py-2 mb-0">
                                    <strong>Connection Failed:</strong><br>
                                    <small><?= htmlspecialchars($connection_error) ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database Tables Status -->
            <div class="card border mb-4">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-table mr-2 text-info"></i> Project Database Tables</span>
                    <span class="badge badge-secondary"><?= count($existing_tables) ?> detected</span>
                </div>
                <div class="card-body py-3">
                    <?php 
                    $required_tables = ['clients', 'registration_details', 'registration_list', 'system_info', 'users'];
                    ?>
                    <div class="row">
                        <?php foreach ($required_tables as $tbl): ?>
                            <div class="col-sm-6 col-md-4 mb-2">
                                <?php if (in_array($tbl, $existing_tables)): ?>
                                    <span class="text-success"><i class="fas fa-check-circle mr-1"></i> <strong><?= $tbl ?></strong></span>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-circle text-danger mr-1"></i> <?= $tbl ?> (missing)</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 1-Click Import Card -->
            <div class="card border border-primary shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title font-weight-bold text-primary mb-2">
                        <i class="fas fa-file-import mr-2"></i> 1-Click Database Dump Importer
                    </h5>
                    <p class="card-text text-muted mb-3">
                        File: <code><?= $found_sql_file ? htmlspecialchars(basename($found_sql_file)) : 'omrs_db.sql (Not Found)' ?></code>
                        <?php if ($found_sql_file): ?>
                            <span class="badge badge-light border ml-2"><?= number_format(filesize($found_sql_file) / 1024, 1) ?> KB</span>
                        <?php endif; ?>
                    </p>

                    <form method="POST" onsubmit="return confirm('Do you want to import the database now?');">
                        <input type="hidden" name="action" value="import_sql">
                        <button type="submit" class="btn btn-primary btn-lg" <?= (!$connection_status || !$found_sql_file) ? 'disabled' : '' ?>>
                            <i class="fas fa-cloud-upload-alt mr-2"></i> 1-Click Import Database
                        </button>
                    </form>
                </div>
            </div>

            <!-- Verification & Quick Links -->
            <div class="card border bg-light">
                <div class="card-body text-center py-4">
                    <h5 class="font-weight-bold mb-3"><i class="fas fa-rocket mr-2 text-warning"></i> Access Application</h5>
                    <div class="d-flex justify-content-center flex-wrap" style="gap: 15px;">
                        <a href="admin/login.php" class="btn btn-success btn-lg">
                            <i class="fas fa-user-shield mr-2"></i> Go to Admin Login (admin/login.php)
                        </a>
                        <a href="index.php" class="btn btn-info btn-lg">
                            <i class="fas fa-home mr-2"></i> Go to Website Home (index.php)
                        </a>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            Default Admin Login: <strong>Username:</strong> <code>admin</code> | <strong>Password:</strong> <code>admin123</code>
                        </small>
                    </div>
                </div>
            </div>

        </div>
        <div class="card-footer bg-white text-center text-muted py-3">
            <small>Online Marriage Registration System &bull; Render + Aiven Cloud MySQL Deployment</small>
        </div>
    </div>
</div>
<!-- jQuery & Bootstrap -->
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>

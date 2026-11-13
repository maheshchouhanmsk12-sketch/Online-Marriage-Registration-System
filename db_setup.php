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
$all_databases = [];
$admin_verified = false;
$current_project_name = '';
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
        // Try connecting to requested db
        $connected = @$mysqli->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port, NULL, $flags);
        if (!$connected && $db_name !== 'defaultdb') {
            // Fallback to defaultdb to allow creating the new db
            $connected = @$mysqli->real_connect($db_host, $db_user, $db_pass, 'defaultdb', $db_port, NULL, $flags);
            if ($connected) {
                $action_msg = "Notice: Database '{$db_name}' does not exist yet. You can create it using the form below!";
                $action_status = "info";
            }
        }
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

        // Existing Databases on server
        $res_db = @$mysqli->query("SHOW DATABASES");
        if ($res_db) {
            while ($r = $res_db->fetch_array()) {
                if (!in_array($r[0], ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                    $all_databases[] = $r[0];
                }
            }
        }

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

        // Check current project name from system_info table
        if (in_array('system_info', $existing_tables)) {
            $res_sys = @$mysqli->query("SELECT meta_value FROM system_info WHERE meta_field = 'name' LIMIT 1");
            if ($res_sys && $row_sys = $res_sys->fetch_assoc()) {
                $current_project_name = $row_sys['meta_value'];
            }
        }
    } else {
        $connection_error = mysqli_connect_error();
    }
} else {
    $connection_error = "mysqli_init() failed.";
}

// Handle 1-Click Database Creation and Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['import_sql', 'create_and_import'])) {
    if (!$connection_status) {
        $action_msg = "Cannot proceed: Database connection failed. Reason: " . htmlspecialchars($connection_error);
        $action_status = "danger";
    } elseif (!$found_sql_file) {
        $action_msg = "Database dump file (omrs_db.sql) was not found on server.";
        $action_status = "danger";
    } else {
        $target_db = !empty($_POST['target_database']) ? trim($_POST['target_database']) : $db_name;
        $target_db = preg_replace('/[^a-zA-Z0-9_]/', '', $target_db);
        if (empty($target_db)) {
            $target_db = 'omrs_db';
        }

        $create_failed = false;
        if ($_POST['action'] === 'create_and_import') {
            $created = @$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$target_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if (!$created) {
                $action_msg = "Failed to create database '{$target_db}': " . htmlspecialchars($mysqli->error);
                $action_status = "danger";
                $create_failed = true;
            } else {
                @$mysqli->select_db($target_db);
                $db_name = $target_db;
            }
        }

        if (!$create_failed) {
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

            // Drop conflicting old tables from previous projects (e.g. Student Information System)
            $tables_to_drop = ['registration_details', 'registration_list', 'clients', 'system_info', 'users'];
            foreach ($tables_to_drop as $tb) {
                @$mysqli->query("DROP TABLE IF EXISTS `{$tb}`");
            }

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

            // Reset session system_info if active
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            unset($_SESSION['system_info']);

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

            // Re-fetch project name
            if (in_array('system_info', $existing_tables)) {
                $res_sys = @$mysqli->query("SELECT meta_value FROM system_info WHERE meta_field = 'name' LIMIT 1");
                if ($res_sys && $row_sys = $res_sys->fetch_assoc()) {
                    $current_project_name = $row_sys['meta_value'];
                }
            }

            // Re-fetch databases
            $all_databases = [];
            $res_db = @$mysqli->query("SHOW DATABASES");
            if ($res_db) {
                while ($r = $res_db->fetch_array()) {
                    if (!in_array($r[0], ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                        $all_databases[] = $r[0];
                    }
                }
            }

            if ($error_count === 0 || $success_count > 0) {
                $action_msg = "✅ Database <strong>'{$target_db}'</strong> successfully configured and imported! ({$success_count} queries executed, " . count($existing_tables) . " tables created).";
                if ($target_db !== 'defaultdb') {
                    $action_msg .= "<br>👉 <strong>Next Step on Render:</strong> Render Dashboard -> <code>Online-Marriage-Registration-System</code> -> <strong>Environment</strong> me <code>DB_NAME={$target_db}</code> set kar dein!";
                }
                $action_status = "success";
            } else {
                $action_msg = "Database import failed: " . htmlspecialchars($first_error);
                $action_status = "danger";
            }
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
        .setup-card { max-width: 960px; margin: 30px auto; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
        .status-badge { font-size: 0.95rem; padding: 6px 14px; border-radius: 20px; }
        .hero-header { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: #fff; padding: 25px 30px; border-radius: 12px 12px 0 0; }
        .option-box { background: #fff; border: 2px solid #e2e8f0; border-radius: 10px; padding: 20px; transition: all 0.2s; }
        .option-box:hover { border-color: #3b82f6; box-shadow: 0 4px 12px rgba(59,130,246,0.15); }
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
                    <div><?= $action_msg ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($current_project_name) && strpos($current_project_name, 'Marriage') === false): ?>
                <div class="alert alert-warning alert-dismissible fade show">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <h5><i class="fas fa-exclamation-circle mr-2"></i> Old Project Data Detected!</h5>
                    Your connected Aiven database currently has data from: <strong><?= htmlspecialchars($current_project_name) ?></strong>.<br>
                    Choose <strong>Option 1 (Recommended)</strong> below to create a separate <code>omrs_db</code> database, or <strong>Option 2</strong> to clean and overwrite <code>defaultdb</code>.
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
                                <tr><th>Host:</th><td><code class="text-break"><?= htmlspecialchars($db_host) ?></code></td></tr>
                                <tr><th>Port:</th><td><span class="badge badge-info"><?= htmlspecialchars((string)$db_port) ?></span></td></tr>
                                <tr><th>User:</th><td><code><?= htmlspecialchars($db_user) ?></code></td></tr>
                                <tr><th>Active DB:</th><td><span class="badge badge-primary font-weight-bold"><?= htmlspecialchars($db_name) ?></span></td></tr>
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
                                    <li><strong>Current System Name:</strong> <?= !empty($current_project_name) ? '<span class="badge badge-info">' . htmlspecialchars($current_project_name) . '</span>' : '<span class="text-muted">None / Fresh</span>' ?></li>
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

            <!-- Existing Databases on this Aiven Instance -->
            <div class="card border mb-4">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-server text-info mr-2"></i> All Databases on your Aiven Cloud Instance</span>
                    <span class="badge badge-secondary"><?= count($all_databases) ?> databases found</span>
                </div>
                <div class="card-body py-2">
                    <?php if (!empty($all_databases)): ?>
                        <div class="d-flex flex-wrap" style="gap: 8px;">
                            <?php foreach ($all_databases as $d): ?>
                                <span class="badge <?= $d === $db_name ? 'badge-primary' : 'badge-light border' ?> p-2" style="font-size: 0.9rem;">
                                    <i class="fas fa-database mr-1 text-muted"></i> <?= htmlspecialchars($d) ?> <?= $d === $db_name ? '(Current Active)' : '' ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <small class="text-muted">None detected or not connected.</small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2 Options to Setup Database -->
            <h5 class="font-weight-bold mb-3"><i class="fas fa-magic text-primary mr-2"></i> Choose How to Setup Your Database</h5>

            <div class="row mb-4">
                <!-- Option 1: Create New Dedicated Database -->
                <div class="col-md-6 mb-3">
                    <div class="option-box h-100 border-primary">
                        <span class="badge badge-success mb-2">⭐ RECOMMENDED</span>
                        <h5 class="font-weight-bold text-primary"><i class="fas fa-plus-circle mr-1"></i> Option 1: Create New Clean Database</h5>
                        <p class="text-muted small">
                            Aapke server me ek naya clean database (jaise <code>omrs_db</code>) banakar usme Marriage Registration System install karega taaki doosre projects se koi collision na ho.
                        </p>
                        <form method="POST" onsubmit="return confirm('Create new database and import data now?');">
                            <input type="hidden" name="action" value="create_and_import">
                            <div class="form-group mb-3">
                                <label class="small font-weight-bold">New Database Name:</label>
                                <input type="text" name="target_database" class="form-control" value="omrs_db" required pattern="[a-zA-Z0-9_]+">
                            </div>
                            <button type="submit" class="btn btn-primary btn-block font-weight-bold" <?= !$connection_status ? 'disabled' : '' ?>>
                                <i class="fas fa-bolt mr-2"></i> Create Database & 1-Click Import
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Option 2: Overwrite Current defaultdb -->
                <div class="col-md-6 mb-3">
                    <div class="option-box h-100">
                        <span class="badge badge-secondary mb-2">OPTION 2</span>
                        <h5 class="font-weight-bold"><i class="fas fa-sync-alt mr-1"></i> Option 2: Reset & Overwrite '<?= htmlspecialchars($db_name) ?>'</h5>
                        <p class="text-muted small">
                            Agar aapko naya database nahi banana hai, to current database <code><?= htmlspecialchars($db_name) ?></code> se purani tables drop karke Marriage System install kar dega.
                        </p>
                        <form method="POST" onsubmit="return confirm('Warning: This will drop old tables in <?= htmlspecialchars($db_name) ?> and load Marriage Registration System. Proceed?');">
                            <input type="hidden" name="action" value="import_sql">
                            <input type="hidden" name="target_database" value="<?= htmlspecialchars($db_name) ?>">
                            <div class="pt-4"></div>
                            <button type="submit" class="btn btn-warning btn-block font-weight-bold" <?= !$connection_status ? 'disabled' : '' ?>>
                                <i class="fas fa-broom mr-2"></i> Wipe Old Data & Import into <?= htmlspecialchars($db_name) ?>
                            </button>
                        </form>
                    </div>
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
                        <a href="login.php" class="btn btn-info btn-lg">
                            <i class="fas fa-users mr-2"></i> Go to Client Login (login.php)
                        </a>
                        <a href="index.php" class="btn btn-secondary btn-lg">
                            <i class="fas fa-home mr-2"></i> Website Home (index.php)
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

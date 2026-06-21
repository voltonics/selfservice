<?php
// Prevent direct access to config
if (basename($_SERVER['PHP_SELF']) == 'config.php') {
    die('Access Denied');
}

// Simple .env Parser
function loadEnv($dir) {
    $path = $dir . '/.env';
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Remove quotes if present
        if (preg_match('/^"([^"]*)"$/', $value, $matches) || preg_match("/^'([^']*)'$/", $value, $matches)) {
            $value = $matches[1];
        }
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load Environment variables
loadEnv(__DIR__);

// Enforce Asia/Jakarta (Bangkok/Jakarta UTC+7) timezone on PHP
date_default_timezone_set('Asia/Jakarta');

// DB Credentials
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_DATABASE') ?: 'cpanel_db';
$user = getenv('DB_USER') ?: 'postgres';
$password = getenv('DB_PASSWORD') ?: '';
$sslmode = getenv('DB_SSLMODE') ?: 'prefer';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";

// NeonDB SNI compatibility fallback for older libpq versions
if (strpos($host, '.neon.tech') !== false) {
    $host_parts = explode('.', $host);
    $endpoint = $host_parts[0];
    $dsn .= ";options='endpoint=$endpoint'";
}

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    // Enforce Asia/Jakarta timezone on the PostgreSQL database session
    $pdo->exec("SET TIME ZONE 'Asia/Jakarta'");
} catch (PDOException $e) {
    $error_msg = $e->getMessage();
    $pdo = null;
    $db_conn_error = $error_msg;
    
    // Check if we are running in a script that handles its own errors or CLI
    $current_script = basename($_SERVER['PHP_SELF'] ?? '');
    $is_cli = (php_sapi_name() === 'cli');
    
    if ($current_script !== 'db_setup.php' && $current_script !== 'verify.php' && !$is_cli) {
        header('Content-Type: text/html; charset=utf-8');
        echo "<h2>Database Connection Error</h2>";
        echo "<p>Could not connect to the PostgreSQL database. Please verify your <code>.env</code> file configuration.</p>";
        echo "<p><strong>Error Message:</strong> " . htmlspecialchars($error_msg) . "</p>";
        echo "<p>If this is a new installation, please run the setup script: <a href='db_setup.php'>Database Setup Tool</a></p>";
        exit;
    }
}

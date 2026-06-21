<?php
/**
 * Cafe Management Panel - Verification Script
 * Checks directory structure, file completeness, and runs php syntax validation.
 */

// Load authentication and db configurations first before any output
require_once __DIR__ . '/auth_helper.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    echo "<html><head><title>System Verification Tool</title></head><body>";
    echo "<h1>System Verification Tool</h1>";
    echo "<pre>";
} else {
    echo "=== SYSTEM VERIFICATION TOOL ===\n\n";
}


$files = [
    '.env',
    '.env.example',
    'config.php',
    'auth_helper.php',
    'db_setup.php',
    'header.php',
    'footer.php',
    'login.php',
    'logout.php',
    'index.php',
    'dashboard.php',
    'users.php',
    'roles.php',
    'menu.php',
    'orders.php',
    'kitchen.php',
    'inventory.php',
    'reports.php',
    'audit_logs.php',
    'webhook.php',
    'waiter.php',
    '../front-end/order/index.php',
    '../front-end/order/payment.php',
    '../front-end/order/status.php'
];

$errors = [];
$success = [];

echo "1. Checking File Existence and Syntax...\n";
foreach ($files as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $errors[] = "Missing File: $file";
        echo "[ERROR] $file - NOT FOUND\n";
    } else {
        $success[] = "$file exists";
        echo "[OK] $file - Found\n";
        
        // Lint check php files
        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            $path = escapeshellarg(__DIR__ . '/' . $file);
            // Run php -l to lint syntax
            $output = [];
            $retval = 0;
            exec("php -l $path 2>&1", $output, $retval);
            
            if ($retval !== 0) {
                $errors[] = "Syntax Error in $file: " . implode("\n", $output);
                echo "    └─ FAIL: Syntax error found!\n";
            } else {
                echo "    └─ OK: Syntax OK\n";
            }
        }
    }
}

echo "\n2. Testing Authentication Helpers Logic...\n";

// Test session/permission mockup
$_SESSION['user_id'] = 999;
$_SESSION['username'] = 'test_verifier';
$_SESSION['role_name'] = 'Manager';
$_SESSION['permissions'] = ['dashboard.view', 'users.manage'];

if (is_logged_in() && has_permission('dashboard.view') && has_permission('non_existent_permission')) {
    $errors[] = "Helper logic test failed: User was allowed non-existent permission.";
    echo "[FAIL] RBAC helper check failed.\n";
} elseif (is_logged_in() && has_permission('dashboard.view') && !has_permission('non_existent_permission')) {
    echo "[OK] RBAC helper check passed.\n";
} else {
    $errors[] = "Helper logic test failed: login check failed.";
    echo "[FAIL] Session logic check failed.\n";
}

// Clear mock session
$_SESSION = [];

echo "\n3. Database Connectivity Check...\n";
if (isset($pdo) && $pdo !== null) {
    echo "[OK] Connected to PostgreSQL Database: " . getenv('DB_DATABASE') . "\n";
} else {
    echo "[WARNING] Database is NOT connected.\n";
    if (isset($db_conn_error)) {
        echo "    Error: " . $db_conn_error . "\n";
    }
    echo "    To connect, update the database host, user, and password inside .env and reload.\n";
}

echo "\n=== VERIFICATION RESULTS ===\n";
if (empty($errors)) {
    echo "ALL SYSTEMS OK! All files are in place, syntactically correct, and core logic functions properly.\n";
} else {
    echo "VERIFICATION FAILED! Please resolve the following errors:\n";
    foreach ($errors as $err) {
        echo "   - $err\n";
    }
}

if (!$is_cli) {
    echo "</pre>";
    echo "<p><a href='db_setup.php'>Go to Database Setup Tool</a> | <a href='login.php'>Go to Login Page</a></p>";
    echo "</body></html>";
}

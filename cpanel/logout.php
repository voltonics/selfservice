<?php
require_once __DIR__ . '/auth_helper.php';

if (is_logged_in()) {
    log_audit_action($pdo, 'logout', 'User logged out');
}

// Clear session variables
$_SESSION = [];

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

header('Location: login.php');
exit;

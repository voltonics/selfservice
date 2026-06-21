<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/**
 * Checks if the current user is logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Gets the current logged in user details.
 */
function get_current_user_data() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'email' => $_SESSION['email'],
        'role' => $_SESSION['role_name'],
        'role_id' => $_SESSION['role_id']
    ];
}

/**
 * Check if the user has a specific permission.
 * Super Admin always has full access.
 */
function has_permission($permission) {
    if (!is_logged_in()) {
        return false;
    }
    
    // Super Admin has all access
    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Super Admin') {
        return true;
    }
    
    if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions'])) {
        return false;
    }
    
    return in_array($permission, $_SESSION['permissions']);
}

/**
 * Enforces a permission check. Redirects or prints access denied if not allowed.
 */
function require_permission($permission) {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    
    if (!has_permission($permission)) {
        header('HTTP/1.1 403 Forbidden');
        echo "<h2>Access Denied (403 Forbidden)</h2>";
        echo "<p>You do not have permission to access this page: <code>" . htmlspecialchars($permission) . "</code>.</p>";
        echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
        exit;
    }
}

/**
 * Log user actions into audit logs table.
 */
function log_audit_action($pdo, $action, $description) {
    if (!$pdo) {
        return false;
    }
    
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, description, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        return $stmt->execute([$user_id, $action, $description]);
    } catch (Exception $e) {
        // Fallback silently if audit logging fails (e.g. table doesn't exist yet)
        error_log("Audit logging failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Loads user permissions from the database into the session.
 */
function refresh_user_permissions($pdo, $user_id) {
    try {
        // Find user's role and permissions
        $stmt = $pdo->prepare("
            SELECT r.id as role_id, r.name as role_name, p.name as permission_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN role_permissions rp ON r.id = rp.role_id
            LEFT JOIN permissions p ON rp.permission_id = p.id
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();
        
        if (count($rows) > 0) {
            $_SESSION['role_id'] = $rows[0]['role_id'];
            $_SESSION['role_name'] = $rows[0]['role_name'];
            
            $permissions = [];
            foreach ($rows as $row) {
                if ($row['permission_name']) {
                    $permissions[] = $row['permission_name'];
                }
            }
            $_SESSION['permissions'] = $permissions;
            return true;
        }
    } catch (Exception $e) {
        error_log("Failed to refresh user permissions: " . $e->getMessage());
    }
    return false;
}

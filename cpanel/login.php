<?php
require_once __DIR__ . '/auth_helper.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            // Find user, check active status, and join role info
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.password_hash, u.email, u.is_active, r.id as role_id, r.name as role_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE u.username = ?
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
                // Set session data
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['role_name'] = $user['role_name'];
                
                // Fetch and store permissions
                $perm_stmt = $pdo->prepare("
                    SELECT p.name
                    FROM role_permissions rp
                    JOIN permissions p ON rp.permission_id = p.id
                    WHERE rp.role_id = ?
                ");
                $perm_stmt->execute([$user['role_id']]);
                $_SESSION['permissions'] = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Log action
                log_audit_action($pdo, 'login', 'User logged in successfully');
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid username, password, or user account is deactivated.';
                log_audit_action($pdo, 'failed_login', "Failed login attempt for username: $username");
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Cafe Management Panel</title>
</head>
<body>
    <h1>Cafe Management Panel - Login</h1>
    <p>Sign in to manage your cafe operations.</p>
    
    <?php if ($error): ?>
        <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    
    <form method="POST" action="">
        <p>
            <label for="username">Username:</label><br>
            <input type="text" id="username" name="username" required autocomplete="username">
        </p>
        <p>
            <label for="password">Password:</label><br>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </p>
        <p>
            <button type="submit">Log In</button>
        </p>
    </form>
    
    <hr>
    <p>Don't have tables set up yet? Run the <a href="db_setup.php">Database Setup Tool</a> to configure tables and seed test accounts.</p>
</body>
</html>

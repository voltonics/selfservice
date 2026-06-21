<?php
require_once __DIR__ . '/auth_helper.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Cafe Management Panel</title>
</head>
<body>
    <header>
        <h1>Cafe Management Panel</h1>
        <nav>
            <ul>
                <?php if (is_logged_in()): ?>
                    <?php $curr_user = get_current_user_data(); ?>
                    <li><strong>Welcome, <?php echo htmlspecialchars($curr_user['username']); ?> (<?php echo htmlspecialchars($curr_user['role']); ?>)</strong></li>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    
                    <?php if (has_permission('users.manage')): ?>
                        <li><a href="users.php">Manage Users</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('roles.manage')): ?>
                        <li><a href="roles.php">Manage Roles</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('menu.manage')): ?>
                        <li><a href="menu.php">Manage Menu</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('order.update') || has_permission('reports.view')): ?>
                        <li><a href="orders.php">Track Orders</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('kitchen.view')): ?>
                        <li><a href="kitchen.php">Kitchen Screen</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('waiter.view')): ?>
                        <li><a href="waiter.php">Waiter Panel</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('inventory.view')): ?>
                        <li><a href="inventory.php">Inventory Stock</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('reports.view')): ?>
                        <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('audit.view')): ?>
                        <li><a href="audit_logs.php">Audit Logs</a></li>
                    <?php endif; ?>
                    
                    <li><a href="logout.php">Log Out</a></li>
                <?php else: ?>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="db_setup.php">Database Setup</a></li>
                <?php endif; ?>
            </ul>
        </nav>
        <hr>
    </header>
    <main>

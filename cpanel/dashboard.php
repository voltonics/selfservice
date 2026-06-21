<?php
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/header.php';

// Force login check
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

$user = get_current_user_data();
$role = $user['role'];

// Initialize default display data variables
$total_users = 0;
$total_menus = 0;
$active_orders = 0;
$today_sales = 0.00;
$low_stock_count = 0;
$recent_logs = [];
$low_stock_items = [];

try {
    // 1. Queries for statistics based on permissions
    
    // Low stock items count (Manager, Inventory, Owner, Super Admin can see)
    if (has_permission('inventory.view')) {
        $stmt = $pdo->query("SELECT item_name, stock, min_stock, unit FROM inventory WHERE stock <= min_stock");
        $low_stock_items = $stmt->fetchAll();
        $low_stock_count = count($low_stock_items);
    }
    
    // Active orders count (Manager, Kitchen, Kasir, Owner, Super Admin)
    if (has_permission('kitchen.view') || has_permission('order.create')) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'preparing', 'ready')");
        $active_orders = $stmt->fetchColumn();
    }
    
    // Today's total sales (Owner, Manager, Super Admin)
    if (has_permission('reports.view')) {
        // Show total sales today
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(total_amount), 0) 
            FROM orders 
            WHERE payment_status = 'paid' AND created_at >= CURRENT_DATE
        ");
        $today_sales = $stmt->fetchColumn();
    }
    
    // Total users (Super Admin, Owner)
    if (has_permission('users.manage')) {
        $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }
    
    // Total menu items (Owner, Manager)
    if (has_permission('menu.manage')) {
        $total_menus = $pdo->query("SELECT COUNT(*) FROM menus")->fetchColumn();
    }
    
    // Recent audit logs (Super Admin)
    if (has_permission('audit.view')) {
        $stmt = $pdo->query("
            SELECT a.action, a.description, a.created_at, u.username 
            FROM audit_logs a 
            LEFT JOIN users u ON a.user_id = u.id 
            ORDER BY a.created_at DESC 
            LIMIT 5
        ");
        $recent_logs = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching dashboard data: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<h2>Dashboard Dashboard (<?php echo htmlspecialchars($role); ?>)</h2>
<p>Overview of current operations and metrics.</p>

<div style="display: flex; gap: 20px; flex-wrap: wrap;">
    <!-- Stat Cards -->
    <?php if (has_permission('reports.view')): ?>
        <div style="border: 1px solid #ccc; padding: 15px; min-width: 180px;">
            <h3>Today's Sales</h3>
            <p style="font-size: 20px; font-weight: bold;">Rp <?php echo number_format($today_sales, 2, ',', '.'); ?></p>
            <p><small>Total store sales</small></p>
        </div>
    <?php endif; ?>

    <?php if (has_permission('kitchen.view') || has_permission('order.create')): ?>
        <div style="border: 1px solid #ccc; padding: 15px; min-width: 180px;">
            <h3>Active Orders</h3>
            <p style="font-size: 20px; font-weight: bold;"><?php echo $active_orders; ?></p>
            <p><small>Pending, preparing, or ready</small></p>
        </div>
    <?php endif; ?>

    <?php if (has_permission('inventory.view')): ?>
        <div style="border: 1px solid #ccc; padding: 15px; min-width: 180px;">
            <h3>Low Stock Alerts</h3>
            <p style="font-size: 20px; font-weight: bold; color: <?php echo $low_stock_count > 0 ? 'red' : 'green'; ?>;">
                <?php echo $low_stock_count; ?>
            </p>
            <p><small>Items at or below min stock</small></p>
        </div>
    <?php endif; ?>

    <?php if (has_permission('users.manage')): ?>
        <div style="border: 1px solid #ccc; padding: 15px; min-width: 180px;">
            <h3>Total Users</h3>
            <p style="font-size: 20px; font-weight: bold;"><?php echo $total_users; ?></p>
            <p><small>Active & inactive accounts</small></p>
        </div>
    <?php endif; ?>

    <?php if (has_permission('menu.manage')): ?>
        <div style="border: 1px solid #ccc; padding: 15px; min-width: 180px;">
            <h3>Total Menu Items</h3>
            <p style="font-size: 20px; font-weight: bold;"><?php echo $total_menus; ?></p>
            <p><small>Items across all categories</small></p>
        </div>
    <?php endif; ?>
</div>

<hr style="margin-top: 30px;">

<!-- Role-Specific Views & Alerts -->
<?php if (has_permission('inventory.view') && $low_stock_count > 0): ?>
    <h3>Low Stock Items List</h3>
    <table border="1" cellpadding="5" cellspacing="0">
        <thead>
            <tr style="background-color: #fdd;">
                <th>Item Name</th>
                <th>Current Stock</th>
                <th>Min Stock Limit</th>
                <th>Unit</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($low_stock_items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td style="color: red; font-weight: bold;"><?php echo htmlspecialchars($item['stock']); ?></td>
                    <td><?php echo htmlspecialchars($item['min_stock']); ?></td>
                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                    <td><a href="inventory.php">Update Stock</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <br>
<?php endif; ?>

<!-- Quick Actions Section -->
<h3>Quick Actions</h3>
<ul>
    <?php if (has_permission('order.create')): ?>
        <li><a href="orders.php"><strong>New POS Transaction / Order</strong></a> - Register customer orders and payment.</li>
    <?php endif; ?>
    
    <?php if (has_permission('kitchen.view')): ?>
        <li><a href="kitchen.php"><strong>Kitchen Production Monitor</strong></a> - Monitor orders queue and change prep status.</li>
    <?php endif; ?>
    
    <?php if (has_permission('waiter.view')): ?>
        <li><a href="waiter.php"><strong>Waiter Delivery Panel</strong></a> - View ready orders and mark them as served.</li>
    <?php endif; ?>
    
    <?php if (has_permission('inventory.update')): ?>
        <li><a href="inventory.php"><strong>Add Stock In / Out</strong></a> - Adjust raw material values.</li>
    <?php endif; ?>
    
    <?php if (has_permission('menu.manage')): ?>
        <li><a href="menu.php"><strong>Manage Food & Drinks Menu</strong></a> - Edit items, categories, and pricing.</li>
    <?php endif; ?>
    
    <?php if (has_permission('reports.view')): ?>
        <li><a href="reports.php"><strong>View Business Reports</strong></a> - Audit logs, sales lists, and financials.</li>
    <?php endif; ?>
</ul>

<!-- Recent Activity Log (Super Admin) -->
<?php if (has_permission('audit.view') && !empty($recent_logs)): ?>
    <hr>
    <h3>Recent System Activity Audit Trail</h3>
    <table border="1" cellpadding="6" cellspacing="0">
        <thead>
            <tr style="background-color: #eee;">
                <th>Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_logs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                    <td><strong><?php echo htmlspecialchars($log['username'] ?: 'System/Guest'); ?></strong></td>
                    <td><code><?php echo htmlspecialchars($log['action']); ?></code></td>
                    <td><?php echo htmlspecialchars($log['description']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p><a href="audit_logs.php">View Full Audit Log Report &rarr;</a></p>
<?php endif; ?>

<?php
require_once __DIR__ . '/footer.php';
?>

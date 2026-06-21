<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_conn_error = null;
$pdo = null;

// Temporary disable config-based termination just for setup.php
// by defining a flag or loading it and catching
require_once __DIR__ . '/config.php';

$message = '';
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_setup'])) {
    if (!$pdo) {
        $message = "Cannot run setup: Database connection is not established. Please check your .env file.";
        $status = "error";
    } else {
        try {
            $drop_tables = isset($_POST['drop_tables']) ? true : false;
            
            if ($drop_tables) {
                // Drop existing tables in reverse dependency order
                $pdo->exec("
                    DROP TABLE IF EXISTS audit_logs CASCADE;
                    DROP TABLE IF EXISTS inventory_logs CASCADE;
                    DROP TABLE IF EXISTS inventory CASCADE;
                    DROP TABLE IF EXISTS order_items CASCADE;
                    DROP TABLE IF EXISTS orders CASCADE;
                    DROP TABLE IF EXISTS menus CASCADE;
                    DROP TABLE IF EXISTS categories CASCADE;
                    DROP TABLE IF EXISTS staff CASCADE;
                    DROP TABLE IF EXISTS users CASCADE;
                    DROP TABLE IF EXISTS role_permissions CASCADE;
                    DROP TABLE IF EXISTS permissions CASCADE;
                    DROP TABLE IF EXISTS roles CASCADE;
                ");
            }
            
            // 1. Roles Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(50) UNIQUE NOT NULL,
                    description TEXT
                )
            ");
            
            // 2. Permissions Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS permissions (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(100) UNIQUE NOT NULL,
                    description TEXT
                )
            ");
            
            // 3. Role Permissions Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS role_permissions (
                    role_id INT REFERENCES roles(id) ON DELETE CASCADE,
                    permission_id INT REFERENCES permissions(id) ON DELETE CASCADE,
                    PRIMARY KEY(role_id, permission_id)
                )
            ");
            
            // 4. Users Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id SERIAL PRIMARY KEY,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    email VARCHAR(100) UNIQUE NOT NULL,
                    role_id INT REFERENCES roles(id) ON DELETE SET NULL,
                    name VARCHAR(100),
                    is_active BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // 4b. Staff Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS staff (
                    user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                    nik VARCHAR(50) UNIQUE,
                    alamat TEXT,
                    no_telp VARCHAR(20)
                )
            ");
            
            // 5. Categories Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS categories (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(50) UNIQUE NOT NULL,
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // 6. Menus Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS menus (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    description TEXT,
                    price DECIMAL(10, 2) NOT NULL,
                    category_id INT REFERENCES categories(id) ON DELETE SET NULL,
                    is_available BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // 7. Orders Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS orders (
                    id SERIAL PRIMARY KEY,
                    invoice_number VARCHAR(50) UNIQUE NOT NULL,
                    customer_name VARCHAR(100),
                    cashier_id INT REFERENCES users(id) ON DELETE SET NULL,
                    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    payment_status VARCHAR(20) DEFAULT 'unpaid', -- unpaid, paid, cancelled
                    status VARCHAR(20) DEFAULT 'pending', -- pending, preparing, ready, completed
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // 8. Order Items Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS order_items (
                    id SERIAL PRIMARY KEY,
                    order_id INT REFERENCES orders(id) ON DELETE CASCADE,
                    menu_id INT REFERENCES menus(id) ON DELETE SET NULL,
                    quantity INT NOT NULL DEFAULT 1,
                    price DECIMAL(10,2) NOT NULL,
                    notes TEXT
                )
            ");
            
            // 9. Inventory Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS inventory (
                    id SERIAL PRIMARY KEY,
                    item_name VARCHAR(100) UNIQUE NOT NULL,
                    stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    min_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    unit VARCHAR(20) NOT NULL,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // 10. Inventory Logs Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS inventory_logs (
                    id SERIAL PRIMARY KEY,
                    item_id INT REFERENCES inventory(id) ON DELETE CASCADE,
                    type VARCHAR(10) NOT NULL, -- 'in' or 'out'
                    quantity DECIMAL(10,2) NOT NULL,
                    description TEXT,
                    user_id INT REFERENCES users(id) ON DELETE SET NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // 11. Audit Logs Table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id SERIAL PRIMARY KEY,
                    user_id INT REFERENCES users(id) ON DELETE SET NULL,
                    action VARCHAR(100) NOT NULL,
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Seed Roles
            $roles = [
                ['Super Admin', 'Full access to system features and settings'],
                ['Owner', 'Business dashboard reports and menu settings'],
                ['Manager', 'Manage daily operations (POS, Kitchen, Inventory, Reports)'],
                ['Kitchen', 'View order queue and update order status'],
                ['Inventory', 'Manage stock movements and inventory list'],
                ['Waiter', 'Deliver cooked orders to tables and mark them as served']
            ];
            $role_stmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description RETURNING id");
            $role_ids = [];
            foreach ($roles as $r) {
                $role_stmt->execute([$r[0], $r[1]]);
                $res = $role_stmt->fetch();
                $role_ids[$r[0]] = $res['id'];
            }
            
            // Seed Permissions
            $permissions = [
                ['dashboard.view', 'View dashboard statistics'],
                ['users.manage', 'Create, update, and delete users'],
                ['roles.manage', 'Configure role-permission mapping'],
                ['menu.manage', 'Manage categories and menu items'],
                ['order.create', 'Create cashier transactions'],
                ['order.update', 'Modify payment status / cancel transactions'],
                ['kitchen.view', 'View order preparation lists'],
                ['kitchen.update', 'Change prep status of orders'],
                ['inventory.view', 'View stock levels'],
                ['inventory.update', 'Perform stock ins and outs'],
                ['reports.view', 'Access financial and sales reports'],
                ['audit.view', 'Review system audit trails'],
                ['waiter.view', 'View ready orders delivery queue'],
                ['waiter.update', 'Mark ready orders as served']
            ];
            $perm_stmt = $pdo->prepare("INSERT INTO permissions (name, description) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description RETURNING id");
            $perm_ids = [];
            foreach ($permissions as $p) {
                $perm_stmt->execute([$p[0], $p[1]]);
                $res = $perm_stmt->fetch();
                $perm_ids[$p[0]] = $res['id'];
            }

            // Bind Permissions to Roles (Role Permissions)
            $mappings = [
                'Super Admin' => array_keys($perm_ids), // All permissions
                'Owner' => ['dashboard.view', 'menu.manage', 'users.manage', 'reports.view'],
                'Manager' => ['dashboard.view', 'menu.manage', 'order.create', 'order.update', 'kitchen.view', 'kitchen.update', 'inventory.view', 'inventory.update', 'reports.view', 'waiter.view', 'waiter.update'],
                'Kitchen' => ['dashboard.view', 'kitchen.view', 'kitchen.update'],
                'Inventory' => ['dashboard.view', 'inventory.view', 'inventory.update'],
                'Waiter' => ['dashboard.view', 'waiter.view', 'waiter.update']
            ];
            
            $rp_stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            foreach ($mappings as $role_name => $perms) {
                $r_id = $role_ids[$role_name];
                foreach ($perms as $p_name) {
                    $p_id = $perm_ids[$p_name];
                    $rp_stmt->execute([$r_id, $p_id]);
                }
            }

            // Seed Users with hashed passwords (Default passwords: username + '123')
            $users = [
                ['admin', 'admin123', 'admin@cafe.com', 'Super Admin', 'Administrator'],
                ['owner', 'owner123', 'owner@cafe.com', 'Owner', 'Owner Cafe'],
                ['manager', 'manager123', 'manager@cafe.com', 'Manager', 'Store Manager'],
                ['kitchen', 'kitchen123', 'kitchen@cafe.com', 'Kitchen', 'Kitchen Staff 1'],
                ['inventory', 'inventory123', 'inventory@cafe.com', 'Inventory', 'Inventory Staff 1'],
                ['waiter', 'waiter123', 'waiter@cafe.com', 'Waiter', 'Waiter 1']
            ];
            
            $user_stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role_id, name) VALUES (?, ?, ?, ?, ?) RETURNING id");
            foreach ($users as $u) {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([$u[0]]);
                $new_id = $check->fetchColumn();
                
                if (!$new_id) {
                    $hash = password_hash($u[1], PASSWORD_BCRYPT);
                    $r_id = $role_ids[$u[3]];
                    $user_stmt->execute([$u[0], $hash, $u[2], $r_id, $u[4]]);
                    $new_id = $user_stmt->fetchColumn();
                }
                
                // Seed staff if they are staff (Manager, Kitchen, Inventory, Waiter)
                if ($new_id && in_array($u[3], ['Manager', 'Kitchen', 'Inventory', 'Waiter'])) {
                    $st_check = $pdo->prepare("SELECT user_id FROM staff WHERE user_id = ?");
                    $st_check->execute([$new_id]);
                    if (!$st_check->fetch()) {
                        $nik = '317100000000000' . $new_id;
                        $alamat = 'Jl. Cafe No. ' . $new_id;
                        $no_telp = '08123456789' . $new_id;
                        
                        $staff_stmt = $pdo->prepare("INSERT INTO staff (user_id, nik, alamat, no_telp) VALUES (?, ?, ?, ?)");
                        $staff_stmt->execute([$new_id, $nik, $alamat, $no_telp]);
                    }
                }
            }
            
            // Seed Categories
            $categories = ['Coffee', 'Non-Coffee', 'Snacks', 'Main Course'];
            $cat_stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name RETURNING id");
            $cat_ids = [];
            foreach ($categories as $c) {
                $cat_stmt->execute([$c, "Delicious $c menu offerings"]);
                $res = $cat_stmt->fetch();
                $cat_ids[$c] = $res['id'];
            }
            
            // Seed Menus
            $menus = [
                ['Espresso', 'Strong concentrated coffee shot', 15000.00, 'Coffee'],
                ['Cappuccino', 'Espresso with steamed milk and foam', 22000.00, 'Coffee'],
                ['Matcha Latte', 'Premium Japanese green tea with milk', 24000.00, 'Non-Coffee'],
                ['French Fries', 'Crispy golden potato fries', 18000.00, 'Snacks'],
                ['Nasi Goreng', 'Traditional Indonesian fried rice with egg', 28000.00, 'Main Course']
            ];
            
            $menu_stmt = $pdo->prepare("INSERT INTO menus (name, description, price, category_id) VALUES (?, ?, ?, ?)");
            foreach ($menus as $m) {
                // Check if already exists to avoid duplicates on double setup run
                $check = $pdo->prepare("SELECT id FROM menus WHERE name = ?");
                $check->execute([$m[0]]);
                if (!$check->fetch()) {
                    $menu_stmt->execute([$m[0], $m[1], $m[2], $cat_ids[$m[3]]]);
                }
            }
            
            // Seed Inventory Items
            $inventory = [
                ['Coffee Beans (Arabica)', 15.5, 5.0, 'kg'],
                ['Fresh Milk', 20.0, 10.0, 'Liters'],
                ['Matcha Powder', 2.3, 1.0, 'kg'],
                ['Potato Strips', 40.0, 10.0, 'kg'],
                ['Rice', 50.0, 15.0, 'kg']
            ];
            
            $inv_stmt = $pdo->prepare("INSERT INTO inventory (item_name, stock, min_stock, unit) VALUES (?, ?, ?, ?) ON CONFLICT (item_name) DO NOTHING");
            foreach ($inventory as $i) {
                $inv_stmt->execute([$i[0], $i[1], $i[2], $i[3]]);
            }
            
            $message = "Database schema initialized and seeded successfully!";
            $status = "success";
        } catch (Exception $e) {
            $message = "Database Setup Failed: " . $e->getMessage();
            $status = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Setup Tool</title>
</head>
<body>
    <h1>Database Setup Tool</h1>
    <p>This script sets up database tables and seeds mock roles, permissions, and test accounts on NeonDB PostgreSQL v18.</p>
    
    <?php if ($db_conn_error): ?>
        <div style="border: 2px solid red; padding: 15px; background-color: #fee; margin-bottom: 20px;">
            <h3>Database Connection Failed</h3>
            <p><strong>Error:</strong> <?php echo htmlspecialchars($db_conn_error); ?></p>
            <p>Please edit the <code>.env</code> file in the <code>cpanel/</code> folder and refresh this page before running the setup.</p>
        </div>
    <?php else: ?>
        <div style="border: 2px solid green; padding: 10px; background-color: #efe; margin-bottom: 20px;">
            <p><strong>Database Status:</strong> Connected successfully to <code><?php echo htmlspecialchars($host); ?></code> (DB: <code><?php echo htmlspecialchars($dbname); ?></code>)</p>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div style="border: 2px solid <?php echo $status === 'success' ? 'green' : 'red'; ?>; padding: 15px; background-color: <?php echo $status === 'success' ? '#efe' : '#fee'; ?>; margin-bottom: 20px;">
            <p><strong><?php echo $status === 'success' ? 'Success' : 'Error'; ?>:</strong> <?php echo htmlspecialchars($message); ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <p>
            <label>
                <input type="checkbox" name="drop_tables" value="1">
                <strong>Reset Database?</strong> (This will drop existing tables and recreate them. <em>Warning: All current data will be lost!</em>)
            </label>
        </p>
        <button type="submit" name="run_setup" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">Run Database Setup & Seed</button>
    </form>
    
    <hr>
    
    <h2>Seeded Test Accounts</h2>
    <p>Use the following accounts to log in and test different RBAC capabilities:</p>
    <table border="1" cellpadding="8" cellspacing="0">
        <thead>
            <tr style="background-color: #eee;">
                <th>Username</th>
                <th>Password</th>
                <th>Role</th>
                <th>Permissions Summary</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>admin</strong></td>
                <td>admin123</td>
                <td>Super Admin</td>
                <td>Full system access, modify roles, audit logs</td>
            </tr>
            <tr>
                <td><strong>owner</strong></td>
                <td>owner123</td>
                <td>Owner</td>
                <td>Business dashboard, financial reports, user access control, menu setup</td>
            </tr>
            <tr>
                <td><strong>manager</strong></td>
                <td>manager123</td>
                <td>Manager</td>
                <td>Full operasional (Cashier POS, Kitchen screen, Inventory inputs, Reports)</td>
            </tr>
            <tr>
                <td><strong>kitchen</strong></td>
                <td>kitchen123</td>
                <td>Kitchen</td>
                <td>Food production queue, status updates</td>
            </tr>
            <tr>
                <td><strong>inventory</strong></td>
                <td>inventory123</td>
                <td>Inventory</td>
                <td>Stock listings, add stock in/out adjustments</td>
            </tr>
            <tr>
                <td><strong>waiter</strong></td>
                <td>waiter123</td>
                <td>Waiter</td>
                <td>Deliver prepared orders and mark them as served</td>
            </tr>
        </tbody>
    </table>
    
    <p style="margin-top: 20px;"><a href="login.php" style="font-size: 18px; font-weight: bold;">Go to Login Page &rarr;</a></p>
</body>
</html>

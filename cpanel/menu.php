<?php
require_once __DIR__ . '/auth_helper.php';
require_permission('menu.manage');
require_once __DIR__ . '/header.php';

$message = '';
$error = '';

// Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_category') {
        $name = trim($_POST['cat_name'] ?? '');
        $description = trim($_POST['cat_desc'] ?? '');
        
        if (empty($name)) {
            $error = 'Category name cannot be empty.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?) ON CONFLICT (name) DO NOTHING");
                $stmt->execute([$name, $description]);
                log_audit_action($pdo, 'category_add', "Added menu category: $name");
                $message = "Category '$name' created successfully.";
            } catch (Exception $e) {
                $error = 'Error adding category: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'add_menu') {
        $name = trim($_POST['menu_name'] ?? '');
        $description = trim($_POST['menu_desc'] ?? '');
        $price = (float)($_POST['menu_price'] ?? 0.00);
        $category_id = (int)($_POST['category_id'] ?? 0);
        $is_available = isset($_POST['is_available']) ? 1 : 0;
        
        if (empty($name) || $price < 0 || $category_id <= 0) {
            $error = 'All fields (Name, Price, Category) are required and must be valid.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO menus (name, description, price, category_id, is_available)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $price, $category_id, $is_available]);
                log_audit_action($pdo, 'menu_add', "Added menu item: $name (Price: $price)");
                $message = "Menu item '$name' added successfully.";
            } catch (Exception $e) {
                $error = 'Error adding menu item: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'edit_menu') {
        $id = (int)($_POST['menu_id'] ?? 0);
        $name = trim($_POST['menu_name'] ?? '');
        $description = trim($_POST['menu_desc'] ?? '');
        $price = (float)($_POST['menu_price'] ?? 0.00);
        $category_id = (int)($_POST['category_id'] ?? 0);
        $is_available = isset($_POST['is_available']) ? 1 : 0;
        
        if ($id <= 0 || empty($name) || $price < 0 || $category_id <= 0) {
            $error = 'Invalid menu update data.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE menus 
                    SET name = ?, description = ?, price = ?, category_id = ?, is_available = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description, $price, $category_id, $is_available, $id]);
                log_audit_action($pdo, 'menu_update', "Updated menu item ID $id: $name (Price: $price)");
                $message = "Menu item '$name' updated successfully.";
            } catch (Exception $e) {
                $error = 'Error updating menu item: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'toggle_status') {
        $id = (int)($_POST['menu_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE menus SET is_available = NOT is_available WHERE id = ? RETURNING name, is_available");
                $stmt->execute([$id]);
                $res = $stmt->fetch();
                $status_str = $res['is_available'] ? 'Available' : 'Unavailable';
                log_audit_action($pdo, 'menu_toggle', "Toggled availability of item: {$res['name']} to $status_str");
                $message = "Toggled status of '{$res['name']}' to {$status_str}.";
            } catch (Exception $e) {
                $error = 'Error toggling availability: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all categories
$categories = [];
try {
    $categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $error = 'Error fetching categories: ' . $e->getMessage();
}

// Fetch all menus
$menus = [];
try {
    $menus = $pdo->query("
        SELECT m.id, m.name, m.description, m.price, m.is_available, c.name as category_name, c.id as category_id
        FROM menus m
        LEFT JOIN categories c ON m.category_id = c.id
        ORDER BY c.name ASC, m.name ASC
    ")->fetchAll();
} catch (Exception $e) {
    $error = 'Error fetching menu list: ' . $e->getMessage();
}

// Check if we are in Edit Menu mode
$edit_item = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($menus as $m) {
        if ($m['id'] === $edit_id) {
            $edit_item = $m;
            break;
        }
    }
}
?>

<h2>Menu & Catalog Management</h2>
<p>Configure cafe catalog products, categories, and availability.</p>

<?php if ($message): ?>
    <p style="color: green;"><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="display: flex; gap: 30px; flex-wrap: wrap;">
    <!-- Column Left: Category Setup -->
    <div style="border: 1px solid #ccc; padding: 20px; flex: 1; min-width: 280px; max-width: 400px;">
        <h3>Add Food/Drink Category</h3>
        <form method="POST" action="menu.php">
            <input type="hidden" name="action" value="add_category">
            <p>
                <label for="cat_name">Category Name:</label><br>
                <input type="text" id="cat_name" name="cat_name" placeholder="e.g., Desserts" required>
            </p>
            <p>
                <label for="cat_desc">Description:</label><br>
                <textarea id="cat_desc" name="cat_desc" rows="3" style="width: 100%;"></textarea>
            </p>
            <p>
                <button type="submit">Create Category</button>
            </p>
        </form>
        
        <h4>Existing Categories</h4>
        <ul>
            <?php foreach ($categories as $cat): ?>
                <li>
                    <strong><?php echo htmlspecialchars($cat['name']); ?></strong>
                    <?php if ($cat['description']): ?>
                        - <small style="color: #666;"><?php echo htmlspecialchars($cat['description']); ?></small>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
                <li>No categories created yet.</li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Column Center: Add/Edit Menu Item -->
    <div style="border: 1px solid #ccc; padding: 20px; flex: 1.2; min-width: 320px;">
        <?php if ($edit_item): ?>
            <h3>Edit Menu Item: <?php echo htmlspecialchars($edit_item['name']); ?></h3>
            <form method="POST" action="menu.php">
                <input type="hidden" name="action" value="edit_menu">
                <input type="hidden" name="menu_id" value="<?php echo $edit_item['id']; ?>">
                
                <p>
                    <label for="menu_name">Item Name:</label><br>
                    <input type="text" id="menu_name" name="menu_name" value="<?php echo htmlspecialchars($edit_item['name']); ?>" required>
                </p>
                <p>
                    <label for="menu_desc">Description:</label><br>
                    <textarea id="menu_desc" name="menu_desc" rows="3" style="width: 100%;"><?php echo htmlspecialchars($edit_item['description']); ?></textarea>
                </p>
                <p>
                    <label for="menu_price">Price (Rp):</label><br>
                    <input type="number" id="menu_price" name="menu_price" step="0.01" min="0" value="<?php echo htmlspecialchars($edit_item['price']); ?>" required>
                </p>
                <p>
                    <label for="category_id">Category:</label><br>
                    <select id="category_id" name="category_id" required>
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_item['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="is_available" value="1" <?php echo $edit_item['is_available'] ? 'checked' : ''; ?>>
                        <strong>In Stock / Available</strong>
                    </label>
                </p>
                <p>
                    <button type="submit">Update Menu Item</button>
                    <a href="menu.php">Cancel Edit</a>
                </p>
            </form>
        <?php else: ?>
            <h3>Add New Menu Item</h3>
            <form method="POST" action="menu.php">
                <input type="hidden" name="action" value="add_menu">
                <p>
                    <label for="menu_name">Item Name:</label><br>
                    <input type="text" id="menu_name" name="menu_name" required>
                </p>
                <p>
                    <label for="menu_desc">Description:</label><br>
                    <textarea id="menu_desc" name="menu_desc" rows="3" style="width: 100%;"></textarea>
                </p>
                <p>
                    <label for="menu_price">Price (Rp):</label><br>
                    <input type="number" id="menu_price" name="menu_price" step="0.01" min="0" required>
                </p>
                <p>
                    <label for="category_id">Category:</label><br>
                    <select id="category_id" name="category_id" required>
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="is_available" value="1" checked>
                        <strong>In Stock / Available</strong>
                    </label>
                </p>
                <p>
                    <button type="submit">Add Item</button>
                </p>
            </form>
        <?php endif; ?>
    </div>
</div>

<hr style="margin-top: 30px;">

<!-- Menu List Table -->
<h3>Menu Catalog List</h3>
<table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
    <thead>
        <tr style="background-color: #eee;">
            <th>ID</th>
            <th>Category</th>
            <th>Name</th>
            <th>Description</th>
            <th>Price</th>
            <th>Availability</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($menus as $m): ?>
            <tr>
                <td><?php echo $m['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($m['category_name'] ?: 'Uncategorized'); ?></strong></td>
                <td><strong><?php echo htmlspecialchars($m['name']); ?></strong></td>
                <td><small><?php echo htmlspecialchars($m['description'] ?: '-'); ?></small></td>
                <td>Rp <?php echo number_format($m['price'], 2, ',', '.'); ?></td>
                <td style="color: <?php echo $m['is_available'] ? 'green' : 'red'; ?>;">
                    <strong><?php echo $m['is_available'] ? 'Available' : 'Sold Out'; ?></strong>
                </td>
                <td>
                    <div style="display: inline-flex; gap: 10px;">
                        <a href="menu.php?edit=<?php echo $m['id']; ?>">Edit Details</a>
                        <form method="POST" action="menu.php" style="display:inline; margin:0;">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="menu_id" value="<?php echo $m['id']; ?>">
                            <button type="submit" style="font-size: 11px; cursor: pointer;">Toggle Status</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($menus)): ?>
            <tr>
                <td colspan="7" align="center">No menu items added yet.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php
require_once __DIR__ . '/footer.php';
?>

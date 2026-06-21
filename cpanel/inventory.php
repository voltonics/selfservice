<?php
require_once __DIR__ . '/auth_helper.php';

if (!has_permission('inventory.view') && !has_permission('inventory.update')) {
    require_permission('inventory.view');
}

require_once __DIR__ . '/header.php';

$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_item') {
        require_permission('inventory.update');
        $item_name = trim($_POST['item_name'] ?? '');
        $min_stock = (float)($_POST['min_stock'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');
        $initial_stock = (float)($_POST['initial_stock'] ?? 0);
        
        if (empty($item_name) || empty($unit) || $min_stock < 0 || $initial_stock < 0) {
            $error = 'All fields are required. Values must be non-negative.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Insert new inventory item
                $stmt = $pdo->prepare("
                    INSERT INTO inventory (item_name, stock, min_stock, unit)
                    VALUES (?, ?, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([$item_name, $initial_stock, $min_stock, $unit]);
                $item_id = $stmt->fetchColumn();
                
                // Write initial stock log if initial_stock > 0
                if ($initial_stock > 0) {
                    $log_stmt = $pdo->prepare("
                        INSERT INTO inventory_logs (item_id, type, quantity, description, user_id)
                        VALUES (?, 'in', ?, 'Initial stock setup', ?)
                    ");
                    $log_stmt->execute([$item_id, $initial_stock, $_SESSION['user_id']]);
                }
                
                $pdo->commit();
                log_audit_action($pdo, 'inventory_add_item', "Added inventory item: $item_name (Init stock: $initial_stock)");
                $message = "Inventory item '$item_name' added successfully.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error adding item: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'adjust_stock') {
        require_permission('inventory.update');
        $item_id = (int)($_POST['item_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $qty = (float)($_POST['qty'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        if ($item_id <= 0 || !in_array($type, ['in', 'out']) || $qty <= 0) {
            $error = 'Invalid adjustment inputs. Quantity must be greater than zero.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Fetch item current details
                $chk = $pdo->prepare("SELECT item_name, stock, unit FROM inventory WHERE id = ?");
                $chk->execute([$item_id]);
                $item = $chk->fetch();
                
                if ($item) {
                    // Update stock value
                    $new_stock = ($type === 'in') ? ($item['stock'] + $qty) : ($item['stock'] - $qty);
                    
                    if ($new_stock < 0) {
                        throw new Exception("Insufficient stock. Cannot adjust stock below 0.");
                    }
                    
                    // Update inventory record
                    $upd = $pdo->prepare("UPDATE inventory SET stock = ?, last_updated = NOW() WHERE id = ?");
                    $upd->execute([$new_stock, $item_id]);
                    
                    // Create stock transaction log
                    $log_stmt = $pdo->prepare("
                        INSERT INTO inventory_logs (item_id, type, quantity, description, user_id)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $log_stmt->execute([$item_id, $type, $qty, $description, $_SESSION['user_id']]);
                    
                    $pdo->commit();
                    log_audit_action($pdo, 'inventory_adjust', "Adjusted stock for {$item['item_name']} ($type): $qty {$item['unit']}");
                    $message = "Stock adjusted successfully. Current stock is now $new_stock {$item['unit']}.";
                } else {
                    $pdo->rollBack();
                    $error = 'Item not found.';
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Stock adjustment failed: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all inventory items
$inventory = [];
try {
    $inventory = $pdo->query("SELECT id, item_name, stock, min_stock, unit, last_updated FROM inventory ORDER BY item_name ASC")->fetchAll();
} catch (Exception $e) {
    $error = 'Error loading inventory list: ' . $e->getMessage();
}

// Fetch 15 recent inventory logs
$logs = [];
try {
    $logs = $pdo->query("
        SELECT il.id, il.type, il.quantity, il.description, il.created_at, i.item_name, i.unit, u.username
        FROM inventory_logs il
        LEFT JOIN inventory i ON il.item_id = i.id
        LEFT JOIN users u ON il.user_id = u.id
        ORDER BY il.created_at DESC
        LIMIT 15
    ")->fetchAll();
} catch (Exception $e) {
    $error = 'Error loading inventory logs: ' . $e->getMessage();
}
?>

<h2>Inventory & Stock Management</h2>
<p>Manage raw ingredients, monitor stock thresholds, and track log adjustments.</p>

<?php if ($message): ?>
    <p style="color: green;"><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="display: flex; gap: 30px; flex-wrap: wrap;">
    <!-- Left Column: Add New Raw Item Form (Inventory Update Permission required) -->
    <?php if (has_permission('inventory.update')): ?>
        <div style="border: 1px solid #ccc; padding: 20px; flex: 1; min-width: 280px; max-width: 380px;">
            <h3>Register New Material Item</h3>
            <form method="POST" action="inventory.php">
                <input type="hidden" name="action" value="add_item">
                <p>
                    <label for="item_name">Item Name:</label><br>
                    <input type="text" id="item_name" name="item_name" placeholder="e.g. Cocoa Powder" required>
                </p>
                <p>
                    <label for="initial_stock">Initial Stock:</label><br>
                    <input type="number" id="initial_stock" name="initial_stock" step="0.01" value="0.00" min="0" required>
                </p>
                <p>
                    <label for="min_stock">Minimum Stock Limit:</label><br>
                    <input type="number" id="min_stock" name="min_stock" step="0.01" value="5.00" min="0" required>
                </p>
                <p>
                    <label for="unit">Stock Unit:</label><br>
                    <input type="text" id="unit" name="unit" placeholder="e.g. kg, Liters, pcs" required>
                </p>
                <p>
                    <button type="submit">Add New Item</button>
                </p>
            </form>

            <hr>

            <h3>Register Stock In / Out Adjustment</h3>
            <form method="POST" action="inventory.php">
                <input type="hidden" name="action" value="adjust_stock">
                <p>
                    <label for="item_id">Select Item:</label><br>
                    <select id="item_id" name="item_id" required style="width: 100%;">
                        <option value="">-- Choose Item --</option>
                        <?php foreach ($inventory as $item): ?>
                            <option value="<?php echo $item['id']; ?>">
                                <?php echo htmlspecialchars($item['item_name']); ?> (Current: <?php echo $item['stock']; ?> <?php echo htmlspecialchars($item['unit']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="type">Adjustment Type:</label><br>
                    <select id="type" name="type" required>
                        <option value="in">Stock IN (Restock/Addition)</option>
                        <option value="out">Stock OUT (Usage/Loss)</option>
                    </select>
                </p>
                <p>
                    <label for="qty">Quantity:</label><br>
                    <input type="number" id="qty" name="qty" step="0.01" min="0.01" required>
                </p>
                <p>
                    <label for="description">Reason/Description:</label><br>
                    <input type="text" id="description" name="description" placeholder="e.g. Monthly supplier purchase" required style="width: 95%;">
                </p>
                <p>
                    <button type="submit">Submit Adjustment</button>
                </p>
            </form>
        </div>
    <?php endif; ?>

    <!-- Center/Right Column: Inventory Stock Listing -->
    <div style="border: 1px solid #ccc; padding: 20px; flex: 2; min-width: 450px;">
        <h3>Current Stock Levels</h3>
        <table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
            <thead>
                <tr style="background-color: #eee;">
                    <th>ID</th>
                    <th>Item Name</th>
                    <th>Current Stock</th>
                    <th>Min Limit</th>
                    <th>Unit</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory as $item): 
                    $is_low = $item['stock'] <= $item['min_stock'];
                ?>
                    <tr style="<?php echo $is_low ? 'background-color: #fff2f2;' : ''; ?>">
                        <td><?php echo $item['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                        <td style="font-weight: bold; color: <?php echo $is_low ? 'red' : 'green'; ?>;">
                            <?php echo $item['stock']; ?>
                        </td>
                        <td><?php echo $item['min_stock']; ?></td>
                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                        <td>
                            <strong style="color: <?php echo $is_low ? 'red' : 'green'; ?>;">
                                <?php echo $is_low ? 'Low Stock ⚠️' : 'Ok ✓'; ?>
                            </strong>
                        </td>
                        <td><small><?php echo htmlspecialchars($item['last_updated']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($inventory)): ?>
                    <tr>
                        <td colspan="7" align="center">No inventory items registered yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<hr style="margin-top: 30px;">

<!-- Inventory Adjustment Logs -->
<h3>Stock Movement Logs (History)</h3>
<table border="1" cellpadding="6" cellspacing="0" style="width: 100%;">
    <thead>
        <tr style="background-color: #eee;">
            <th>Date & Time</th>
            <th>Item</th>
            <th>Type</th>
            <th>Quantity</th>
            <th>Description</th>
            <th>Authorized By</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><small><?php echo htmlspecialchars($log['created_at']); ?></small></td>
                <td><strong><?php echo htmlspecialchars($log['item_name']); ?></strong></td>
                <td style="font-weight: bold; color: <?php echo ($log['type'] === 'in') ? 'green' : 'red'; ?>;">
                    <?php echo strtoupper($log['type']); ?>
                </td>
                <td><?php echo htmlspecialchars($log['quantity']); ?> <?php echo htmlspecialchars($log['unit']); ?></td>
                <td><?php echo htmlspecialchars($log['description'] ?: '-'); ?></td>
                <td><strong><?php echo htmlspecialchars($log['username'] ?: 'System'); ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
            <tr>
                <td colspan="6" align="center">No inventory logs recorded.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php
require_once __DIR__ . '/footer.php';
?>

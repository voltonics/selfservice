<?php
require_once __DIR__ . '/auth_helper.php';

if (!has_permission('kitchen.view') && !has_permission('kitchen.update')) {
    require_permission('kitchen.view');
}

require_once __DIR__ . '/header.php';

$message = '';
$error = '';

// Handle POST request for status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    require_permission('kitchen.update');
    
    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    
    $valid_statuses = ['pending', 'preparing', 'ready', 'completed'];
    
    if ($order_id > 0 && in_array($new_status, $valid_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? RETURNING invoice_number");
            $stmt->execute([$new_status, $order_id]);
            $invoice = $stmt->fetchColumn();
            
            log_audit_action($pdo, 'kitchen_status', "Updated order $invoice status to $new_status");
            $message = "Order $invoice updated to status: " . strtoupper($new_status);
        } catch (Exception $e) {
            $error = "Failed to update order status: " . $e->getMessage();
        }
    } else {
        $error = "Invalid order status update request.";
    }
}

// Fetch all active orders (status != completed, sorted by oldest first)
$active_orders = [];
try {
    // Automatically fail any pending orders that have expired (older than 10 minutes)
    $pdo->exec("
        UPDATE orders 
        SET payment_status = 'failed', status = 'completed' 
        WHERE payment_status = 'pending' AND created_at < NOW() - INTERVAL '10 minutes'
    ");

    $stmt = $pdo->query("
        SELECT o.id, o.invoice_number, o.customer_name, o.status, o.created_at, o.payment_status, EXTRACT(EPOCH FROM o.created_at) AS created_epoch
        FROM orders o
        WHERE o.status IN ('pending', 'preparing') AND o.payment_status = 'paid'
        ORDER BY o.created_at ASC
    ");
    $active_orders = $stmt->fetchAll();
    
    // For each active order, fetch its items
    if (count($active_orders) > 0) {
        $order_ids = array_column($active_orders, 'id');
        // PostgreSQL IN query placeholders
        $in_clause = implode(',', array_fill(0, count($order_ids), '?'));
        
        $item_stmt = $pdo->prepare("
            SELECT oi.order_id, oi.quantity, oi.notes, m.name as menu_name
            FROM order_items oi
            LEFT JOIN menus m ON oi.menu_id = m.id
            WHERE oi.order_id IN ($in_clause)
        ");
        $item_stmt->execute($order_ids);
        $all_items = $item_stmt->fetchAll();
        
        // Group items by order_id
        $items_by_order = [];
        foreach ($all_items as $item) {
            $items_by_order[$item['order_id']][] = $item;
        }
    }
} catch (Exception $e) {
    $error = "Error fetching kitchen orders: " . $e->getMessage();
}
?>

<h2>Kitchen Production Display Queue</h2>
<p>Monitor food & beverage production flows and update order preparation steps.</p>

<?php if ($message): ?>
    <p style="color: green;"><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
    <?php if (empty($active_orders)): ?>
        <p style="padding: 40px; border: 1px dashed #ccc; width: 100%; text-align: center; color: #666;">
            All orders prepared! The kitchen queue is currently empty.
        </p>
    <?php else: ?>
        <?php foreach ($active_orders as $order): ?>
            <!-- Order Card -->
            <div style="border: 2px solid <?php 
                echo ($order['status'] === 'pending') ? 'red' : (($order['status'] === 'preparing') ? 'orange' : 'green'); 
            ?>; padding: 15px; width: 280px; background-color: #fafafa; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <strong><?php echo htmlspecialchars($order['invoice_number']); ?></strong>
                    <span style="font-size: 11px; color: #666;"><?php echo date('H:i', (int)$order['created_epoch']); ?></span>
                </div>
                <p style="margin: 5px 0;">Customer: <strong><?php echo htmlspecialchars($order['customer_name'] ?: 'Guest'); ?></strong></p>
                <p style="margin: 5px 0;">Payment: 
                    <strong style="color: <?php echo $order['payment_status'] === 'paid' ? 'green' : 'red'; ?>;">
                        <?php echo strtoupper($order['payment_status']); ?>
                    </strong>
                </p>
                
                <hr>
                
                <h4>Items Ordered:</h4>
                <ul style="padding-left: 20px; margin: 10px 0;">
                    <?php 
                    $order_items = $items_by_order[$order['id']] ?? [];
                    foreach ($order_items as $item): 
                    ?>
                        <li style="margin-bottom: 8px;">
                            <strong><?php echo $item['quantity']; ?>x</strong> <?php echo htmlspecialchars($item['menu_name']); ?>
                            <?php if ($item['notes']): ?>
                                <br><small style="color: #c55; font-style: italic;">(Note: <?php echo htmlspecialchars($item['notes']); ?>)</small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <hr>
                
                <div style="margin-top: 10px; text-align: center;">
                    <p style="margin-bottom: 8px;">Status: <strong><?php echo strtoupper($order['status']); ?></strong></p>
                    
                    <?php if (has_permission('kitchen.update')): ?>
                        <form method="POST" action="kitchen.php" style="margin:0;">
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            
                            <?php if ($order['status'] === 'pending'): ?>
                                <input type="hidden" name="status" value="preparing">
                                <button type="submit" name="update_status" style="width: 100%; padding: 8px; background-color: #ffe8d6; cursor: pointer; font-weight: bold;">
                                    Start Preparing &rarr;
                                </button>
                            <?php elseif ($order['status'] === 'preparing'): ?>
                                <input type="hidden" name="status" value="ready">
                                <button type="submit" name="update_status" style="width: 100%; padding: 8px; background-color: #d8f3dc; cursor: pointer; font-weight: bold; color: green;">
                                    Mark as Ready &rarr;
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <small style="color: gray;">Read-only view</small>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>

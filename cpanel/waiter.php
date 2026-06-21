<?php
require_once __DIR__ . '/auth_helper.php';

if (!has_permission('waiter.view') && !has_permission('waiter.update')) {
    require_permission('waiter.view');
}

require_once __DIR__ . '/header.php';

$message = '';
$error = '';

// Handle POST request for marking as served
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_served'])) {
    require_permission('waiter.update');
    
    $order_id = (int)($_POST['order_id'] ?? 0);
    
    if ($order_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ? AND status = 'ready' RETURNING invoice_number");
            $stmt->execute([$order_id]);
            $invoice = $stmt->fetchColumn();
            
            if ($invoice) {
                log_audit_action($pdo, 'waiter_serve', "Marked order $invoice as served");
                $message = "Order $invoice has been marked as served.";
            } else {
                $error = "Order not found or not in READY status.";
            }
        } catch (Exception $e) {
            $error = "Failed to update status: " . $e->getMessage();
        }
    } else {
        $error = "Invalid order ID.";
    }
}

// Fetch all paid orders with status = 'ready' (oldest first)
$ready_orders = [];
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
        WHERE o.status = 'ready' AND o.payment_status = 'paid'
        ORDER BY o.created_at ASC
    ");
    $ready_orders = $stmt->fetchAll();
    
    // Fetch items for the ready orders
    if (count($ready_orders) > 0) {
        $order_ids = array_column($ready_orders, 'id');
        $in_clause = implode(',', array_fill(0, count($order_ids), '?'));
        
        $item_stmt = $pdo->prepare("
            SELECT oi.order_id, oi.quantity, oi.notes, m.name as menu_name
            FROM order_items oi
            LEFT JOIN menus m ON oi.menu_id = m.id
            WHERE oi.order_id IN ($in_clause)
        ");
        $item_stmt->execute($order_ids);
        $all_items = $item_stmt->fetchAll();
        
        $items_by_order = [];
        foreach ($all_items as $item) {
            $items_by_order[$item['order_id']][] = $item;
        }
    }
} catch (Exception $e) {
    $error = "Error loading waiter delivery list: " . $e->getMessage();
}
?>

<h2>Waiter Delivery Queue</h2>
<p>Monitor ready orders, track target tables, and mark items as served.</p>

<?php if ($message): ?>
    <p style="color: green;"><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
    <?php if (empty($ready_orders)): ?>
        <p style="padding: 40px; border: 1px dashed #ccc; width: 100%; text-align: center; color: #666;">
            All orders delivered! The waiter queue is currently empty.
        </p>
    <?php else: ?>
        <?php foreach ($ready_orders as $order): ?>
            <?php
            // Extract customer name and table number if stored as "Name (Table X)"
            $raw_name = $order['customer_name'] ?: 'Guest';
            $customer_name_display = $raw_name;
            $table_display = 'Counter Pickup';
            
            if (preg_match('/^(.*?)\s*\(Table\s+(\d+)\)$/i', $raw_name, $matches)) {
                $customer_name_display = trim($matches[1]);
                $table_display = 'Table ' . $matches[2];
            }
            
            $time_formatted = date('H:i', (int)$order['created_epoch']);
            ?>
            <!-- Order Card -->
            <div style="border: 2px solid green; padding: 15px; width: 280px; background-color: #fafafa; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <strong><?php echo htmlspecialchars($order['invoice_number']); ?></strong>
                    <span style="font-size: 11px; color: #666;"><?php echo $time_formatted; ?></span>
                </div>
                <p style="margin: 5px 0;">Customer: <strong><?php echo htmlspecialchars($customer_name_display); ?></strong></p>
                <p style="margin: 5px 0;">Table: <strong><?php echo htmlspecialchars($table_display); ?></strong></p>
                
                <hr>
                
                <h4>Items to Deliver:</h4>
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
                    <?php if (has_permission('waiter.update')): ?>
                        <form method="POST" action="waiter.php" style="margin: 0;">
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <button type="submit" name="mark_served" style="width: 100%; padding: 8px; background-color: #caf0f8; cursor: pointer; font-weight: bold; color: blue;">
                                Mark as Served
                            </button>
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

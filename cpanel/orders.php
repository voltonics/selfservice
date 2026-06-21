<?php
require_once __DIR__ . '/auth_helper.php';

// Allow access to Manager, Owner, Super Admin (anyone with order.update or reports.view)
if (!has_permission('order.update') && !has_permission('reports.view')) {
    require_permission('reports.view');
}

require_once __DIR__ . '/header.php';

$message = '';
$error = '';

// Handle POST actions (Admin manual status updates)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_paid') {
        require_permission('order.update');
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'paid', status = 'preparing' WHERE id = ? RETURNING invoice_number");
                $stmt->execute([$order_id]);
                $invoice = $stmt->fetchColumn();
                log_audit_action($pdo, 'order_payment_manual', "Manually marked order $invoice as PAID");
                $message = "Order $invoice manually marked as PAID.";
            } catch (Exception $e) {
                $error = "Payment update failed: " . $e->getMessage();
            }
        }
    }
    
    if ($action === 'cancel_order') {
        require_permission('order.update');
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE orders SET status = 'completed', payment_status = 'failed' WHERE id = ? RETURNING invoice_number");
                $stmt->execute([$order_id]);
                $invoice = $stmt->fetchColumn();
                log_audit_action($pdo, 'order_cancel_manual', "Manually marked order $invoice as FAILED / CANCELLED");
                $message = "Order $invoice marked as FAILED.";
            } catch (Exception $e) {
                $error = "Cancellation failed: " . $e->getMessage();
            }
        }
    }
}

// Fetch today's transactions
$orders = [];
try {
    // Automatically fail any pending orders that have expired (older than 10 minutes)
    $pdo->exec("
        UPDATE orders 
        SET payment_status = 'failed', status = 'completed' 
        WHERE payment_status = 'pending' AND created_at < NOW() - INTERVAL '10 minutes'
    ");

    $orders = $pdo->query("
        SELECT o.id, o.invoice_number, o.customer_name, o.total_amount, o.payment_status, o.status, o.created_at
        FROM orders o
        WHERE o.created_at >= CURRENT_DATE
        ORDER BY o.created_at DESC
    ")->fetchAll();
    
    // Fetch items for each order
    if (count($orders) > 0) {
        $order_ids = array_column($orders, 'id');
        $in_clause = implode(',', array_fill(0, count($order_ids), '?'));
        
        $item_stmt = $pdo->prepare("
            SELECT oi.order_id, oi.quantity, oi.price, oi.notes, m.name as menu_name
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
    $error = "Error loading transactions: " . $e->getMessage();
}
?>

<h2>Track Orders & Transactions</h2>
<p>Monitor live self-service customer orders and update payment statuses manually if needed.</p>

<?php if ($message): ?>
    <p style="color: green;"><strong>Success:</strong> <?php echo $message; ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<!-- Today's Orders List Table -->
<div style="border: 1px solid #ccc; padding: 20px; background-color: #fafafa;">
    <h3>Today's Live Orders</h3>
    <table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
        <thead>
            <tr style="background-color: #eee;">
                <th>Invoice</th>
                <th>Order Date & Time</th>
                <th>Customer & Table</th>
                <th>Ordered Items Details</th>
                <th>Total Bill</th>
                <th>Payment Status</th>
                <th>Kitchen Status</th>
                <th>Admin Actions Override</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $ord): ?>
                <tr>
                    <td><strong><code><?php echo htmlspecialchars($ord['invoice_number']); ?></code></strong></td>
                    <td><small><?php echo htmlspecialchars($ord['created_at']); ?></small></td>
                    <td><strong><?php echo htmlspecialchars($ord['customer_name'] ?: '-'); ?></strong></td>
                    <td>
                        <ul style="margin: 0; padding-left: 15px;">
                            <?php 
                            $order_items = $items_by_order[$ord['id']] ?? [];
                            foreach ($order_items as $item): 
                            ?>
                                <li>
                                    <?php echo $item['quantity']; ?>x <?php echo htmlspecialchars($item['menu_name']); ?> 
                                    <small style="color: #666;">(Rp <?php echo number_format($item['price'], 0, ',', '.'); ?>)</small>
                                    <?php if ($item['notes']): ?>
                                        <br><small style="color: #933; font-style: italic;">* Note: <?php echo htmlspecialchars($item['notes']); ?></small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                    <td><strong>Rp <?php echo number_format($ord['total_amount'], 2, ',', '.'); ?></strong></td>
                    <td align="center">
                        <span style="font-weight: bold; color: <?php 
                            echo ($ord['payment_status'] === 'paid') ? 'green' : (($ord['payment_status'] === 'failed') ? 'red' : 'orange'); 
                        ?>;">
                            <?php echo strtoupper($ord['payment_status']); ?>
                        </span>
                    </td>
                    <td align="center">
                        <span style="font-weight: bold; color: <?php 
                            echo ($ord['status'] === 'completed') ? 'blue' : (($ord['status'] === 'ready') ? 'green' : 'orange'); 
                        ?>;">
                            <?php echo strtoupper($ord['status']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (has_permission('order.update')): ?>
                            <div style="display: flex; gap: 5px; justify-content: center;">
                                <?php if ($ord['payment_status'] === 'pending'): ?>
                                    <form method="POST" action="orders.php" style="margin:0;">
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="order_id" value="<?php echo $ord['id']; ?>">
                                        <button type="submit" style="background-color:#dfd; cursor:pointer;">Verify Paid</button>
                                    </form>
                                    
                                    <form method="POST" action="orders.php" style="margin:0;">
                                        <input type="hidden" name="action" value="cancel_order">
                                        <input type="hidden" name="order_id" value="<?php echo $ord['id']; ?>">
                                        <button type="submit" style="background-color:#fdd; color:red; cursor:pointer;">Cancel/Fail</button>
                                    </form>
                                <?php elseif ($ord['payment_status'] === 'paid' && $ord['status'] !== 'completed'): ?>
                                    <form method="POST" action="orders.php" style="margin:0;">
                                        <input type="hidden" name="action" value="cancel_order">
                                        <input type="hidden" name="order_id" value="<?php echo $ord['id']; ?>">
                                        <button type="submit" style="background-color:#fdd; color:red; cursor:pointer;">Cancel/Refund</button>
                                    </form>
                                <?php else: ?>
                                    <small style="color: gray;">Closed</small>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <small style="color: gray;">Read-only</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="8" align="center">No transactions recorded today yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>

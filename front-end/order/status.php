<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../cpanel/config.php';

$invoice = $_GET['invoice'] ?? '';

if (empty($invoice)) {
    die("<h2>Error: Invoice number is missing.</h2>");
}

try {
    // Automatically fail any pending orders that have expired (older than 10 minutes)
    $pdo->exec("
        UPDATE orders 
        SET payment_status = 'failed', status = 'completed' 
        WHERE payment_status = 'pending' AND created_at < NOW() - INTERVAL '10 minutes'
    ");

    $stmt = $pdo->prepare("
        SELECT o.id, o.invoice_number, o.customer_name, o.total_amount, o.payment_status, o.status, o.created_at
        FROM orders o
        WHERE o.invoice_number = ?
    ");
    $stmt->execute([$invoice]);
    $order = $stmt->fetch();
    
    if (!$order) {
        die("<h2>Error: Order not found.</h2>");
    }
    
    // Check if pending has expired
    $created_time = strtotime($order['created_at']);
    $expiry_time = $created_time + 600;
    $time_left = $expiry_time - time();
    $is_expired = ($time_left <= 0);
    
    // Automatically update expired to failed on display
    if ($is_expired && $order['payment_status'] === 'pending') {
        $upd = $pdo->prepare("UPDATE orders SET payment_status = 'failed', status = 'completed' WHERE id = ?");
        $upd->execute([$order['id']]);
        $order['payment_status'] = 'failed';
        $order['status'] = 'completed';
    }
    
} catch (Exception $e) {
    die("<h2>Database Error: " . htmlspecialchars($e->getMessage()) . "</h2>");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Order Status - Cafe Order</title>
</head>
<body>
    <h1>Order Status Tracking</h1>
    <p>Check the live status of your order and payment below.</p>
    <p><a href="/front-end/order/index.php">Place Another Order</a></p>
    
    <hr>
    
    <div style="border: 1px solid #ccc; padding: 20px; max-width: 500px; background-color: #fafafa;">
        <h3>Order Invoice: <code><?php echo htmlspecialchars($order['invoice_number']); ?></code></h3>
        <p>Customer & Table: <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong></p>
        <p>Total Paid Amount: <strong>Rp <?php echo number_format($order['total_amount'], 0, ',', '.'); ?></strong></p>
        
        <hr>
        
        <h4>1. Payment Status:</h4>
        <p style="font-size: 18px;">
            <?php if ($order['payment_status'] === 'paid'): ?>
                <span style="color: green; font-weight: bold;">PAID (Verified)</span>
            <?php elseif ($order['payment_status'] === 'failed'): ?>
                <span style="color: red; font-weight: bold;">FAILED / EXPIRED</span>
                <p><small style="color: gray;">Your payment window has expired. If you already transferred after 10 minutes, please contact the restaurant staff directly with your proof of payment.</small></p>
            <?php else: ?>
                <span style="color: orange; font-weight: bold;">PENDING PAYMENT</span><br>
                <a href="/front-end/order/payment.php?invoice=<?php echo urlencode($order['invoice_number']); ?>">Go to Payment Screen &rarr;</a>
            <?php endif; ?>
        </p>

        <h4>2. Kitchen Preparation Status:</h4>
        <p style="font-size: 18px; font-weight: bold;">
            <?php if ($order['payment_status'] === 'paid'): ?>
                <?php if ($order['status'] === 'pending'): ?>
                    <span style="color: orange;">Awaiting Kitchen Queue</span>
                <?php elseif ($order['status'] === 'preparing'): ?>
                    <span style="color: orange;">Preparing in Kitchen</span>
                <?php elseif ($order['status'] === 'ready'): ?>
                    <span style="color: green;">READY FOR PICKUP!</span>
                <?php elseif ($order['status'] === 'completed'): ?>
                    <span style="color: blue;">SERVED & COMPLETED</span>
                <?php endif; ?>
            <?php elseif ($order['payment_status'] === 'failed'): ?>
                <span style="color: red;">Cancelled</span>
            <?php else: ?>
                <span style="color: gray;">Awaiting Payment Confirmation</span>
            <?php endif; ?>
        </p>

        <hr style="margin-top: 20px;">
        
        <div style="text-align: center;">
            <button onclick="location.reload();" style="padding: 10px 20px; font-size: 16px; font-weight: bold; cursor: pointer;">
                Refresh Status
            </button>
            <br>
            <small style="color: #666;">Last checked: <?php echo date('H:i:s'); ?></small>
        </div>
    </div>
</body>
</html>

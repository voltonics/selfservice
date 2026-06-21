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

    // Fetch order details
    $stmt = $pdo->prepare("SELECT id, invoice_number, customer_name, total_amount, payment_status, status, created_at FROM orders WHERE invoice_number = ?");
    $stmt->execute([$invoice]);
    $order = $stmt->fetch();
    
    if (!$order) {
        die("<h2>Error: Order with invoice " . htmlspecialchars($invoice) . " not found.</h2>");
    }
    
    // Check timeout: 10 minutes (600 seconds) limit
    $created_time = strtotime($order['created_at']);
    $expiry_time = $created_time + 600;
    $time_left = $expiry_time - time();
    $is_expired = ($time_left <= 0);
    
    // If expired and still pending/unpaid, automatically update the status to failed in the database
    if ($is_expired && $order['payment_status'] === 'pending') {
        $upd = $pdo->prepare("UPDATE orders SET payment_status = 'failed', status = 'completed' WHERE id = ?");
        $upd->execute([$order['id']]);
        $order['payment_status'] = 'failed';
        $order['status'] = 'completed';
    }
    
    // Check if order is already processed (paid or failed)
    $payment_done = ($order['payment_status'] === 'paid');
    $payment_failed = ($order['payment_status'] === 'failed');
    
} catch (Exception $e) {
    die("<h2>Database Error: " . htmlspecialchars($e->getMessage()) . "</h2>");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payment Checkout - Cafe Order</title>
</head>
<body>
    <h1>Online Payment Checkout</h1>
    <p>Complete your payment using your preferred payment method.</p>
    <p><a href="/front-end/order/index.php">&larr; Back to Menu Selection</a></p>
    
    <hr>
    
    <div style="display: flex; gap: 40px; flex-wrap: wrap;">
        <!-- Left: Order Details & Timer -->
        <div style="flex: 1.5; min-width: 320px; border: 1px solid #ccc; padding: 20px;">
            <h2>Invoice Details</h2>
            <table border="1" cellpadding="6" cellspacing="0" style="width: 100%;">
                <tr>
                    <td><strong>Invoice Number:</strong></td>
                    <td><code><?php echo htmlspecialchars($order['invoice_number']); ?></code></td>
                </tr>
                <tr>
                    <td><strong>Customer & Table:</strong></td>
                    <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                </tr>
                <tr>
                    <td><strong>Grand Total:</strong></td>
                    <td><strong>Rp <?php echo number_format($order['total_amount'], 2, ',', '.'); ?></strong></td>
                </tr>
                <tr>
                    <td><strong>Order Created At:</strong></td>
                    <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                </tr>
                <tr>
                    <td><strong>Current Payment Status:</strong></td>
                    <td style="font-weight: bold; color: <?php 
                        echo $payment_done ? 'green' : ($payment_failed ? 'red' : 'orange'); 
                    ?>;">
                        <?php echo strtoupper($order['payment_status']); ?>
                    </td>
                </tr>
            </table>

            <!-- Countdown Timer -->
            <?php if (!$payment_done && !$payment_failed && !$is_expired): ?>
                <div style="border: 2px solid red; background-color: #fee; padding: 15px; margin-top: 20px; text-align: center;">
                    <h3 style="margin: 0; color: red;">TIME REMAINING TO PAY</h3>
                    <p style="font-size: 24px; font-weight: bold; margin: 10px 0;" id="timer">10:00</p>
                    <small>Please complete the transfer before the timer runs out. If the timer expires, this invoice becomes invalid.</small>
                </div>
                
                <script>
                    var secondsLeft = <?php echo $time_left; ?>;
                    var timerDisplay = document.getElementById('timer');
                    
                    function updateTimer() {
                        var minutes = Math.floor(secondsLeft / 60);
                        var seconds = secondsLeft % 60;
                        
                        timerDisplay.innerText = 
                            (minutes < 10 ? "0" + minutes : minutes) + ":" + 
                            (seconds < 10 ? "0" + seconds : seconds);
                        
                        if (secondsLeft <= 0) {
                            clearInterval(timerInterval);
                            alert("Time is up! This payment session has expired.");
                            location.reload();
                        }
                        secondsLeft--;
                    }
                    
                    updateTimer();
                    var timerInterval = setInterval(updateTimer, 1000);
                </script>
            <?php elseif ($is_expired || $payment_failed): ?>
                <div style="border: 2px solid gray; background-color: #eee; padding: 15px; margin-top: 20px; text-align: center;">
                    <h3 style="margin: 0; color: red;">INVOICE EXPIRED / INVALID</h3>
                    <p style="font-weight: bold; margin: 10px 0;">This transaction has expired or failed.</p>
                    <p style="color: #666; font-size: 13px;"><small>Transfers made after the 10-minute limit will not be processed. The restaurant is not responsible for delayed payments.</small></p>
                </div>
            <?php else: ?>
                <div style="border: 2px solid green; background-color: #efe; padding: 15px; margin-top: 20px; text-align: center;">
                    <h3 style="margin: 0; color: green;">PAYMENT RECEIVED</h3>
                    <p style="font-weight: bold; margin: 10px 0;">This invoice has been settled successfully.</p>
                    <p><a href="/front-end/order/status.php?invoice=<?php echo urlencode($invoice); ?>">View Kitchen Preparation Status &rarr;</a></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: Payment Methods Selection -->
        <div style="flex: 1.5; min-width: 320px; border: 1px solid #ccc; padding: 20px; position: relative;">
            
            <!-- Disabled Overlay if Expired or Paid -->
            <?php if ($payment_done || $payment_failed || $is_expired): ?>
                <div style="position: absolute; top:0; left:0; width:100%; height:100%; background: rgba(255,255,255,0.7); z-index:10; display:flex; align-items:center; justify-content:center;">
                    <div style="border: 2px solid black; padding: 15px; background: white; font-weight: bold; text-align: center;">
                        <?php echo $payment_done ? "PAYMENT SETTLED" : "PAYMENT WINDOW LOCKED"; ?>
                    </div>
                </div>
            <?php endif; ?>

            <h2>Choose Payment Method</h2>
            
            <div style="margin-bottom: 20px; border: 1px solid #ddd; padding: 10px;">
                <h3>Option 1: QRIS QR Code</h3>
                <div style="border: 1px dashed black; width: 150px; height: 150px; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; background-color: #fafafa;">
                    <strong>[ SIMULATED QRIS ]</strong>
                </div>
                <small>Scan this QR code using GoPay, OVO, Dana, LinkAja, or ShopeePay.</small>
            </div>

            <div style="margin-bottom: 20px; border: 1px solid #ddd; padding: 10px;">
                <h3>Option 2: Bank Transfer (VA)</h3>
                <p>Bank: <strong>NEON BANK</strong></p>
                <p>Virtual Account Number: <code>8882736183921</code></p>
                <small>Make a bank transfer to this VA number from any mobile banking app.</small>
            </div>

            <div style="margin-bottom: 20px; border: 1px solid #ddd; padding: 10px;">
                <h3>Option 3: E-Wallets</h3>
                <p>Choose: GoPay | ShopeePay | OVO | Dana</p>
                <small>Simulate payment notification directly below.</small>
            </div>

            <hr>

            <h2>Payment Gateway Simulator (Webhook triggers)</h2>
            <p>Since we are in development/simulation mode, click below to trigger payment gateway API callbacks:</p>
            
            <div style="display: flex; gap: 10px;">
                <!-- Simulate Success Webhook Form -->
                <form method="POST" action="../../cpanel/webhook.php">
                    <input type="hidden" name="invoice" value="<?php echo htmlspecialchars($invoice); ?>">
                    <input type="hidden" name="status" value="success">
                    <button type="submit" style="background-color: #efe; color: green; font-weight: bold; padding: 10px 15px; cursor: pointer;">
                        Simulate Payment Success
                    </button>
                </form>

                <!-- Simulate Failure Webhook Form -->
                <form method="POST" action="../../cpanel/webhook.php">
                    <input type="hidden" name="invoice" value="<?php echo htmlspecialchars($invoice); ?>">
                    <input type="hidden" name="status" value="failed">
                    <button type="submit" style="background-color: #fee; color: red; padding: 10px 15px; cursor: pointer;">
                        Simulate Payment Failure
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>

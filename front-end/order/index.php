<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database configuration
require_once __DIR__ . '/../../cpanel/config.php';

$message = '';
$error = '';

// Initialize Cart Session
if (!isset($_SESSION['cust_cart']) || !is_array($_SESSION['cust_cart'])) {
    $_SESSION['cust_cart'] = [];
}

// Handle POST actions (Cart management and checkout)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_to_cart') {
        $menu_id = (int)($_POST['menu_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($menu_id > 0 && $qty > 0) {
            try {
                // Fetch menu details
                $stmt = $pdo->prepare("SELECT id, name, price, is_available FROM menus WHERE id = ?");
                $stmt->execute([$menu_id]);
                $item = $stmt->fetch();
                
                if ($item && $item['is_available']) {
                    if (isset($_SESSION['cust_cart'][$menu_id])) {
                        $_SESSION['cust_cart'][$menu_id]['qty'] += $qty;
                        if (!empty($notes)) {
                            $_SESSION['cust_cart'][$menu_id]['notes'] .= "; " . $notes;
                        }
                    } else {
                        $_SESSION['cust_cart'][$menu_id] = [
                            'name' => $item['name'],
                            'price' => (float)$item['price'],
                            'qty' => $qty,
                            'notes' => $notes
                        ];
                    }
                    $message = "Added '" . htmlspecialchars($item['name']) . "' to your selection.";
                } else {
                    $error = "Item is currently sold out or does not exist.";
                }
            } catch (Exception $e) {
                $error = "Cart error: " . $e->getMessage();
            }
        }
    }
    
    if ($action === 'remove') {
        $menu_id = (int)($_POST['menu_id'] ?? 0);
        if (isset($_SESSION['cust_cart'][$menu_id])) {
            $name = $_SESSION['cust_cart'][$menu_id]['name'];
            unset($_SESSION['cust_cart'][$menu_id]);
            $message = "Removed '" . htmlspecialchars($name) . "' from your selection.";
        }
    }
    
    if ($action === 'clear') {
        $_SESSION['cust_cart'] = [];
        $message = "Selections cleared.";
    }
    
    if ($action === 'checkout') {
        $customer_name = trim($_POST['customer_name'] ?? '');
        $table_number = trim($_POST['table_number'] ?? '');
        
        if (empty($customer_name) || empty($table_number)) {
            $error = "Please fill in both your Name and Table Number.";
        } elseif (empty($_SESSION['cust_cart'])) {
            $error = "Your cart is empty. Please choose some menus first.";
        } else {
            try {
                $pdo->beginTransaction();
                
                $total_amount = 0.00;
                foreach ($_SESSION['cust_cart'] as $item) {
                    $total_amount += $item['price'] * $item['qty'];
                }
                
                // Format invoice number
                $invoice = "INV-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -5));
                
                // Customer details string format: Name (Table X)
                $cust_details = $customer_name . " (Table " . $table_number . ")";
                
                // Insert order as pending
                $stmt = $pdo->prepare("
                    INSERT INTO orders (invoice_number, customer_name, cashier_id, total_amount, payment_status, status, created_at)
                    VALUES (?, ?, NULL, ?, 'pending', 'pending', NOW())
                    RETURNING id
                ");
                $stmt->execute([$invoice, $cust_details, $total_amount]);
                $order_id = $stmt->fetchColumn();
                
                // Insert order items
                $item_stmt = $pdo->prepare("
                    INSERT INTO order_items (order_id, menu_id, quantity, price, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($_SESSION['cust_cart'] as $m_id => $cart_item) {
                    $item_stmt->execute([
                        $order_id,
                        $m_id,
                        $cart_item['qty'],
                        $cart_item['price'],
                        $cart_item['notes']
                    ]);
                }
                
                $pdo->commit();
                
                // Store this invoice in user's session cache for order history tracking
                if (!isset($_SESSION['my_orders']) || !is_array($_SESSION['my_orders'])) {
                    $_SESSION['my_orders'] = [];
                }
                $_SESSION['my_orders'][] = $invoice;

                // Clear the selection cart
                $_SESSION['cust_cart'] = [];
                
                // Redirect directly to payment simulation page
                header("Location: /front-end/order/payment.php?invoice=" . urlencode($invoice));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Checkout failed: " . $e->getMessage();
            }
        }
    }
}

// Fetch customer's own order history from session cache
$my_orders = [];
if (!empty($_SESSION['my_orders'])) {
    try {
        // Automatically fail any pending orders that have expired (older than 10 minutes)
        $pdo->exec("
            UPDATE orders 
            SET payment_status = 'failed', status = 'completed' 
            WHERE payment_status = 'pending' AND created_at < NOW() - INTERVAL '10 minutes'
        ");

        $in_placeholders = implode(',', array_fill(0, count($_SESSION['my_orders']), '?'));
        $stmt_my = $pdo->prepare("
            SELECT invoice_number, total_amount, payment_status, status, created_at
            FROM orders
            WHERE invoice_number IN ($in_placeholders)
            ORDER BY created_at DESC
        ");
        $stmt_my->execute($_SESSION['my_orders']);
        $my_orders = $stmt_my->fetchAll();
    } catch (Exception $e) {
        // Silently fail or log, don't break main catalog display
    }
}

// Fetch menus grouped by Category
$catalog = [];
try {
    $stmt = $pdo->query("
        SELECT COALESCE(c.name, 'Uncategorized') as category_name, m.id, m.name, m.description, m.price, m.is_available
        FROM menus m
        LEFT JOIN categories c ON m.category_id = c.id
        WHERE m.is_available = TRUE
        ORDER BY c.name ASC, m.name ASC
    ");
    $catalog = $stmt->fetchAll(PDO::FETCH_GROUP);
} catch (Exception $e) {
    $error = "Error loading menu items: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Self-Service Cafe Ordering</title>
</head>
<body>
    <h1>Cafe Self-Service Ordering Menu</h1>
    <p>Select your favorite dishes, customize notes, and place order directly from your table.</p>

    <?php if ($message): ?>
        <p style="color: green;"><strong>Success:</strong> <?php echo $message; ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <div style="display: flex; gap: 30px; flex-wrap: wrap;">
        <!-- Left: Menu List -->
        <div style="flex: 2; min-width: 450px; border: 1px solid #ccc; padding: 20px;">
            <h2>Menu Options</h2>
            <?php if (empty($catalog)): ?>
                <p>No food or drink options are currently available. Please check back later.</p>
            <?php else: ?>
                <?php foreach ($catalog as $category => $items): ?>
                    <h3>Category: <?php echo htmlspecialchars($category ?: 'Uncategorized'); ?></h3>
                    <table border="1" cellpadding="8" cellspacing="0" style="width: 100%; margin-bottom: 20px;">
                        <thead>
                            <tr style="background-color: #eee;">
                                <th>Item</th>
                                <th>Price</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                        <small style="color: #666;"><?php echo htmlspecialchars($item['description'] ?: '-'); ?></small>
                                    </td>
                                    <td>Rp <?php echo number_format($item['price'], 0, ',', '.'); ?></td>
                                    <td width="150">
                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="add_to_cart">
                                            <input type="hidden" name="menu_id" value="<?php echo $item['id']; ?>">
                                            <label>Qty:</label>
                                            <input type="number" name="qty" value="1" min="1" style="width: 40px;"><br>
                                            <input type="text" name="notes" placeholder="Notes (optional)" style="width: 90%; margin-top: 5px;"><br>
                                            <button type="submit" style="margin-top: 5px; cursor: pointer;">Add Item</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Right: Current Cart Selection & Checkout -->
        <div style="flex: 1.2; min-width: 320px; border: 1px solid #ccc; padding: 20px; align-self: flex-start;">
            <h2>Your Selection</h2>
            <table border="1" cellpadding="6" cellspacing="0" style="width: 100%;">
                <thead>
                    <tr style="background-color: #eee;">
                        <th>Dish</th>
                        <th>Price</th>
                        <th>Qty</th>
                        <th>Subtotal</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $grand_total = 0.00;
                    foreach ($_SESSION['cust_cart'] as $m_id => $item): 
                        $subtotal = $item['price'] * $item['qty'];
                        $grand_total += $subtotal;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                <?php if ($item['notes']): ?>
                                    <br><small style="color: #666;">Note: <?php echo htmlspecialchars($item['notes']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>Rp <?php echo number_format($item['price'], 0, ',', '.'); ?></td>
                            <td><?php echo $item['qty']; ?></td>
                            <td>Rp <?php echo number_format($subtotal, 0, ',', '.'); ?></td>
                            <td>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="menu_id" value="<?php echo $m_id; ?>">
                                    <button type="submit" style="color: red; cursor: pointer;">X</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($_SESSION['cust_cart'])): ?>
                        <tr>
                            <td colspan="5" align="center">Your selection list is empty.</td>
                        </tr>
                    <?php else: ?>
                        <tr style="background-color: #fafafa; font-weight: bold;">
                            <td colspan="3" align="right">Total:</td>
                            <td colspan="2">Rp <?php echo number_format($grand_total, 2, ',', '.'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($_SESSION['cust_cart'])): ?>
                <br>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="checkout">
                    <p>
                        <label for="customer_name"><strong>Your Name:</strong></label><br>
                        <input type="text" id="customer_name" name="customer_name" required style="width: 95%;">
                    </p>
                    <p>
                        <label for="table_number"><strong>Table Number:</strong></label><br>
                        <input type="number" id="table_number" name="table_number" min="1" required style="width: 95%;">
                    </p>
                    <div style="display: flex; justify-content: space-between; margin-top: 15px;">
                        <button type="submit" name="action" value="clear" style="background-color: #fee; color: red; cursor: pointer;">Clear Selections</button>
                        <button type="submit" style="background-color: #efe; font-weight: bold; padding: 5px 15px; cursor: pointer;">Checkout & Pay Online</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <hr style="margin-top: 40px;">

    <!-- Order Lookup Form -->
    <div style="border: 1px solid #ccc; padding: 15px; max-width: 500px; background-color: #fafafa; margin-bottom: 20px;">
        <h3>Find Order by Invoice</h3>
        <form method="GET" action="status.php">
            <label for="search_invoice">Invoice Number:</label><br>
            <input type="text" id="search_invoice" name="invoice" placeholder="INV-YYYYMMDD-XXXXX" required style="width: 70%; padding: 5px;">
            <button type="submit" style="padding: 5px 15px; cursor: pointer;">Track Order</button>
        </form>
    </div>

    <?php if (!empty($my_orders)): ?>
        <h2>Your Recent Orders</h2>
        <table border="1" cellpadding="8" cellspacing="0" style="width: 100%; max-width: 800px; margin-bottom: 40px;">
            <thead>
                <tr style="background-color: #eee;">
                    <th>Invoice</th>
                    <th>Time</th>
                    <th>Total Bill</th>
                    <th>Payment Status</th>
                    <th>Kitchen Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($my_orders as $mo): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($mo['invoice_number']); ?></code></td>
                        <td><small><?php echo date('H:i', strtotime($mo['created_at'])); ?></small></td>
                        <td>Rp <?php echo number_format($mo['total_amount'], 0, ',', '.'); ?></td>
                        <td style="font-weight: bold; color: <?php 
                            echo ($mo['payment_status'] === 'paid') ? 'green' : (($mo['payment_status'] === 'failed') ? 'red' : 'orange'); 
                        ?>;">
                            <?php echo strtoupper($mo['payment_status']); ?>
                        </td>
                        <td style="font-weight: bold; color: <?php 
                            echo ($mo['status'] === 'completed') ? 'blue' : (($mo['status'] === 'ready') ? 'green' : 'orange'); 
                        ?>;">
                            <?php 
                            if ($mo['payment_status'] === 'failed') {
                                echo 'Cancelled';
                            } elseif ($mo['payment_status'] === 'paid') {
                                if ($mo['status'] === 'pending') echo 'Awaiting Kitchen';
                                elseif ($mo['status'] === 'preparing') echo 'Preparing';
                                elseif ($mo['status'] === 'ready') echo 'Ready for Pickup';
                                elseif ($mo['status'] === 'completed') echo 'Served';
                            } else {
                                echo 'Awaiting Payment';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($mo['payment_status'] === 'pending'): ?>
                                <a href="/front-end/order/payment.php?invoice=<?php echo urlencode($mo['invoice_number']); ?>">Pay Now &rarr;</a>
                            <?php else: ?>
                                <a href="/front-end/order/status.php?invoice=<?php echo urlencode($mo['invoice_number']); ?>">Track Status &rarr;</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>

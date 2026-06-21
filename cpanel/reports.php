<?php
require_once __DIR__ . '/auth_helper.php';
require_permission('reports.view');
require_once __DIR__ . '/header.php';

$error = '';

// Setup date range filters (default to last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Initialize reporting variables
$today_sales = 0.00;
$month_sales = 0.00;
$filtered_sales_total = 0.00;
$best_sellers = [];
$transactions = [];

try {
    // 1. Calculate today's sales
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0) 
        FROM orders 
        WHERE payment_status = 'paid' AND created_at >= CURRENT_DATE
    ");
    $today_sales = $stmt->fetchColumn();

    // 2. Calculate current month's sales
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0) 
        FROM orders 
        WHERE payment_status = 'paid' AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $month_sales = $stmt->fetchColumn();

    // 3. Fetch best selling products (all time or matching filter, let's keep all-time top 10 for simplicity)
    $stmt = $pdo->query("
        SELECT m.name as menu_name, SUM(oi.quantity) as total_sold, SUM(oi.quantity * oi.price) as total_revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN menus m ON oi.menu_id = m.id
        WHERE o.payment_status = 'paid'
        GROUP BY m.name
        ORDER BY total_sold DESC
        LIMIT 10
    ");
    $best_sellers = $stmt->fetchAll();

    // 4. Fetch filtered transactions list
    $stmt = $pdo->prepare("
        SELECT o.id, o.invoice_number, o.customer_name, o.total_amount, o.payment_status, o.status, o.created_at, u.username as cashier_name
        FROM orders o
        LEFT JOIN users u ON o.cashier_id = u.id
        WHERE o.created_at >= ? AND o.created_at <= ?::timestamp + INTERVAL '1 day - 1 second'
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $transactions = $stmt->fetchAll();
    
    // Calculate total for filtered sales
    foreach ($transactions as $t) {
        if ($t['payment_status'] === 'paid') {
            $filtered_sales_total += $t['total_amount'];
        }
    }

} catch (Exception $e) {
    $error = "Error generating reports: " . $e->getMessage();
}
?>

<h2>Business Reports</h2>
<p>Analyze income summaries, top-selling items, and transaction logs.</p>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px;">
    <div style="border: 1px solid #ccc; padding: 15px; min-width: 200px; background-color: #fafafa;">
        <h3>Sales Today</h3>
        <p style="font-size: 24px; font-weight: bold; color: green;">Rp <?php echo number_format($today_sales, 2, ',', '.'); ?></p>
    </div>
    
    <div style="border: 1px solid #ccc; padding: 15px; min-width: 200px; background-color: #fafafa;">
        <h3>Sales This Month</h3>
        <p style="font-size: 24px; font-weight: bold; color: green;">Rp <?php echo number_format($month_sales, 2, ',', '.'); ?></p>
    </div>

    <div style="border: 1px solid #ccc; padding: 15px; min-width: 250px; background-color: #f0f8ff;">
        <h3>Filtered Range Sales Total</h3>
        <p style="font-size: 24px; font-weight: bold; color: blue;">Rp <?php echo number_format($filtered_sales_total, 2, ',', '.'); ?></p>
        <small>For period: <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></small>
    </div>
</div>

<hr>

<div style="display: flex; gap: 30px; flex-wrap: wrap;">
    <!-- Best Selling Items Table -->
    <div style="flex: 1; min-width: 320px;">
        <h3>Top 10 Best Selling Items</h3>
        <table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
            <thead>
                <tr style="background-color: #eee;">
                    <th>No</th>
                    <th>Product</th>
                    <th>Quantity Sold</th>
                    <th>Gross Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                foreach ($best_sellers as $item): 
                ?>
                    <tr>
                        <td align="center"><?php echo $i++; ?></td>
                        <td><strong><?php echo htmlspecialchars($item['menu_name']); ?></strong></td>
                        <td align="center"><?php echo $item['total_sold']; ?></td>
                        <td>Rp <?php echo number_format($item['total_revenue'], 2, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($best_sellers)): ?>
                    <tr>
                        <td colspan="4" align="center">No sales registered yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Date Range Sales List -->
    <div style="flex: 2; min-width: 450px;">
        <h3>Transaction List Filters</h3>
        
        <form method="GET" action="reports.php" style="margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; background-color: #fafafa;">
            <label for="start_date">Start Date:</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
            
            &nbsp;&nbsp;
            <label for="end_date">End Date:</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
            
            &nbsp;&nbsp;
            <button type="submit">Filter Range</button>
            <a href="reports.php" style="margin-left: 10px; font-size: 13px;">Clear Filter</a>
        </form>

        <table border="1" cellpadding="6" cellspacing="0" style="width: 100%;">
            <thead>
                <tr style="background-color: #eee;">
                    <th>Invoice</th>
                    <th>Cashier</th>
                    <th>Customer</th>
                    <th>Total Paid</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($t['invoice_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($t['cashier_name'] ?: 'System'); ?></td>
                        <td><?php echo htmlspecialchars($t['customer_name'] ?: '-'); ?></td>
                        <td>Rp <?php echo number_format($t['total_amount'], 2, ',', '.'); ?></td>
                        <td align="center">
                            <span style="font-weight: bold; color: <?php 
                                echo ($t['payment_status'] === 'paid') ? 'green' : (($t['payment_status'] === 'cancelled') ? 'red' : 'orange'); 
                            ?>;">
                                <?php echo strtoupper($t['payment_status']); ?>
                            </span>
                        </td>
                        <td><small><?php echo htmlspecialchars($t['created_at']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="6" align="center">No transactions match the selected date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>

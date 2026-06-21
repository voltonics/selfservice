<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';

// Accept parameters from POST or JSON payload
$invoice = $_POST['invoice'] ?? '';
$status = $_POST['status'] ?? '';

// Support JSON payloads (simulating real gateway API posts)
if (empty($invoice) || empty($status)) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if ($data) {
        $invoice = $data['invoice'] ?? '';
        $status = $data['status'] ?? '';
    }
}

if (empty($invoice) || empty($status)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['status' => 'error', 'message' => 'Missing invoice or status parameters']);
    exit;
}

try {
    // Automatically fail any pending orders that have expired (older than 10 minutes)
    $pdo->exec("
        UPDATE orders 
        SET payment_status = 'failed', status = 'completed' 
        WHERE payment_status = 'pending' AND created_at < NOW() - INTERVAL '10 minutes'
    ");

    // Retrieve order record
    $stmt = $pdo->prepare("SELECT id, invoice_number, total_amount, payment_status, created_at FROM orders WHERE invoice_number = ?");
    $stmt->execute([$invoice]);
    $order = $stmt->fetch();
    
    if (!$order) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        exit;
    }
    
    // Check if order has already been finalized or expired
    if ($order['payment_status'] !== 'pending') {
        // Log late settlement attempts for failed/expired transactions
        if ($order['payment_status'] === 'failed' && $status === 'success') {
            log_audit_action($pdo, 'payment_late_rejected', "Late payment of Rp {$order['total_amount']} rejected for expired invoice: $invoice");
        }
        
        if (basename($_SERVER['HTTP_REFERER'] ?? '') === 'payment.php' || $_SERVER['REQUEST_METHOD'] === 'POST') {
            header("Location: /front-end/order/status.php?invoice=" . urlencode($invoice));
            exit;
        }
        echo json_encode(['status' => 'error', 'message' => 'Order already processed, failed, or expired. Payment rejected.']);
        exit;
    }
    
    // Verify 10-minute expiry constraint (600 seconds)
    $created_time = strtotime($order['created_at']);
    $expiry_time = $created_time + 600;
    $is_expired = (time() > $expiry_time);
    
    if ($is_expired) {
        // Enforce payment failure due to timeout
        $upd = $pdo->prepare("UPDATE orders SET payment_status = 'failed', status = 'completed' WHERE id = ?");
        $upd->execute([$order['id']]);
        
        log_audit_action($pdo, 'payment_webhook_timeout', "Payment rejected due to 10-minute timeout for invoice: $invoice");
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
            header("Location: /front-end/order/status.php?invoice=" . urlencode($invoice));
            exit;
        }
        
        header('Content-Type: application/json');
        echo json_encode(['status' => 'expired', 'message' => 'Transaction timed out. Payment rejected.']);
        exit;
    }
    
    // Process payment status update
    if ($status === 'success') {
        $upd = $pdo->prepare("UPDATE orders SET payment_status = 'paid', status = 'preparing' WHERE id = ?");
        $upd->execute([$order['id']]);
        
        log_audit_action($pdo, 'payment_webhook_success', "Payment settled via webhook for invoice: $invoice (Amount: {$order['total_amount']})");
    } else {
        $upd = $pdo->prepare("UPDATE orders SET payment_status = 'failed', status = 'completed' WHERE id = ?");
        $upd->execute([$order['id']]);
        
        log_audit_action($pdo, 'payment_webhook_failed', "Payment marked as failed/cancelled via webhook for invoice: $invoice");
    }
    
    // Check if this was triggered from browser form and redirect back
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        header("Location: /front-end/order/status.php?invoice=" . urlencode($invoice));
        exit;
    }
    
    // Otherwise return API success response
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Webhook processed successfully']);
    exit;

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['status' => 'error', 'message' => 'Webhook error: ' . $e->getMessage()]);
    exit;
}

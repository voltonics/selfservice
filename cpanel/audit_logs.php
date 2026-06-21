<?php
require_once __DIR__ . '/auth_helper.php';
require_permission('audit.view');
require_once __DIR__ . '/header.php';

$error = '';
$search = trim($_GET['search'] ?? '');

$logs = [];
try {
    if (!empty($search)) {
        // Query audit logs with search matching description or action or username
        $stmt = $pdo->prepare("
            SELECT a.id, a.action, a.description, a.created_at, u.username
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.action ILIKE ? OR a.description ILIKE ? OR u.username ILIKE ?
            ORDER BY a.created_at DESC
            LIMIT 100
        ");
        $like_val = "%$search%";
        $stmt->execute([$like_val, $like_val, $like_val]);
        $logs = $stmt->fetchAll();
    } else {
        // Fetch last 100 audit logs
        $logs = $pdo->query("
            SELECT a.id, a.action, a.description, a.created_at, u.username
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT 100
        ")->fetchAll();
    }
} catch (Exception $e) {
    $error = "Error loading audit trail logs: " . $e->getMessage();
}
?>

<h2>System Audit Trail logs</h2>
<p>Authorized access to tracking all significant activities performed in the Cafe Management Panel.</p>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="border: 1px solid #ddd; padding: 15px; background-color: #fafafa; margin-bottom: 20px;">
    <form method="GET" action="audit_logs.php">
        <label for="search">Search logs:</label>
        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. login, invoice, admin" style="width: 300px; padding: 5px;">
        <button type="submit" style="padding: 5px 15px;">Search</button>
        <?php if (!empty($search)): ?>
            <a href="audit_logs.php" style="margin-left: 10px; font-size: 13px;">Clear Search</a>
        <?php endif; ?>
    </form>
</div>

<table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
    <thead>
        <tr style="background-color: #eee;">
            <th width="80">Log ID</th>
            <th width="160">Timestamp</th>
            <th width="120">Authorized User</th>
            <th width="180">Action Type</th>
            <th>Log Details Description</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td align="center"><small><?php echo $log['id']; ?></small></td>
                <td><small><?php echo htmlspecialchars($log['created_at']); ?></small></td>
                <td>
                    <strong><?php echo htmlspecialchars($log['username'] ?: 'System / Anonymous'); ?></strong>
                </td>
                <td>
                    <code><?php echo htmlspecialchars($log['action']); ?></code>
                </td>
                <td><?php echo htmlspecialchars($log['description']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
            <tr>
                <td colspan="5" align="center" style="padding: 20px; color: gray;">
                    No audit log records found matching your search.
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php
require_once __DIR__ . '/footer.php';
?>

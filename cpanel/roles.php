<?php
require_once __DIR__ . '/auth_helper.php';
require_permission('roles.manage');
require_once __DIR__ . '/header.php';

$message = '';
$error = '';

// Handle POST permission mappings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mappings'])) {
    $role_id = (int)($_POST['role_id'] ?? 0);
    $selected_perms = $_POST['perms'] ?? []; // Array of permission IDs
    
    if ($role_id <= 0) {
        $error = 'Invalid role selected.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get role name for audit logs
            $r_stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
            $r_stmt->execute([$role_id]);
            $role_name = $r_stmt->fetchColumn();
            
            if ($role_name) {
                // Delete existing mappings
                $del = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $del->execute([$role_id]);
                
                // Insert new mappings
                if (!empty($selected_perms)) {
                    $ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    foreach ($selected_perms as $p_id) {
                        $ins->execute([$role_id, (int)$p_id]);
                    }
                }
                
                $pdo->commit();
                log_audit_action($pdo, 'role_permissions_update', "Updated permission mappings for role: $role_name");
                
                // If current logged-in user's role is updated, refresh their permissions in session
                if ($role_id === $_SESSION['role_id']) {
                    refresh_user_permissions($pdo, $_SESSION['user_id']);
                }
                
                $message = "Role '{$role_name}' permissions updated successfully!";
            } else {
                $pdo->rollBack();
                $error = 'Role not found.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error saving permissions: ' . $e->getMessage();
        }
    }
}

// Fetch all roles
$roles = [];
try {
    $roles = $pdo->query("SELECT id, name, description FROM roles ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {
    $error = 'Error fetching roles: ' . $e->getMessage();
}

// Fetch all permissions
$permissions = [];
try {
    $permissions = $pdo->query("SELECT id, name, description FROM permissions ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $error = 'Error fetching permissions: ' . $e->getMessage();
}

// Get selected role for editing
$selected_role_id = 0;
$selected_role_perms = [];

if (isset($_GET['role_id'])) {
    $selected_role_id = (int)$_GET['role_id'];
    
    // Fetch currently mapped permissions
    try {
        $p_stmt = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
        $p_stmt->execute([$selected_role_id]);
        $selected_role_perms = $p_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $error = 'Error loading role permissions: ' . $e->getMessage();
    }
}
?>

<h2>Role & Permissions Management (RBAC)</h2>
<p>Allocate granular system action permissions to user roles.</p>

<?php if ($message): ?>
    <p style="color: green;"><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="display: flex; gap: 30px; flex-wrap: wrap;">
    <!-- Left Column: List Roles -->
    <div style="border: 1px solid #ccc; padding: 20px; min-width: 300px; flex: 1;">
        <h3>System Roles</h3>
        <table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
            <thead>
                <tr style="background-color: #eee;">
                    <th>ID</th>
                    <th>Role Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $r): ?>
                    <tr style="<?php echo ($selected_role_id === $r['id']) ? 'background-color: #ffd;' : ''; ?>">
                        <td><?php echo $r['id']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($r['name']); ?></strong><br>
                            <small style="color: #666;"><?php echo htmlspecialchars($r['description']); ?></small>
                        </td>
                        <td>
                            <a href="roles.php?role_id=<?php echo $r['id']; ?>">Configure Permissions</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Right Column: Configure Permissions Checkboxes -->
    <div style="border: 1px solid #ccc; padding: 20px; min-width: 400px; flex: 1.5;">
        <?php if ($selected_role_id > 0): ?>
            <?php 
                $selected_role_name = '';
                foreach ($roles as $r) {
                    if ($r['id'] === $selected_role_id) {
                        $selected_role_name = $r['name'];
                        break;
                    }
                }
            ?>
            <h3>Manage Permissions for: <u><?php echo htmlspecialchars($selected_role_name); ?></u></h3>
            
            <?php if ($selected_role_name === 'Super Admin'): ?>
                <div style="border: 1px dashed red; padding: 10px; margin-bottom: 20px; background-color: #fff9f9;">
                    <strong>Notice:</strong> Members of Super Admin role have full access bypass enabled. Changes here are saved but they will retain access to all resources regardless.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="roles.php?role_id=<?php echo $selected_role_id; ?>">
                <input type="hidden" name="role_id" value="<?php echo $selected_role_id; ?>">
                
                <table border="1" cellpadding="6" cellspacing="0" style="width: 100%; margin-bottom: 20px;">
                    <thead>
                        <tr style="background-color: #eee;">
                            <th width="50">Allow</th>
                            <th>Permission Key</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permissions as $p): ?>
                            <tr>
                                <td align="center">
                                    <input type="checkbox" name="perms[]" value="<?php echo $p['id']; ?>" 
                                        <?php echo in_array($p['id'], $selected_role_perms) ? 'checked' : ''; ?>>
                                </td>
                                <td><code><?php echo htmlspecialchars($p['name']); ?></code></td>
                                <td><?php echo htmlspecialchars($p['description']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <button type="submit" name="save_mappings">Save Permissions Mapping</button>
                <a href="roles.php">Cancel</a>
            </form>
        <?php else: ?>
            <p style="padding: 40px; text-align: center; border: 1px dashed #bbb; background-color: #fafafa;">
                Please select a role from the left menu to view and configure its permission mappings.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>

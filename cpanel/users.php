<?php
require_once __DIR__ . '/auth_helper.php';
require_permission('users.manage');
require_once __DIR__ . '/header.php';

$message = '';
$error = '';

// Handle Create / Update User POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id = (int)($_POST['role_id'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $name = trim($_POST['name'] ?? '');
        
        $nik = trim($_POST['nik'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $no_telp = trim($_POST['no_telp'] ?? '');
        
        if (empty($username) || empty($email) || empty($password) || empty($name) || $role_id <= 0) {
            $error = 'Username, Full Name, Email, Password, and Role are required.';
        } else {
            try {
                // Check uniqueness of username and email
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $chk->execute([$username, $email]);
                if ($chk->fetch()) {
                    $error = 'Username or Email is already registered.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, email, password_hash, role_id, is_active, name)
                        VALUES (?, ?, ?, ?, ?, ?) RETURNING id
                    ");
                    $stmt->execute([$username, $email, $hash, $role_id, $is_active, $name]);
                    $new_user_id = $stmt->fetchColumn();
                    
                    // Fetch role name to check if it's staff
                    $role_stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                    $role_stmt->execute([$role_id]);
                    $role_name = $role_stmt->fetchColumn();
                    
                    if (in_array($role_name, ['Manager', 'Kitchen', 'Inventory', 'Waiter'])) {
                        if (!empty($nik) || !empty($alamat) || !empty($no_telp)) {
                            $st_stmt = $pdo->prepare("
                                INSERT INTO staff (user_id, nik, alamat, no_telp)
                                VALUES (?, ?, ?, ?)
                            ");
                            $st_stmt->execute([$new_user_id, $nik ?: null, $alamat ?: null, $no_telp ?: null]);
                        }
                    }
                    
                    log_audit_action($pdo, 'user_create', "Created user account: $username with role ID $role_id");
                    $message = "User '{$username}' successfully created!";
                }
            } catch (Exception $e) {
                $error = 'Error creating user: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id = (int)($_POST['role_id'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $name = trim($_POST['name'] ?? '');
        
        $nik = trim($_POST['nik'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $no_telp = trim($_POST['no_telp'] ?? '');
        
        if (empty($email) || empty($name) || $role_id <= 0) {
            $error = 'Full Name, Email and Role are required.';
        } else {
            try {
                $stmt_curr = $pdo->prepare("SELECT username, role_id FROM users WHERE id = ?");
                $stmt_curr->execute([$id]);
                $user_curr = $stmt_curr->fetch();
                
                if ($user_curr) {
                    $update_sql = "UPDATE users SET email = ?, role_id = ?, is_active = ?, name = ?, updated_at = NOW()";
                    $params = [$email, $role_id, $is_active, $name];
                    
                    // If password is provided, update it too
                    if (!empty($password)) {
                        $update_sql .= ", password_hash = ?";
                        $params[] = password_hash($password, PASSWORD_BCRYPT);
                    }
                    
                    $update_sql .= " WHERE id = ?";
                    $params[] = $id;
                    
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute($params);
                    
                    // Fetch role name to check if it's staff
                    $role_stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                    $role_stmt->execute([$role_id]);
                    $role_name = $role_stmt->fetchColumn();
                    
                    if (in_array($role_name, ['Manager', 'Kitchen', 'Inventory', 'Waiter'])) {
                        // Upsert staff profile
                        $chk = $pdo->prepare("SELECT user_id FROM staff WHERE user_id = ?");
                        $chk->execute([$id]);
                        if ($chk->fetch()) {
                            $st_upd = $pdo->prepare("
                                UPDATE staff SET nik = ?, alamat = ?, no_telp = ?
                                WHERE user_id = ?
                            ");
                            $st_upd->execute([$nik ?: null, $alamat ?: null, $no_telp ?: null, $id]);
                        } else {
                            if (!empty($nik) || !empty($alamat) || !empty($no_telp)) {
                                $st_ins = $pdo->prepare("
                                    INSERT INTO staff (user_id, nik, alamat, no_telp)
                                    VALUES (?, ?, ?, ?)
                                ");
                                $st_ins->execute([$id, $nik ?: null, $alamat ?: null, $no_telp ?: null]);
                            }
                        }
                    } else {
                        // If role is no longer staff, remove staff profile
                        $st_del = $pdo->prepare("DELETE FROM staff WHERE user_id = ?");
                        $st_del->execute([$id]);
                    }
                    
                    log_audit_action($pdo, 'user_update', "Updated user account: {$user_curr['username']}");
                    
                    // If updating current logged in user's role or status, refresh their session permissions
                    if ($id === $_SESSION['user_id']) {
                        refresh_user_permissions($pdo, $id);
                    }
                    
                    $message = "User '{$user_curr['username']}' updated successfully!";
                } else {
                    $error = 'User not found.';
                }
            } catch (Exception $e) {
                $error = 'Error updating user: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Roles for dropdown
$roles = [];
try {
    $roles = $pdo->query("SELECT id, name, description FROM roles ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {
    $error = 'Error fetching roles: ' . $e->getMessage();
}

// Fetch existing users
$users = [];
try {
    $users = $pdo->query("
        SELECT u.id, u.username, u.email, u.is_active, u.created_at, u.name, r.name as role_name, r.id as role_id,
               s.nik, s.alamat, s.no_telp
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN staff s ON u.id = s.user_id
        ORDER BY u.id ASC
    ")->fetchAll();
} catch (Exception $e) {
    $error = 'Error fetching users list: ' . $e->getMessage();
}

// Handle GET edit mode parameter
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($users as $u) {
        if ($u['id'] === $edit_id) {
            $edit_user = $u;
            break;
        }
    }
}
?>

<h2>User Management</h2>
<p>Create and update system accounts and assign authorization roles.</p>

<?php if ($message): ?>
    <p style="color: green;"><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p style="color: red;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<div style="display: flex; gap: 30px; flex-wrap: wrap;">
    <!-- Form Side -->
    <div style="border: 1px solid #ccc; padding: 20px; min-width: 300px; flex: 1;">
        <?php if ($edit_user): ?>
            <h3>Edit User: <?php echo htmlspecialchars($edit_user['username']); ?></h3>
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                
                <p>
                    <label>Username:</label><br>
                    <input type="text" value="<?php echo htmlspecialchars($edit_user['username']); ?>" disabled>
                    <br><small>Username cannot be modified.</small>
                </p>
                <p>
                    <label for="name">Full Name:</label><br>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($edit_user['name'] ?? ''); ?>" required>
                </p>
                <p>
                    <label for="email">Email Address:</label><br>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($edit_user['email']); ?>" required>
                </p>
                <p>
                    <label for="password">Password:</label><br>
                    <input type="password" id="password" name="password" placeholder="Leave blank to keep current password">
                </p>
                <p>
                    <label for="role_id_edit">System Role:</label><br>
                    <select id="role_id_edit" name="role_id" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" data-name="<?php echo htmlspecialchars($r['name']); ?>" <?php echo ($edit_user['role_id'] == $r['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <div id="staff_fields_edit" style="border: 1px dashed #ccc; padding: 10px; margin-bottom: 15px; display: none;">
                    <h4 style="margin: 0 0 10px 0;">Staff Details</h4>
                    <p style="margin: 5px 0;">
                        <label for="nik">NIK:</label><br>
                        <input type="text" id="nik" name="nik" value="<?php echo htmlspecialchars($edit_user['nik'] ?? ''); ?>">
                    </p>
                    <p style="margin: 5px 0;">
                        <label for="no_telp">Phone Number:</label><br>
                        <input type="text" id="no_telp" name="no_telp" value="<?php echo htmlspecialchars($edit_user['no_telp'] ?? ''); ?>">
                    </p>
                    <p style="margin: 5px 0;">
                        <label for="alamat">Address:</label><br>
                        <textarea id="alamat" name="alamat" rows="2" style="width: 95%;"><?php echo htmlspecialchars($edit_user['alamat'] ?? ''); ?></textarea>
                    </p>
                </div>
                <p>
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?php echo $edit_user['is_active'] ? 'checked' : ''; ?>>
                        <strong>User Active / Enabled</strong>
                    </label>
                </p>
                <p>
                    <button type="submit">Update User</button>
                    <a href="users.php">Cancel Edit</a>
                </p>
            </form>
        <?php else: ?>
            <h3>Create New User</h3>
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="create">
                <p>
                    <label for="username">Username:</label><br>
                    <input type="text" id="username" name="username" required>
                </p>
                <p>
                    <label for="name">Full Name:</label><br>
                    <input type="text" id="name" name="name" required>
                </p>
                <p>
                    <label for="email">Email Address:</label><br>
                    <input type="email" id="email" name="email" required>
                </p>
                <p>
                    <label for="password">Password:</label><br>
                    <input type="password" id="password" name="password" required>
                </p>
                <p>
                    <label for="role_id_create">System Role:</label><br>
                    <select id="role_id_create" name="role_id" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" data-name="<?php echo htmlspecialchars($r['name']); ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <div id="staff_fields_create" style="border: 1px dashed #ccc; padding: 10px; margin-bottom: 15px; display: none;">
                    <h4 style="margin: 0 0 10px 0;">Staff Details</h4>
                    <p style="margin: 5px 0;">
                        <label for="nik">NIK:</label><br>
                        <input type="text" id="nik" name="nik">
                    </p>
                    <p style="margin: 5px 0;">
                        <label for="no_telp">Phone Number:</label><br>
                        <input type="text" id="no_telp" name="no_telp">
                    </p>
                    <p style="margin: 5px 0;">
                        <label for="alamat">Address:</label><br>
                        <textarea id="alamat" name="alamat" rows="2" style="width: 95%;"></textarea>
                    </p>
                </div>
                <p>
                    <label>
                        <input type="checkbox" name="is_active" value="1" checked>
                        <strong>User Active / Enabled</strong>
                    </label>
                </p>
                <p>
                    <button type="submit">Create User</button>
                </p>
            </form>
        <?php endif; ?>
    </div>

    <!-- Listing Side -->
    <div style="border: 1px solid #ccc; padding: 20px; flex: 2; min-width: 450px;">
        <h3>Registered Accounts List</h3>
        <table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
            <thead>
                <tr style="background-color: #eee;">
                    <th>ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>NIK</th>
                    <th>Address</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($u['name'] ?: '-'); ?></strong></td>
                        <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><?php echo htmlspecialchars($u['role_name'] ?: 'None'); ?></td>
                        <td><?php echo htmlspecialchars($u['nik'] ?: '-'); ?></td>
                        <td><small><?php echo htmlspecialchars($u['alamat'] ?: '-'); ?></small></td>
                        <td><?php echo htmlspecialchars($u['no_telp'] ?: '-'); ?></td>
                        <td style="color: <?php echo $u['is_active'] ? 'green' : 'red'; ?>;">
                            <strong><?php echo $u['is_active'] ? 'Active' : 'Disabled'; ?></strong>
                        </td>
                        <td><small><?php echo htmlspecialchars($u['created_at']); ?></small></td>
                        <td>
                            <a href="users.php?edit=<?php echo $u['id']; ?>">Edit Details</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    function toggleStaffFields(roleSelectId, fieldsContainerId) {
        var select = document.getElementById(roleSelectId);
        var container = document.getElementById(fieldsContainerId);
        if (!select || !container) return;
        
        function update() {
            var selectedOption = select.options[select.selectedIndex];
            var roleName = selectedOption ? selectedOption.getAttribute('data-name') : '';
            var staffRoles = ['Manager', 'Kitchen', 'Inventory', 'Waiter'];
            if (staffRoles.indexOf(roleName) !== -1) {
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
            }
        }
        select.addEventListener('change', update);
        update();
    }
    
    toggleStaffFields('role_id_edit', 'staff_fields_edit');
    toggleStaffFields('role_id_create', 'staff_fields_create');
});
</script>

<?php
require_once __DIR__ . '/footer.php';
?>

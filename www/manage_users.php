<?php
/**
 * USER MANAGEMENT
 * Check existing users and create superadmin if needed
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Users</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .success { color: #00ff00; }
        .warning { color: #ffaa00; }
        .error { color: #ff0000; }
        .info { color: #00aaff; }
        h1 { color: #ffff00; border-bottom: 2px solid #ffff00; padding-bottom: 10px; }
        h2 { color: #ff00ff; margin-top: 30px; }
        .section { background: #2a2a2a; padding: 15px; margin: 10px 0; border-left: 4px solid #00ff00; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th {
            background: #000;
            color: #00ff00;
            padding: 10px;
            text-align: left;
            border: 1px solid #00ff00;
        }
        td {
            padding: 10px;
            border: 1px solid #333;
        }
        .btn {
            background: #00ff00;
            color: #000;
            padding: 10px 20px;
            border: none;
            font-weight: bold;
            cursor: pointer;
            font-size: 14px;
            margin: 5px;
        }
        .btn:hover { background: #00cc00; }
        .btn-danger { background: #ff0000; color: #fff; }
        .btn-danger:hover { background: #cc0000; }
        pre { margin: 5px 0; }
        input[type="text"], input[type="password"] {
            background: #000;
            color: #00ff00;
            border: 1px solid #00ff00;
            padding: 8px;
            font-family: 'Courier New', monospace;
            width: 200px;
        }
        select {
            background: #000;
            color: #00ff00;
            border: 1px solid #00ff00;
            padding: 8px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <h1>👥 USER MANAGEMENT</h1>

<?php
$db = getDbConnection();

// Create superadmin if requested
if (isset($_POST['create_superadmin'])) {
    try {
        $username = 'superadmin';
        $password = '12345';
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Check if columns exist
        $hasSetupCol = false;
        $hasSuperAdminCol = false;

        $result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
        if ($result && $result->num_rows > 0) {
            $hasSetupCol = true;
        }

        $result = $db->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
        if ($result && $result->num_rows > 0) {
            $hasSuperAdminCol = true;
        }

        // Check if user exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Update existing user
            if ($hasSetupCol && $hasSuperAdminCol) {
                $stmt = $db->prepare("UPDATE users SET password_hash = ?, role = 'super_admin', is_super_admin = 1, setup_completed = 1, is_active = 1 WHERE username = ?");
            } else {
                $stmt = $db->prepare("UPDATE users SET password_hash = ?, role = 'super_admin', is_active = 1 WHERE username = ?");
            }
            $stmt->bind_param("ss", $hashedPassword, $username);
            $stmt->execute();

            echo '<div class="section">';
            echo '<h2 class="success">✓ Superadmin Updated!</h2>';
            echo '<pre>Username: superadmin</pre>';
            echo '<pre>Password: 12345</pre>';
            echo '<pre>Password has been reset.</pre>';
            echo '</div>';
        } else {
            // Create new user
            $fullName = 'Super Administrator';
            $email = 'superadmin@localhost';

            if ($hasSetupCol && $hasSuperAdminCol) {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, email, role, is_active, is_super_admin, setup_completed) VALUES (?, ?, ?, ?, 'super_admin', 1, 1, 1)");
            } else {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, email, role, is_active) VALUES (?, ?, ?, ?, 'super_admin', 1)");
            }
            $stmt->bind_param("ssss", $username, $hashedPassword, $fullName, $email);
            $stmt->execute();

            echo '<div class="section">';
            echo '<h2 class="success">✓ Superadmin Created!</h2>';
            echo '<pre>Username: superadmin</pre>';
            echo '<pre>Password: 12345</pre>';
            echo '<pre>User ID: ' . $db->insert_id . '</pre>';
            echo '</div>';
        }
    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ Error Creating Superadmin</h2>';
        echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '</div>';
    }
}

// Create custom user if requested
if (isset($_POST['create_user'])) {
    try {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $fullName = $_POST['full_name'];
        $role = $_POST['role'];

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Check if columns exist
        $hasSetupCol = false;
        $result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
        if ($result && $result->num_rows > 0) {
            $hasSetupCol = true;
        }

        if ($hasSetupCol) {
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active, setup_completed) VALUES (?, ?, ?, ?, 1, 1)");
        } else {
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)");
        }
        $stmt->bind_param("ssss", $username, $hashedPassword, $fullName, $role);
        $stmt->execute();

        echo '<div class="section">';
        echo '<h2 class="success">✓ User Created!</h2>';
        echo '<pre>Username: ' . htmlspecialchars($username) . '</pre>';
        echo '<pre>Role: ' . htmlspecialchars($role) . '</pre>';
        echo '</div>';
    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ Error Creating User</h2>';
        echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '</div>';
    }
}

// Delete user if requested
if (isset($_POST['delete_user'])) {
    try {
        $userId = (int)$_POST['user_id'];
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        echo '<div class="section">';
        echo '<h2 class="success">✓ User Deleted</h2>';
        echo '</div>';
    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ Error Deleting User</h2>';
        echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '</div>';
    }
}

// Show all users
echo '<div class="section">';
echo '<h2>📋 CURRENT USERS</h2>';

$result = $db->query("SELECT id, username, full_name, email, role, is_active, created_at FROM users ORDER BY id ASC");

if ($result && $result->num_rows > 0) {
    echo '<table>';
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Username</th>';
    echo '<th>Full Name</th>';
    echo '<th>Email</th>';
    echo '<th>Role</th>';
    echo '<th>Status</th>';
    echo '<th>Created</th>';
    echo '<th>Actions</th>';
    echo '</tr>';

    while ($row = $result->fetch_assoc()) {
        $statusClass = $row['is_active'] ? 'success' : 'error';
        $statusText = $row['is_active'] ? 'Active' : 'Inactive';

        echo '<tr>';
        echo '<td>' . $row['id'] . '</td>';
        echo '<td><strong>' . htmlspecialchars($row['username']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($row['full_name'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['email'] ?? 'N/A') . '</td>';
        echo '<td class="' . ($row['role'] === 'super_admin' ? 'warning' : 'info') . '">' . htmlspecialchars($row['role']) . '</td>';
        echo '<td class="' . $statusClass . '">' . $statusText . '</td>';
        echo '<td>' . date('Y-m-d H:i', strtotime($row['created_at'])) . '</td>';
        echo '<td>';
        echo '<form method="post" style="display: inline;" onsubmit="return confirm(\'Delete this user?\')">';
        echo '<input type="hidden" name="user_id" value="' . $row['id'] . '">';
        echo '<button type="submit" name="delete_user" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">Delete</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';

    echo '<br><pre class="info">Total users: ' . $result->num_rows . '</pre>';
} else {
    echo '<pre class="warning">⚠️ No users found in database!</pre>';
}

echo '</div>';

// Create Superadmin section
echo '<div class="section">';
echo '<h2>🔐 CREATE/RESET SUPERADMIN</h2>';
echo '<pre class="info">Username: superadmin</pre>';
echo '<pre class="info">Password: 12345</pre>';
echo '<pre class="warning">⚠️ If superadmin exists, password will be reset to 12345</pre>';
echo '<form method="post">';
echo '<button type="submit" name="create_superadmin" class="btn">🚀 Create/Reset Superadmin</button>';
echo '</form>';
echo '</div>';

// Create custom user section
echo '<div class="section">';
echo '<h2>➕ CREATE NEW USER</h2>';
echo '<form method="post">';
echo '<table style="border: none;">';
echo '<tr>';
echo '<td style="border: none;">Username:</td>';
echo '<td style="border: none;"><input type="text" name="username" required></td>';
echo '</tr>';
echo '<tr>';
echo '<td style="border: none;">Password:</td>';
echo '<td style="border: none;"><input type="password" name="password" required></td>';
echo '</tr>';
echo '<tr>';
echo '<td style="border: none;">Full Name:</td>';
echo '<td style="border: none;"><input type="text" name="full_name" required></td>';
echo '</tr>';
echo '<tr>';
echo '<td style="border: none;">Role:</td>';
echo '<td style="border: none;">';
echo '<select name="role">';
echo '<option value="admin">Admin</option>';
echo '<option value="doctor">Doctor</option>';
echo '<option value="radiologist">Radiologist</option>';
echo '<option value="technician">Technician</option>';
echo '<option value="viewer">Viewer</option>';
echo '</select>';
echo '</td>';
echo '</tr>';
echo '<tr>';
echo '<td style="border: none;"></td>';
echo '<td style="border: none;"><button type="submit" name="create_user" class="btn">Create User</button></td>';
echo '</tr>';
echo '</table>';
echo '</form>';
echo '</div>';

// Default credentials info
echo '<div class="section">';
echo '<h2>🔑 DEFAULT CREDENTIALS</h2>';
echo '<pre class="info">After license activation, default admin is created:</pre>';
echo '<pre>  Username: admin</pre>';
echo '<pre>  Password: admin123</pre>';
echo '<br>';
echo '<pre class="info">To use superadmin instead, create it above:</pre>';
echo '<pre>  Username: superadmin</pre>';
echo '<pre>  Password: 12345</pre>';
echo '</div>';
?>

</body>
</html>

<?php
/**
 * FIX ROLE ENUM
 * Add 'super_admin' to the role ENUM type
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Role ENUM</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .success { color: #00ff00; }
        .error { color: #ff0000; }
        .info { color: #00aaff; }
        h1 { color: #ffff00; border-bottom: 2px solid #ffff00; padding-bottom: 10px; }
        .section { background: #2a2a2a; padding: 15px; margin: 10px 0; border-left: 4px solid #00ff00; }
        pre { margin: 5px 0; }
    </style>
</head>
<body>
    <h1>🔧 FIX ROLE ENUM - ADD SUPER_ADMIN</h1>

<?php
try {
    $db = getDbConnection();

    echo '<div class="section">';
    echo '<h2>Step 1: Current ENUM Values</h2>';

    $result = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $result->fetch_assoc();

    echo '<pre class="info">Current Type: ' . htmlspecialchars($column['Type']) . '</pre>';
    echo '</div>';

    echo '<div class="section">';
    echo '<h2>Step 2: Modifying ENUM to Include super_admin</h2>';

    $sql = "ALTER TABLE users MODIFY COLUMN role ENUM('admin','doctor','radiologist','technician','viewer','super_admin') NOT NULL DEFAULT 'viewer'";

    echo '<pre class="info">Executing SQL:</pre>';
    echo '<pre>' . htmlspecialchars($sql) . '</pre>';

    $result = $db->query($sql);

    if ($result) {
        echo '<pre class="success">✓ ENUM modified successfully!</pre>';
    } else {
        echo '<pre class="error">✗ Error: ' . htmlspecialchars($db->error) . '</pre>';
        throw new Exception("Failed to modify ENUM");
    }

    echo '</div>';

    echo '<div class="section">';
    echo '<h2>Step 3: Verifying New ENUM</h2>';

    $result = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $result->fetch_assoc();

    echo '<pre class="success">New Type: ' . htmlspecialchars($column['Type']) . '</pre>';

    if (strpos($column['Type'], 'super_admin') !== false) {
        echo '<pre class="success">✓ "super_admin" is now in the ENUM!</pre>';
    } else {
        echo '<pre class="error">✗ "super_admin" was NOT added!</pre>';
    }

    echo '</div>';

    echo '<div class="section">';
    echo '<h2>Step 4: Setting superadmin user role</h2>';

    $stmt = $db->prepare("UPDATE users SET role = 'super_admin' WHERE username = 'superadmin'");
    $stmt->execute();

    echo '<pre class="info">Affected rows: ' . $stmt->affected_rows . '</pre>';

    if ($stmt->affected_rows > 0) {
        echo '<pre class="success">✓ Role updated!</pre>';
    } else {
        echo '<pre class="error">✗ No rows affected</pre>';
    }

    $stmt->close();

    echo '</div>';

    echo '<div class="section">';
    echo '<h2>Step 5: Final Verification</h2>';

    $result = $db->query("SELECT id, username, role, is_super_admin FROM users WHERE username = 'superadmin'");
    $user = $result->fetch_assoc();

    echo '<pre>ID: ' . $user['id'] . '</pre>';
    echo '<pre>Username: ' . htmlspecialchars($user['username']) . '</pre>';
    echo '<pre>Role: <strong>' . htmlspecialchars($user['role']) . '</strong></pre>';
    echo '<pre>is_super_admin: <strong>' . $user['is_super_admin'] . '</strong></pre>';

    if ($user['role'] === 'super_admin') {
        echo '<br><pre class="success">✓✓✓ SUCCESS! Role is now "super_admin"!</pre>';

        echo '</div>';

        echo '<div class="section">';
        echo '<h2>🎉 ALL DONE!</h2>';
        echo '<pre class="success">The superadmin role has been fixed!</pre>';
        echo '<br>';
        echo '<pre class="info">NEXT STEPS:</pre>';
        echo '<pre>1. Close this browser tab</pre>';
        echo '<pre>2. In your Electron app, click LOGOUT</pre>';
        echo '<pre>3. Close the Electron app completely</pre>';
        echo '<pre>4. Restart: npm start</pre>';
        echo '<pre>5. Login with superadmin / 12345</pre>';
        echo '<pre>6. Click "Manage Licenses" - IT WILL WORK NOW!</pre>';
        echo '</div>';
    } else {
        echo '<br><pre class="error">✗ Still not working! Role is: "' . htmlspecialchars($user['role']) . '"</pre>';
    }

} catch (Exception $e) {
    echo '<div class="section">';
    echo '<h2 class="error">❌ ERROR</h2>';
    echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
    echo '</div>';
}
?>

</body>
</html>

<?php
/**
 * FIX SUPERADMIN ROLE
 * This script fixes the superadmin user by setting the role field
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Superadmin Role</title>
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
    <h1>🔧 FIX SUPERADMIN ROLE</h1>

<?php
try {
    $db = getDbConnection();

    echo '<div class="section">';
    echo '<h2>Step 1: Checking current superadmin user...</h2>';

    // Check current state
    $stmt = $db->prepare("SELECT id, username, role, is_super_admin, is_active FROM users WHERE username = 'superadmin'");
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo '<pre class="error">✗ Superadmin user does NOT exist!</pre>';
        echo '<pre class="info">Please create it first using manage_users.php</pre>';
    } else {
        $user = $result->fetch_assoc();
        echo '<pre class="info">Found superadmin user:</pre>';
        echo '<pre>  ID: ' . $user['id'] . '</pre>';
        echo '<pre>  Username: ' . $user['username'] . '</pre>';
        echo '<pre>  Current Role: ' . ($user['role'] ?: '[EMPTY]') . '</pre>';
        echo '<pre>  is_super_admin: ' . ($user['is_super_admin'] ?? '[COLUMN MISSING]') . '</pre>';
        echo '<pre>  is_active: ' . ($user['is_active'] ? 'YES' : 'NO') . '</pre>';

        echo '</div>';

        // Fix the role
        echo '<div class="section">';
        echo '<h2>Step 2: Updating role to super_admin...</h2>';

        $stmt = $db->prepare("UPDATE users SET role = 'super_admin', is_super_admin = 1, is_active = 1 WHERE username = 'superadmin'");
        $stmt->execute();
        $stmt->close();

        echo '<pre class="success">✓ Updated superadmin user!</pre>';
        echo '</div>';

        // Verify the fix
        echo '<div class="section">';
        echo '<h2>Step 3: Verifying the fix...</h2>';

        $stmt = $db->prepare("SELECT id, username, role, is_super_admin, is_active FROM users WHERE username = 'superadmin'");
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        echo '<pre class="success">✓ Verification complete:</pre>';
        echo '<pre>  ID: ' . $user['id'] . '</pre>';
        echo '<pre>  Username: ' . $user['username'] . '</pre>';
        echo '<pre>  Role: <strong>' . $user['role'] . '</strong></pre>';
        echo '<pre>  is_super_admin: <strong>' . $user['is_super_admin'] . '</strong></pre>';
        echo '<pre>  is_active: <strong>' . ($user['is_active'] ? 'YES' : 'NO') . '</strong></pre>';

        if ($user['role'] === 'super_admin' && $user['is_super_admin'] == 1) {
            echo '<br><pre class="success">✓✓✓ SUCCESS! Superadmin role is now set correctly!</pre>';
        } else {
            echo '<br><pre class="error">✗ Something went wrong. Please check manually.</pre>';
        }

        echo '</div>';

        // Instructions
        echo '<div class="section">';
        echo '<h2>✅ NEXT STEPS:</h2>';
        echo '<pre class="info">1. Close this browser tab</pre>';
        echo '<pre class="info">2. In your Electron app, click LOGOUT</pre>';
        echo '<pre class="info">3. Close the Electron app completely</pre>';
        echo '<pre class="info">4. Restart: npm start</pre>';
        echo '<pre class="info">5. Login with superadmin / 12345</pre>';
        echo '<pre class="info">6. Click "Manage Licenses" - it should work now!</pre>';
        echo '</div>';
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

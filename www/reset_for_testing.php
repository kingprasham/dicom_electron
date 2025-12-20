<?php
/**
 * RESET SCRIPT FOR TESTING SETUP WIZARD FLOW
 * This script clears all licenses, setup data, and resets users
 * USE THIS TO TEST: License Activation → Setup Wizard → Tour
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset For Testing</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }
        .success { color: #00ff00; }
        .warning { color: #ffaa00; }
        .error { color: #ff0000; }
        .info { color: #00aaff; }
        h1 { color: #ffff00; border-bottom: 2px solid #ffff00; padding-bottom: 10px; }
        h2 { color: #ff00ff; margin-top: 30px; }
        .section { background: #2a2a2a; padding: 15px; margin: 10px 0; border-left: 4px solid #00ff00; }
        .command { background: #000; padding: 10px; margin: 5px 0; border: 1px solid #333; }
        pre { margin: 5px 0; }
        .btn {
            background: #00ff00;
            color: #000;
            padding: 10px 20px;
            border: none;
            font-weight: bold;
            cursor: pointer;
            font-size: 16px;
            margin: 20px 0;
        }
        .btn:hover {
            background: #00cc00;
        }
    </style>
</head>
<body>
    <h1>🔄 RESET DATABASE FOR TESTING</h1>
    <p class="warning">⚠️ WARNING: This will DELETE all licenses, setup data, and reset users!</p>

<?php
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    echo '<div class="section">';
    echo '<h2>🚀 EXECUTING RESET...</h2>';

    try {
        $db = getDbConnection();

        // Step 1: Delete all license keys and activations
        echo '<div class="command">';
        echo '<pre class="info">STEP 1: Clearing all license keys...</pre>';
        $result = $db->query("DELETE FROM licenses");
        echo '<pre class="success">✓ Deleted ' . $db->affected_rows . ' license keys</pre>';

        $result = $db->query("DELETE FROM license_activations");
        echo '<pre class="success">✓ Deleted ' . $db->affected_rows . ' license activations</pre>';
        echo '</div>';

        // Step 2: Clear setup data from system_settings
        echo '<div class="command">';
        echo '<pre class="info">STEP 2: Clearing setup data from system_settings...</pre>';
        $settingsToDelete = [
            'setup_complete',
            'hospital_name',
            'hospital_department',
            'hospital_phone',
            'hospital_email',
            'hospital_address',
            'hospital_address1',
            'hospital_address2',
            'hospital_city',
            'hospital_state',
            'hospital_pincode',
            'hospital_country',
            'auto_import_paths',
            'auto_import_interval',
            'auto_import_action'
        ];

        $placeholders = implode(',', array_fill(0, count($settingsToDelete), '?'));
        $stmt = $db->prepare("DELETE FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($settingsToDelete)), ...$settingsToDelete);
        $stmt->execute();
        echo '<pre class="success">✓ Deleted ' . $stmt->affected_rows . ' settings from system_settings</pre>';
        $stmt->close();
        echo '</div>';

        // Step 3: Clear setup data from settings table
        echo '<div class="command">';
        echo '<pre class="info">STEP 3: Clearing setup data from settings table...</pre>';
        $stmt = $db->prepare("DELETE FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($settingsToDelete)), ...$settingsToDelete);
        $stmt->execute();
        echo '<pre class="success">✓ Deleted ' . $stmt->affected_rows . ' settings from settings table</pre>';
        $stmt->close();
        echo '</div>';

        // Step 4: Reset all users - mark setup as incomplete
        echo '<div class="command">';
        echo '<pre class="info">STEP 4: Resetting all users...</pre>';

        // Check if setup_completed column exists
        $result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
        if ($result && $result->num_rows > 0) {
            $result = $db->query("UPDATE users SET setup_completed = 0");
            echo '<pre class="success">✓ Marked ' . $db->affected_rows . ' users as setup incomplete</pre>';
        } else {
            echo '<pre class="warning">⚠ setup_completed column does not exist yet (will be created by setup wizard)</pre>';
        }
        echo '</div>';

        // Step 5: Clear all sessions
        echo '<div class="command">';
        echo '<pre class="info">STEP 5: Clearing all active sessions...</pre>';
        $result = $db->query("DELETE FROM sessions");
        echo '<pre class="success">✓ Deleted ' . $db->affected_rows . ' active sessions</pre>';
        echo '</div>';

        // Step 6: Delete all users EXCEPT the first admin
        echo '<div class="command">';
        echo '<pre class="info">STEP 6: Removing extra users (keeping first admin)...</pre>';
        $result = $db->query("SELECT id, username, role FROM users ORDER BY id ASC LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $keepUserId = $row['id'];
            echo '<pre class="info">  Keeping user: ' . htmlspecialchars($row['username']) . ' (ID: ' . $keepUserId . ')</pre>';

            $stmt = $db->prepare("DELETE FROM users WHERE id != ?");
            $stmt->bind_param("i", $keepUserId);
            $stmt->execute();
            echo '<pre class="success">✓ Deleted ' . $stmt->affected_rows . ' extra users</pre>';
            $stmt->close();
        } else {
            echo '<pre class="warning">⚠ No users found in database</pre>';
        }
        echo '</div>';

        // Step 7: Show current database state
        echo '<div class="command">';
        echo '<pre class="info">STEP 7: Current database state...</pre>';

        $result = $db->query("SELECT COUNT(*) as cnt FROM licenses");
        $row = $result->fetch_assoc();
        echo '<pre>  Licenses: ' . $row['cnt'] . '</pre>';

        $result = $db->query("SELECT COUNT(*) as cnt FROM users WHERE is_active = 1");
        $row = $result->fetch_assoc();
        echo '<pre>  Active Users: ' . $row['cnt'] . '</pre>';

        $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'hospital_name' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo '<pre class="warning">  Hospital Name: ' . htmlspecialchars($row['setting_value']) . ' (should be empty!)</pre>';
        } else {
            echo '<pre class="success">  Hospital Name: [NOT SET] ✓</pre>';
        }

        $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'setup_complete' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            echo '<pre class="error">  Setup Complete: YES (should be NO!)</pre>';
        } else {
            echo '<pre class="success">  Setup Complete: NO ✓</pre>';
        }
        echo '</div>';

        echo '</div>'; // Close section

        // Success summary
        echo '<div class="section">';
        echo '<h2>✅ RESET COMPLETE!</h2>';
        echo '<pre class="success">Database is now ready for testing the complete flow:</pre>';
        echo '<pre>1. Create a new license key (or use existing one)</pre>';
        echo '<pre>2. npm start (to start Electron app)</pre>';
        echo '<pre>3. Enter license key → Should show Setup Wizard</pre>';
        echo '<pre>4. Complete wizard → Should redirect to patients page with tour</pre>';
        echo '<pre>5. Tour should automatically start</pre>';
        echo '<br>';
        echo '<pre class="info">👉 Next step: Start your app with "npm start"</pre>';
        echo '</div>';

        // Show what user credentials exist
        echo '<div class="section">';
        echo '<h2>👤 AVAILABLE USER CREDENTIALS</h2>';
        $result = $db->query("SELECT id, username, role, is_active FROM users ORDER BY id ASC");
        if ($result && $result->num_rows > 0) {
            echo '<pre class="info">Current users in database:</pre>';
            while ($row = $result->fetch_assoc()) {
                echo '<pre>  • ' . htmlspecialchars($row['username']) .
                     ' (Role: ' . htmlspecialchars($row['role']) .
                     ', Active: ' . ($row['is_active'] ? 'Yes' : 'No') . ')</pre>';
            }
            echo '<br><pre class="warning">⚠️ Default password for auto-created admin: admin123</pre>';
        } else {
            echo '<pre class="warning">No users found. A new admin will be created during license activation.</pre>';
        }
        echo '</div>';

    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ ERROR OCCURRED</h2>';
        echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '<pre class="error">File: ' . htmlspecialchars($e->getFile()) . '</pre>';
        echo '<pre class="error">Line: ' . $e->getLine() . '</pre>';
        echo '</div>';
    }

} else {
    // Show confirmation screen
    echo '<div class="section">';
    echo '<h2>📋 This script will:</h2>';
    echo '<pre>1. ❌ DELETE all license keys from the database</pre>';
    echo '<pre>2. ❌ DELETE all license activations</pre>';
    echo '<pre>3. ❌ CLEAR all hospital settings (name, address, etc.)</pre>';
    echo '<pre>4. ❌ CLEAR setup_complete flags from both tables</pre>';
    echo '<pre>5. 🔄 RESET all users to setup_completed = 0</pre>';
    echo '<pre>6. ❌ DELETE all active sessions (logout everyone)</pre>';
    echo '<pre>7. ❌ DELETE all users except the first admin</pre>';
    echo '<br>';
    echo '<pre class="warning">⚠️ This will completely reset your database to pre-setup state!</pre>';
    echo '<pre class="info">✓ Safe for testing environments</pre>';
    echo '<pre class="error">✗ DO NOT RUN ON PRODUCTION!</pre>';
    echo '</div>';

    echo '<div class="section">';
    echo '<h2>🎯 After running this:</h2>';
    echo '<pre>1. Create a new license key in your license management system</pre>';
    echo '<pre>2. Start the Electron app: npm start</pre>';
    echo '<pre>3. Enter the license key</pre>';
    echo '<pre>4. Complete the setup wizard with your hospital info</pre>';
    echo '<pre>5. The tour should start automatically on the patients page</pre>';
    echo '</div>';

    echo '<form method="get" action="">';
    echo '<input type="hidden" name="confirm" value="yes">';
    echo '<button type="submit" class="btn">🚀 YES, RESET DATABASE NOW</button>';
    echo '</form>';

    echo '<p class="warning">Click the button above to proceed with the reset.</p>';
}
?>

</body>
</html>

<?php
/**
 * QUICK RESET AND TEST
 * One-click solution: Resets database + Creates new license key
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');

function generateLicenseKey() {
    $prefix = 'DICOM';
    $parts = [];
    for ($i = 0; $i < 4; $i++) {
        $part = '';
        for ($j = 0; $j < 4; $j++) {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $part .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $parts[] = $part;
    }
    return $prefix . '-' . implode('-', $parts);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Quick Reset & Test</title>
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
        .license-key {
            background: #000;
            padding: 20px;
            margin: 15px 0;
            border: 3px solid #ffff00;
            font-size: 28px;
            text-align: center;
            letter-spacing: 3px;
            color: #ffff00;
            font-weight: bold;
            animation: glow 2s infinite alternate;
        }
        @keyframes glow {
            from { box-shadow: 0 0 5px #ffff00; }
            to { box-shadow: 0 0 20px #ffff00; }
        }
        .btn {
            background: #ffff00;
            color: #000;
            padding: 15px 30px;
            border: none;
            font-weight: bold;
            cursor: pointer;
            font-size: 18px;
            margin: 10px 0;
            display: inline-block;
            text-decoration: none;
        }
        .btn:hover {
            background: #ffaa00;
        }
        pre { margin: 5px 0; }
        .command { background: #000; padding: 10px; margin: 5px 0; border: 1px solid #333; }
        .copy-btn {
            background: #00aaff;
            color: #000;
            padding: 5px 15px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <h1>⚡ QUICK RESET & TEST</h1>

<?php
if (isset($_GET['go']) && $_GET['go'] === 'yes') {
    try {
        $db = getDbConnection();

        echo '<div class="section">';
        echo '<h2>🔄 STEP 1: RESETTING DATABASE...</h2>';

        // Clear licenses
        $db->query("DELETE FROM licenses");
        echo '<pre class="success">✓ Cleared all licenses</pre>';

        $db->query("DELETE FROM license_activations");
        echo '<pre class="success">✓ Cleared all activations</pre>';

        // Clear settings from both tables
        $settingsToDelete = [
            'setup_complete', 'hospital_name', 'hospital_department', 'hospital_phone',
            'hospital_email', 'hospital_address', 'hospital_address1', 'hospital_address2',
            'hospital_city', 'hospital_state', 'hospital_pincode', 'hospital_country',
            'auto_import_paths', 'auto_import_interval', 'auto_import_action'
        ];

        $placeholders = implode(',', array_fill(0, count($settingsToDelete), '?'));

        $stmt = $db->prepare("DELETE FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($settingsToDelete)), ...$settingsToDelete);
        $stmt->execute();
        echo '<pre class="success">✓ Cleared system_settings</pre>';
        $stmt->close();

        $stmt = $db->prepare("DELETE FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($settingsToDelete)), ...$settingsToDelete);
        $stmt->execute();
        echo '<pre class="success">✓ Cleared settings</pre>';
        $stmt->close();

        // Reset users
        $result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
        if ($result && $result->num_rows > 0) {
            $db->query("UPDATE users SET setup_completed = 0");
            echo '<pre class="success">✓ Reset all users</pre>';
        }

        // Clear sessions
        $db->query("DELETE FROM sessions");
        echo '<pre class="success">✓ Cleared all sessions</pre>';

        // Keep only first admin
        $result = $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $stmt = $db->prepare("DELETE FROM users WHERE id != ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            echo '<pre class="success">✓ Removed extra users</pre>';
            $stmt->close();
        }

        echo '</div>';

        // Create new license
        echo '<div class="section">';
        echo '<h2>🔑 STEP 2: CREATING NEW LICENSE KEY...</h2>';

        $licenseKey = generateLicenseKey();
        $customerName = 'Test Hospital';
        $customerEmail = 'test@example.com';
        $maxActivations = 1;
        $expiresAt = date('Y-m-d', strtotime('+1 year'));

        $stmt = $db->prepare("
            INSERT INTO licenses
            (license_key, customer_name, customer_email, license_type, max_activations, expires_at, is_active, created_at)
            VALUES (?, ?, ?, 'trial', ?, ?, 1, NOW())
        ");
        $stmt->bind_param("sssis", $licenseKey, $customerName, $customerEmail, $maxActivations, $expiresAt);
        $stmt->execute();
        $stmt->close();

        echo '<pre class="success">✓ License created successfully</pre>';
        echo '</div>';

        // Show license key prominently
        echo '<div class="section">';
        echo '<h2>🎉 YOUR NEW LICENSE KEY:</h2>';
        echo '<div class="license-key" id="licenseKey">' . htmlspecialchars($licenseKey) . '</div>';
        echo '<div style="text-align: center;">';
        echo '<button class="copy-btn" onclick="copyLicense()">📋 COPY TO CLIPBOARD</button>';
        echo '</div>';
        echo '</div>';

        // Instructions
        echo '<div class="section">';
        echo '<h2>🚀 NEXT STEPS:</h2>';
        echo '<pre class="info">1. Copy the license key above (click the COPY button)</pre>';
        echo '<pre class="info">2. Close this browser tab</pre>';
        echo '<pre class="info">3. Run: npm start (in your terminal)</pre>';
        echo '<pre class="info">4. Paste the license key when prompted</pre>';
        echo '<pre class="info">5. Complete the setup wizard:</pre>';
        echo '<pre>   → Enter hospital info (name, department, phone, email)</pre>';
        echo '<pre>   → Enter address (line 1, city, state)</pre>';
        echo '<pre>   → Create admin user (username, password)</pre>';
        echo '<pre>   → Add folder path (e.g., C:\\DICOM\\Incoming)</pre>';
        echo '<pre class="info">6. Click "Complete Setup"</pre>';
        echo '<pre class="info">7. You should be redirected to patients page</pre>';
        echo '<pre class="info">8. Tour should automatically start! 🎊</pre>';
        echo '</div>';

        // Show database state
        echo '<div class="section">';
        echo '<h2>📊 CURRENT DATABASE STATE:</h2>';

        $result = $db->query("SELECT COUNT(*) as cnt FROM licenses");
        $row = $result->fetch_assoc();
        echo '<pre>  Licenses: ' . $row['cnt'] . '</pre>';

        $result = $db->query("SELECT COUNT(*) as cnt FROM users WHERE is_active = 1");
        $row = $result->fetch_assoc();
        echo '<pre>  Active Users: ' . $row['cnt'] . '</pre>';

        $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'setup_complete' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            echo '<pre class="error">  Setup Complete: YES (ERROR!)</pre>';
        } else {
            echo '<pre class="success">  Setup Complete: NO ✓</pre>';
        }

        $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'hospital_name' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            echo '<pre class="error">  Hospital Configured: YES (ERROR!)</pre>';
        } else {
            echo '<pre class="success">  Hospital Configured: NO ✓</pre>';
        }

        echo '</div>';

        echo '<script>
        function copyLicense() {
            const licenseKey = document.getElementById("licenseKey").textContent;
            navigator.clipboard.writeText(licenseKey).then(() => {
                alert("License key copied to clipboard!\\n\\n" + licenseKey + "\\n\\nNow run: npm start");
            });
        }
        </script>';

    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ ERROR OCCURRED</h2>';
        echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '</div>';
    }

} else {
    // Confirmation screen
    echo '<div class="section">';
    echo '<h2>⚡ ONE-CLICK SETUP</h2>';
    echo '<pre class="info">This script will automatically:</pre>';
    echo '<pre>  1. ❌ DELETE all licenses</pre>';
    echo '<pre>  2. ❌ CLEAR all setup data</pre>';
    echo '<pre>  3. 🔄 RESET all users</pre>';
    echo '<pre>  4. 🔑 CREATE a new license key</pre>';
    echo '<pre>  5. 📋 DISPLAY the key for you to copy</pre>';
    echo '<br>';
    echo '<pre class="success">✓ Perfect for quick testing!</pre>';
    echo '<pre class="warning">⚠️ DO NOT RUN ON PRODUCTION!</pre>';
    echo '</div>';

    echo '<div class="section">';
    echo '<h2>🎯 TESTING FLOW:</h2>';
    echo '<pre>License Activation → Setup Wizard → Tour</pre>';
    echo '<br>';
    echo '<pre class="info">You will test:</pre>';
    echo '<pre>  • License key activation</pre>';
    echo '<pre>  • Setup wizard (4 steps)</pre>';
    echo '<pre>  • Hospital info entry</pre>';
    echo '<pre>  • User creation</pre>';
    echo '<pre>  • Automatic tour start</pre>';
    echo '</div>';

    echo '<form method="get" action="">';
    echo '<input type="hidden" name="go" value="yes">';
    echo '<button type="submit" class="btn">⚡ RESET & CREATE LICENSE NOW</button>';
    echo '</form>';
}
?>

</body>
</html>

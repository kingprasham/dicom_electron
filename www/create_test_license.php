<?php
/**
 * CREATE TEST LICENSE KEY
 * Generates a new license key for testing the setup wizard flow
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
    <title>Create Test License</title>
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
            border: 2px solid #00ff00;
            font-size: 24px;
            text-align: center;
            letter-spacing: 2px;
            color: #00ff00;
            font-weight: bold;
        }
        .btn {
            background: #00ff00;
            color: #000;
            padding: 10px 20px;
            border: none;
            font-weight: bold;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
            display: inline-block;
            text-decoration: none;
        }
        .btn:hover {
            background: #00cc00;
        }
        .btn-secondary {
            background: #00aaff;
        }
        .btn-secondary:hover {
            background: #0088dd;
        }
        pre { margin: 5px 0; }
        input[type="text"] {
            background: #000;
            color: #00ff00;
            border: 1px solid #00ff00;
            padding: 10px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            width: 300px;
        }
        input[type="number"] {
            background: #000;
            color: #00ff00;
            border: 1px solid #00ff00;
            padding: 10px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            width: 100px;
        }
        label {
            display: inline-block;
            width: 180px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <h1>🔑 CREATE TEST LICENSE KEY</h1>

<?php
if (isset($_POST['create'])) {
    try {
        $db = getDbConnection();

        // Generate license key
        $licenseKey = generateLicenseKey();
        $customerName = $_POST['customer_name'] ?? 'Test Hospital';
        $customerEmail = $_POST['customer_email'] ?? 'test@example.com';
        $maxActivations = (int)($_POST['max_activations'] ?? 1);
        $expiresAt = $_POST['expires_at'] ?? date('Y-m-d', strtotime('+1 year'));

        // Insert into database
        $stmt = $db->prepare("
            INSERT INTO licenses
            (license_key, customer_name, customer_email, license_type, max_activations, expires_at, is_active, created_at)
            VALUES (?, ?, ?, 'trial', ?, ?, 1, NOW())
        ");
        $stmt->bind_param("sssis", $licenseKey, $customerName, $customerEmail, $maxActivations, $expiresAt);
        $stmt->execute();
        $stmt->close();

        echo '<div class="section">';
        echo '<h2>✅ LICENSE CREATED SUCCESSFULLY!</h2>';
        echo '<div class="license-key">' . htmlspecialchars($licenseKey) . '</div>';
        echo '<pre class="success">License key has been created and saved to database.</pre>';
        echo '<br>';
        echo '<pre class="info">Customer Name: ' . htmlspecialchars($customerName) . '</pre>';
        echo '<pre class="info">Customer Email: ' . htmlspecialchars($customerEmail) . '</pre>';
        echo '<pre class="info">Max Activations: ' . $maxActivations . '</pre>';
        echo '<pre class="info">Expires: ' . htmlspecialchars($expiresAt) . '</pre>';
        echo '</div>';

        echo '<div class="section">';
        echo '<h2>📋 NEXT STEPS:</h2>';
        echo '<pre>1. Copy the license key above</pre>';
        echo '<pre>2. Start your Electron app: npm start</pre>';
        echo '<pre>3. Paste the license key in the activation form</pre>';
        echo '<pre>4. Complete the setup wizard</pre>';
        echo '<pre>5. Enjoy the tour!</pre>';
        echo '</div>';

        echo '<div class="section">';
        echo '<a href="create_test_license.php" class="btn">🔑 Create Another License</a>';
        echo '<a href="reset_for_testing.php" class="btn btn-secondary">🔄 Reset Database</a>';
        echo '</div>';

        // Show all licenses
        echo '<div class="section">';
        echo '<h2>📜 ALL LICENSES IN DATABASE</h2>';
        $result = $db->query("SELECT license_key, customer_name, max_activations, expires_at, is_active, created_at FROM licenses ORDER BY created_at DESC");
        if ($result && $result->num_rows > 0) {
            echo '<table style="width: 100%; border-collapse: collapse;">';
            echo '<tr style="border-bottom: 1px solid #00ff00;">';
            echo '<th style="text-align: left; padding: 10px;">License Key</th>';
            echo '<th style="text-align: left; padding: 10px;">Customer</th>';
            echo '<th style="text-align: left; padding: 10px;">Activations</th>';
            echo '<th style="text-align: left; padding: 10px;">Expires</th>';
            echo '<th style="text-align: left; padding: 10px;">Status</th>';
            echo '</tr>';

            while ($row = $result->fetch_assoc()) {
                $statusColor = $row['is_active'] ? 'success' : 'error';
                $statusText = $row['is_active'] ? 'Active' : 'Inactive';

                echo '<tr style="border-bottom: 1px solid #333;">';
                echo '<td style="padding: 10px; font-size: 12px;">' . htmlspecialchars($row['license_key']) . '</td>';
                echo '<td style="padding: 10px;">' . htmlspecialchars($row['customer_name']) . '</td>';
                echo '<td style="padding: 10px;">' . $row['max_activations'] . '</td>';
                echo '<td style="padding: 10px;">' . htmlspecialchars($row['expires_at']) . '</td>';
                echo '<td style="padding: 10px;" class="' . $statusColor . '">' . $statusText . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<pre class="warning">No licenses found in database.</pre>';
        }
        echo '</div>';

    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ ERROR CREATING LICENSE</h2>';
        echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '</div>';
    }
} else {
    // Show form
    echo '<div class="section">';
    echo '<h2>📝 Enter License Details</h2>';
    echo '<form method="post" action="">';
    echo '<div>';
    echo '<label>Customer Name:</label>';
    echo '<input type="text" name="customer_name" value="Test Hospital" required>';
    echo '</div>';
    echo '<div>';
    echo '<label>Customer Email:</label>';
    echo '<input type="text" name="customer_email" value="test@example.com" required>';
    echo '</div>';
    echo '<div>';
    echo '<label>Max Activations:</label>';
    echo '<input type="number" name="max_activations" value="1" min="1" required>';
    echo '</div>';
    echo '<div>';
    echo '<label>Expires At:</label>';
    echo '<input type="text" name="expires_at" value="' . date('Y-m-d', strtotime('+1 year')) . '" placeholder="YYYY-MM-DD" required>';
    echo '</div>';
    echo '<br>';
    echo '<button type="submit" name="create" class="btn">🚀 Generate License Key</button>';
    echo '</form>';
    echo '</div>';

    echo '<div class="section">';
    echo '<h2>📌 IMPORTANT NOTES:</h2>';
    echo '<pre class="info">• License key will be automatically generated</pre>';
    echo '<pre class="info">• Format: DICOM-XXXX-XXXX-XXXX-XXXX</pre>';
    echo '<pre class="info">• License type: Trial (for testing)</pre>';
    echo '<pre class="info">• Status: Active</pre>';
    echo '</div>';

    echo '<div class="section">';
    echo '<a href="reset_for_testing.php" class="btn btn-secondary">🔄 Reset Database First</a>';
    echo '</div>';

    // Show existing licenses
    try {
        $db = getDbConnection();
        $result = $db->query("SELECT COUNT(*) as cnt FROM licenses");
        if ($result) {
            $row = $result->fetch_assoc();
            if ($row['cnt'] > 0) {
                echo '<div class="section">';
                echo '<h2>📜 EXISTING LICENSES</h2>';
                echo '<pre class="warning">Found ' . $row['cnt'] . ' existing license(s) in database.</pre>';
                echo '<pre class="info">Tip: Run reset_for_testing.php to clear all licenses before testing.</pre>';

                $result = $db->query("SELECT license_key, customer_name, is_active FROM licenses ORDER BY created_at DESC LIMIT 5");
                if ($result && $result->num_rows > 0) {
                    echo '<br><pre>Recent licenses:</pre>';
                    while ($row = $result->fetch_assoc()) {
                        $status = $row['is_active'] ? '✓' : '✗';
                        echo '<pre>  ' . $status . ' ' . htmlspecialchars($row['license_key']) . ' (' . htmlspecialchars($row['customer_name']) . ')</pre>';
                    }
                }
                echo '</div>';
            }
        }
    } catch (Exception $e) {
        // Ignore - table might not exist yet
    }
}
?>

</body>
</html>

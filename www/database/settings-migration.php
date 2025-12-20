<?php
/**
 * Run Database Migration for Address Fields and Security Settings
 * Execute this file ONCE via browser: http://localhost:8080/database/settings-migration.php
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - Address & Security Settings</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #1a1f3a; color: #fff; }
        .container { max-width: 800px; margin: 0 auto; background: rgba(255,255,255,0.05); padding: 30px; border-radius: 15px; border: 1px solid rgba(255,255,255,0.1); }
        .success { color: #28a745; padding: 10px; background: rgba(40, 167, 69, 0.2); border: 1px solid #28a745; border-radius: 4px; margin: 10px 0; }
        .error { color: #dc3545; padding: 10px; background: rgba(220, 53, 69, 0.2); border: 1px solid #dc3545; border-radius: 4px; margin: 10px 0; }
        .info { color: #17a2b8; padding: 10px; background: rgba(23, 162, 184, 0.2); border: 1px solid #17a2b8; border-radius: 4px; margin: 10px 0; }
        code { background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px; }
        h1 { color: #fff; }
        h2 { color: #aaa; margin-top: 30px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; }
        a { color: #0d6efd; }
        .btn { background: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 10px; }
        .btn:hover { background: #0b5ed7; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Settings Migration - Address & Security</h1>
        <p>Adding hospital address fields and private settings security...</p>

<?php
$mysqli = getDbConnection();
$errors = [];
$success = [];

// Migration Step 1: Add hospital address fields
try {
    echo "<h2>Step 1: Adding Hospital Address Fields...</h2>";

    $addressSettings = [
        'hospital_address1',
        'hospital_address2',
        'hospital_address3',
        'hospital_city',
        'hospital_state',
        'hospital_pincode',
    ];

    $stmt = $mysqli->prepare("
        INSERT IGNORE INTO system_settings (setting_key, setting_value)
        VALUES (?, '')
    ");

    foreach ($addressSettings as $key) {
        $stmt->bind_param("s", $key);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success[] = "Added: {$key}";
                echo "<div class='success'>Added: <code>{$key}</code></div>";
            } else {
                echo "<div class='info'>Already exists: <code>{$key}</code></div>";
            }
        }
    }
    $stmt->close();

} catch (Exception $e) {
    $errors[] = "Step 1 Error: " . $e->getMessage();
    echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Step 2: Security settings note
echo "<h2>Step 2: Security Settings</h2>";
echo "<div class='info'>Security settings (PIN, secret) will be created automatically when you first set up a PIN in Private Settings.</div>";

// Verification
echo "<h2>Verification</h2>";

try {
    // Check address fields exist
    $result = $mysqli->query("SELECT setting_key FROM system_settings WHERE setting_key IN ('hospital_address1', 'hospital_address2', 'hospital_address3', 'hospital_city', 'hospital_state', 'hospital_pincode')");
    $addressCount = $result->num_rows;

    if ($addressCount >= 6) {
        echo "<div class='success'>All address fields configured ($addressCount/6 settings)</div>";
    } else {
        echo "<div class='info'>Address fields: $addressCount of 6 configured (remaining will be added on save)</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>Verification Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Summary
echo "<h2>Migration Summary</h2>";

if (empty($errors)) {
    echo "<div class='success'>";
    echo "<h3>Migration Completed Successfully!</h3>";
    echo "<p><strong>New Settings Pages:</strong></p>";
    echo "<ul>";
    echo "<li><strong>General Settings</strong> - Hospital Info, Address, Print Settings</li>";
    echo "<li><strong>Folder Monitoring</strong> - Auto-folder monitoring configuration</li>";
    echo "<li><strong>Private Settings</strong> - DICOM Nodes, Printers, Orthanc (Protected by PIN)</li>";
    echo "<li><strong>Backup & Import</strong> - Cloud backup and study import</li>";
    echo "</ul>";
    echo "<p><a href='../admin/general-settings.php' class='btn'>Go to General Settings</a></p>";
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<h3>Migration Completed with Errors</h3>";
    echo "<p>Errors encountered:</p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}
?>

        <hr style="border-color: rgba(255,255,255,0.1); margin-top: 30px;">
        <p><small style="color: #888;">This migration is safe to run multiple times. Existing settings will not be overwritten.</small></p>
    </div>
</body>
</html>

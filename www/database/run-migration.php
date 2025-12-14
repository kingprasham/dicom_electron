<?php
/**
 * Run Database Migration for Multi-Clinic System
 * Execute this file ONCE via browser: http://localhost:8080/database/run-migration.php
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - Multi-Clinic System</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #28a745; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin: 10px 0; }
        .error { color: #dc3545; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 10px 0; }
        .info { color: #0c5460; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px; margin: 10px 0; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 4px; overflow-x: auto; }
        h1 { color: #333; }
        h2 { color: #666; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Multi-Clinic System Migration</h1>
        <p>Running database migration to add clinic location tracking...</p>

<?php
$mysqli = getDbConnection();
$errors = [];
$success = [];

// Migration Step 1: Add clinic_location column
try {
    echo "<h2>Step 1: Adding clinic_location column...</h2>";
    
    $sql = "ALTER TABLE `cached_studies` 
            ADD COLUMN `clinic_location` VARCHAR(100) DEFAULT NULL AFTER `accession_number`,
            ADD INDEX `clinic_location` (`clinic_location`)";
    
    if ($mysqli->query($sql)) {
        $success[] = "✅ Added clinic_location column to cached_studies";
        echo "<div class='success'>✅ Added clinic_location column to cached_studies</div>";
    } else {
        // Check if column already exists
        if (strpos($mysqli->error, 'Duplicate column') !== false) {
            $success[] = "ℹ️  Column clinic_location already exists";
            echo "<div class='info'>ℹ️  Column clinic_location already exists</div>";
        } else {
            throw new Exception($mysqli->error);
        }
    }
} catch (Exception $e) {
    $errors[] = "Step 1 Error: " . $e->getMessage();
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Migration Step 2: Add clinic settings
try {
    echo "<h2>Step 2: Adding clinic settings...</h2>";
    
    $settings = [
        ['clinic_location_name', 'Main Clinic', 'clinic'],
        ['multi_clinic_mode', 'false', 'clinic'],
        ['clinic_locations_list', '[]', 'clinic']
    ];
    
    foreach ($settings as $setting) {
        $stmt = $mysqli->prepare("
            INSERT INTO hospital_settings (setting_key, setting_value, setting_group) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->bind_param("sss", $setting[0], $setting[1], $setting[2]);
        
        if ($stmt->execute()) {
            $success[] = "✅ Added setting: {$setting[0]}";
            echo "<div class='success'>✅ Added setting: <code>{$setting[0]}</code> = {$setting[1]}</div>";
        }
        $stmt->close();
    }
} catch (Exception $e) {
    $errors[] = "Step 2 Error: " . $e->getMessage();
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Migration Step 3: Update existing studies
try {
    echo "<h2>Step 3: Updating existing studies...</h2>";
    
    // Get current clinic location from settings
    $stmt = $mysqli->prepare("SELECT setting_value FROM hospital_settings WHERE setting_key = 'clinic_location_name'");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $clinicLocation = $result['setting_value'] ?? 'Main Clinic';
    $stmt->close();
    
    $sql = "UPDATE `cached_studies` 
            SET `clinic_location` = '$clinicLocation' 
            WHERE `clinic_location` IS NULL";
    
    if ($mysqli->query($sql)) {
        $affectedRows = $mysqli->affected_rows;
        $success[] = "✅ Updated $affectedRows studies with clinic location";
        echo "<div class='success'>✅ Updated <strong>$affectedRows</strong> studies with clinic location: <code>$clinicLocation</code></div>";
    }
} catch (Exception $e) {
    $errors[] = "Step 3 Error: " . $e->getMessage();
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Verification
echo "<h2>Verification</h2>";

try {
    // Check column exists
    $result = $mysqli->query("SHOW COLUMNS FROM `cached_studies` LIKE 'clinic_location'");
    if ($result->num_rows > 0) {
        echo "<div class='success'>✅ Column <code>clinic_location</code> exists</div>";
    } else {
        echo "<div class='error'>❌ Column <code>clinic_location</code> NOT found</div>";
    }
    
    // Check settings exist
    $result = $mysqli->query("SELECT * FROM hospital_settings WHERE setting_group = 'clinic'");
    $count = $result->num_rows;
    if ($count >= 2) {
        echo "<div class='success'>✅ Clinic settings configured ($count settings)</div>";
    } else {
        echo "<div class='error'>❌ Clinic settings missing (found $count)</div>";
    }
    
    // Check sample studies
    $result = $mysqli->query("SELECT COUNT(*) as total, 
                               SUM(CASE WHEN clinic_location IS NOT NULL THEN 1 ELSE 0 END) as with_location 
                               FROM cached_studies");
    $stats = $result->fetch_assoc();
    echo "<div class='info'>
        📊 Studies: <strong>{$stats['total']}</strong> total, 
        <strong>{$stats['with_location']}</strong> with clinic location assigned
    </div>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Verification Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Summary
echo "<h2>Migration Summary</h2>";

if (empty($errors)) {
    echo "<div class='success'>";
    echo "<h3>🎉 Migration Completed Successfully!</h3>";
    echo "<p><strong>Next Steps:</strong></p>";
    echo "<ol>";
    echo "<li>Refresh your browser (Ctrl+F5)</li>";
    echo "<li>Go to <strong>Admin → Settings → Clinic Location Settings</strong></li>";
    echo "<li>Configure your clinic name and multi-clinic mode</li>";
    echo "<li>View Patient Studies to see accession number and clinic location</li>";
    echo "</ol>";
    echo "<p><a href='../admin/settings.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 10px;'>Go to Settings →</a></p>";
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<h3>⚠️ Migration Completed with Errors</h3>";
    echo "<p>Errors encountered:</p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

if (!empty($success)) {
    echo "<div class='info'><p><strong>Successful operations:</strong></p><ul>";
    foreach ($success as $msg) {
        echo "<li>$msg</li>";
    }
    echo "</ul></div>";
}
?>

        <hr>
        <p><small>⚠️ Only run this migration once. Subsequent runs are safe but unnecessary.</small></p>
    </div>
</body>
</html>

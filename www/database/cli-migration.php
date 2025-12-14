<?php
/**
 * Command-Line Database Migration for Multi-Clinic System
 * Run via: php www/database/cli-migration.php
 */

// Set CLI mode
$isCLI = php_sapi_name() === 'cli';
if (!$isCLI) {
    die("This script must be run from command line\n");
}

define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║     Multi-Clinic System Database Migration              ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$mysqli = getDbConnection();
$errors = [];
$success = [];

// Migration Step 1: Add clinic_location column
echo "[Step 1] Adding clinic_location column to cached_studies...\n";
try {
    $sql = "ALTER TABLE `cached_studies` 
            ADD COLUMN `clinic_location` VARCHAR(100) DEFAULT NULL AFTER `accession_number`,
            ADD INDEX `clinic_location` (`clinic_location`)";
    
    if ($mysqli->query($sql)) {
        echo "  ✓ Added clinic_location column\n";
        $success[] = "Added clinic_location column";
    }
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "  ℹ Column clinic_location already exists\n";
        $success[] = "Column already exists";
    } else {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        $errors[] = "Step 1: " . $e->getMessage();
    }
}

// Migration Step 2: Add clinic settings
echo "\n[Step 2] Adding clinic settings...\n";
try {
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
            echo "  ✓ Added setting: {$setting[0]} = {$setting[1]}\n";
            $success[] = "Added setting: {$setting[0]}";
        }
        $stmt->close();
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $errors[] = "Step 2: " . $e->getMessage();
}

// Migration Step 3: Update existing studies
echo "\n[Step 3] Updating existing studies with clinic location...\n";
try {
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
        echo "  ✓ Updated $affectedRows studies with clinic location: $clinicLocation\n";
        $success[] = "Updated $affectedRows studies";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $errors[] = "Step 3: " . $e->getMessage();
}

// Verification
echo "\n[Verification]\n";
try {
    // Check column exists
    $result = $mysqli->query("SHOW COLUMNS FROM `cached_studies` LIKE 'clinic_location'");
    if ($result->num_rows > 0) {
        echo "  ✓ Column clinic_location exists\n";
    } else {
        echo "  ✗ Column clinic_location NOT found\n";
    }
    
    // Check settings exist
    $result = $mysqli->query("SELECT * FROM hospital_settings WHERE setting_group = 'clinic'");
    $count = $result->num_rows;
    if ($count >= 2) {
        echo "  ✓ Clinic settings configured ($count settings)\n";
    } else {
        echo "  ✗ Clinic settings missing (found $count)\n";
    }
    
    // Check sample studies
    $result = $mysqli->query("SELECT COUNT(*) as total, 
                               SUM(CASE WHEN clinic_location IS NOT NULL THEN 1 ELSE 0 END) as with_location 
                               FROM cached_studies");
    $stats = $result->fetch_assoc();
    echo "  ℹ Studies: {$stats['total']} total, {$stats['with_location']} with clinic location\n";
    
} catch (Exception $e) {
    echo "  ✗ Verification Error: " . $e->getMessage() . "\n";
}

// Summary
echo "\n╔══════════════════════════════════════════════════════════╗\n";
if (empty($errors)) {
    echo "║                  ✓ MIGRATION SUCCESSFUL                  ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
    echo "Next Steps:\n";
    echo "  1. Refresh your browser (Ctrl+F5)\n";
    echo "  2. Go to Admin → Settings → Clinic Location Settings\n";
    echo "  3. Configure your clinic name\n";
    echo "  4. View Patient Studies to see accession numbers\n\n";
} else {
    echo "║              ⚠ MIGRATION COMPLETED WITH ERRORS           ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
    echo "Errors:\n";
    foreach ($errors as $error) {
        echo "  • $error\n";
    }
    echo "\n";
}

if (!empty($success)) {
    echo "Successful operations:\n";
    foreach ($success as $msg) {
        echo "  • $msg\n";
    }
    echo "\n";
}

echo "Migration complete!\n";

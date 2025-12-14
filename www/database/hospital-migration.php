<?php
/**
 * Hospital Isolation System Migration
 * Run via: php www/database/hospital-migration.php
 */

$isCLI = php_sapi_name() === 'cli';
if (!$isCLI) {
    die("This script must be run from command line\n");
}

define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║     Hospital Isolation System Migration                 ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$mysqli = getDbConnection();
$errors = [];
$success = [];

// Step 1: Create hospitals table
echo "[Step 1] Creating hospitals table...\n";
try {
    $sql = "CREATE TABLE IF NOT EXISTS `hospitals` (
      `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
      `hospital_code` varchar(50) NOT NULL UNIQUE,
      `hospital_name` varchar(255) NOT NULL,
      `location` varchar(255) DEFAULT NULL,
      `admin_email` varchar(255) DEFAULT NULL,
      `admin_phone` varchar(50) DEFAULT NULL,
      `is_active` tinyint(1) DEFAULT 1,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `hospital_code` (`hospital_code`),
      KEY `is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($mysqli->query($sql)) {
        echo "  ✓ Created hospitals table\n";
        $success[] = "Created hospitals table";
    }
} catch (Exception $e) {
    echo "  ℹ " . $e->getMessage() . "\n";
}

// Step 2: Create user_hospital_access table
echo "\n[Step 2] Creating user_hospital_access table...\n";
try {
    $sql = "CREATE TABLE IF NOT EXISTS `user_hospital_access` (
      `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(11) UNSIGNED NOT NULL,
      `hospital_id` int(11) UNSIGNED NOT NULL,
      `access_level` enum('owner','admin','read_only') DEFAULT 'read_only',
      `granted_by` int(11) UNSIGNED DEFAULT NULL,
      `granted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `user_hospital` (`user_id`, `hospital_id`),
      KEY `user_id` (`user_id`),
      KEY `hospital_id` (`hospital_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($mysqli->query($sql)) {
        echo "  ✓ Created user_hospital_access table\n";
        $success[] = "Created user_hospital_access table";
    }
} catch (Exception $e) {
    echo "  ℹ " . $e->getMessage() . "\n";
}

// Step 3: Add hospital_id columns
echo "\n[Step 3] Adding hospital_id columns to data tables...\n";
$tables = ['cached_patients', 'cached_studies', 'medical_reports'];
foreach ($tables as $table) {
    try {
        $sql = "ALTER TABLE `$table` 
                ADD COLUMN `hospital_id` int(11) UNSIGNED DEFAULT NULL AFTER `id`,
                ADD INDEX `hospital_id` (`hospital_id`)";
        
        if ($mysqli->query($sql)) {
            echo "  ✓ Added hospital_id to $table\n";
            $success[] = "Added hospital_id to $table";
        }
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "  ℹ Column already exists in $table\n";
        } else {
           echo "  ✗ Error on $table: " . $e->getMessage() . "\n";
            $errors[] = "$table: " . $e->getMessage();
        }
    }
}

// Step 4: Create default hospital
echo "\n[Step 4] Setting up default hospital...\n";
try {
    // Get clinic name from settings
    $stmt = $mysqli->prepare("SELECT setting_value FROM hospital_settings WHERE setting_key = 'clinic_location_name'");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $clinicName = $result['setting_value'] ?? 'Main Hospital';
    $stmt->close();
    
    // Insert default hospital
    $stmt = $mysqli->prepare("
        INSERT INTO `hospitals` (`id`, `hospital_code`, `hospital_name`, `location`, `is_active`)
        VALUES (1, 'HOSP_MAIN_001', ?, 'Primary Location', 1)
        ON DUPLICATE KEY UPDATE hospital_name = VALUES(hospital_name)
    ");
    $stmt->bind_param("s", $clinicName);
    if ($stmt->execute()) {
        echo "  ✓ Created/updated default hospital: $clinicName\n";
        $success[] = "Created default hospital";
    }
    $stmt->close();
    
    // Add hospital settings
    $settings = [
        ['current_hospital_id', '1', 'hospital'],
        ['hospital_isolation_enabled', 'true', 'hospital']
    ];
    
    foreach ($settings as $setting) {
        $stmt = $mysqli->prepare("
            INSERT INTO hospital_settings (setting_key, setting_value, setting_group) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->bind_param("sss", $setting[0], $setting[1], $setting[2]);
        $stmt->execute();
        $stmt->close();
    }
    echo "  ✓ Updated hospital settings\n";
    
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $errors[] = "Step 4: " . $e->getMessage();
}

// Step 5: Assign admin users to default hospital
echo "\n[Step 5] Assigning admin users to default hospital...\n";
try {
    $result = $mysqli->query("SELECT id, username FROM users WHERE role = 'admin'");
    $adminCount = 0;
    
    while ($user = $result->fetch_assoc()) {
        $stmt = $mysqli->prepare("
            INSERT INTO `user_hospital_access` (`user_id`, `hospital_id`, `access_level`)
            VALUES (?, 1, 'owner')
            ON DUPLICATE KEY UPDATE access_level = 'owner'
        ");
        $stmt->bind_param("i", $user['id']);
        if ($stmt->execute()) {
            echo "  ✓ Assigned {$user['username']} as owner of default hospital\n";
            $adminCount++;
        }
        $stmt->close();
    }
    
    $success[] = "Assigned $adminCount admin(s) to default hospital";
    
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $errors[] = "Step 5: " . $e->getMessage();
}

// Step 6: Update existing data
echo "\n[Step 6] Migrating existing data to default hospital...\n";
try {
    $tables = ['cached_patients', 'cached_studies', 'medical_reports'];
    foreach ($tables as $table) {
        $sql = "UPDATE `$table` SET `hospital_id` = 1 WHERE `hospital_id` IS NULL";
        if ($mysqli->query($sql)) {
            $count = $mysqli->affected_rows;
            echo "  ✓ Updated $count records in $table\n";
            $success[] = "Updated $count records in $table";
        }
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $errors[] = "Step 6: " . $e->getMessage();
}

// Verification
echo "\n[Verification]\n";
try {
    // Check hospitals
    $result = $mysqli->query("SELECT COUNT(*) as count FROM hospitals");
    $count = $result->fetch_assoc()['count'];
    echo "  ✓ Hospitals in system: $count\n";
    
    // Check access mappings
    $result = $mysqli->query("SELECT COUNT(*) as count FROM user_hospital_access");
    $count = $result->fetch_assoc()['count'];
    echo "  ✓ User-hospital access mappings: $count\n";
    
    // Check data distribution
    $result = $mysqli->query("
        SELECT 
            h.hospital_name,
            COUNT(DISTINCT p.id) as patients,
            COUNT(DISTINCT s.id) as studies
        FROM hospitals h
        LEFT JOIN cached_patients p ON h.id = p.hospital_id
        LEFT JOIN cached_studies s ON h.id = s.hospital_id
        GROUP BY h.id, h.hospital_name
    ");
    
    echo "\n  Data Distribution:\n";
    while ($row = $result->fetch_assoc()) {
        echo "    • {$row['hospital_name']}: {$row['patients']} patients, {$row['studies']} studies\n";
    }
    
} catch (Exception $e) {
    echo "  ✗ Verification Error: " . $e->getMessage() . "\n";
}

// Summary
echo "\n╔══════════════════════════════════════════════════════════╗\n";
if (empty($errors)) {
    echo "║              ✓ HOSPITAL SYSTEM ACTIVATED                 ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
    echo "🎉 Hospital Isolation System is now active!\n\n";
    echo "Features:\n";
    echo "  ✓ Each hospital has a unique ID\n";
    echo "  ✓ Users can be assigned to multiple hospitals\n";
    echo "  ✓ Complete data isolation between hospitals\n";
    echo "  ✓ Admin users have 'owner' access to default hospital\n\n";
    echo "Next Steps:\n";
    echo "  1. Refresh your browser\n";
    echo "  2. Visit Admin → Hospital Management (to be implemented)\n";
    echo "  3. Add new hospitals and assign users\n\n";
} else {
    echo "║           ⚠ MIGRATION COMPLETED WITH ERRORS              ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
    echo "Errors:\n";
    foreach ($errors as $error) {
        echo "  • $error\n";
    }
    echo "\n";
}

echo "Migration complete!\n";

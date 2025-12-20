<?php
/**
 * Run Setup Wizard Migration
 * Applies migration 011 to fix setup wizard requirements
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Setup Wizard Migration ===\n\n";

try {
    $db = getDbConnection();

    // Step 1: Create settings table if not exists
    echo "1. Creating settings table...\n";
    $db->query("
        CREATE TABLE IF NOT EXISTS `settings` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(100) NOT NULL,
            `setting_value` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "   Done.\n";

    // Step 2: Add setup_completed column to users if not exists
    echo "2. Adding setup_completed column to users...\n";
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
    if ($result->num_rows == 0) {
        $db->query("ALTER TABLE `users` ADD COLUMN `setup_completed` TINYINT(1) NOT NULL DEFAULT 0");
        echo "   Column added.\n";
    } else {
        echo "   Column already exists.\n";
    }

    // Step 3: Add is_super_admin column to users if not exists
    echo "3. Adding is_super_admin column to users...\n";
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
    if ($result->num_rows == 0) {
        $db->query("ALTER TABLE `users` ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0");
        echo "   Column added.\n";
    } else {
        echo "   Column already exists.\n";
    }

    // Step 4: Check if hospital_name exists and has value
    echo "4. Checking hospital configuration...\n";
    $result = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'hospital_name' AND setting_value != '' LIMIT 1");
    $hospitalConfigured = ($result && $result->num_rows > 0);

    if ($hospitalConfigured) {
        echo "   Hospital is already configured.\n";
        // Mark all admins as setup completed
        $db->query("UPDATE users SET setup_completed = 1 WHERE role = 'admin'");
        echo "   Marked admin users as setup completed.\n";
    } else {
        echo "   Hospital NOT configured - setup wizard will be shown.\n";
        // Make sure setup_complete flag is removed
        $db->query("DELETE FROM settings WHERE setting_key = 'setup_complete'");
        // Mark all admins as NOT having completed setup
        $db->query("UPDATE users SET setup_completed = 0 WHERE role = 'admin'");
        echo "   Cleared setup_complete flag and marked admins for setup.\n";
    }

    echo "\n=== Migration Complete ===\n";
    echo "\nYou can now:\n";
    echo "1. Go to /activate-license.php to activate a new license\n";
    echo "2. After activation, the setup wizard will appear\n";
    echo "3. Complete the setup wizard to configure your hospital\n";
    echo "4. After setup, the tour will start on the patients page\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
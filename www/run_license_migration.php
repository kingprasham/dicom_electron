<?php
/**
 * Run Database Migrations
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

echo "Running Database Migrations...\n\n";

$db = getDbConnection();

// First, add columns to users table if they don't exist
echo "Checking users table columns...\n";

// Check and add is_super_admin
$result = $db->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
if ($result->num_rows === 0) {
    $db->query("ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0");
    echo "  Added is_super_admin column\n";
} else {
    echo "  is_super_admin column exists\n";
}

// Check and add setup_completed
$result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
if ($result->num_rows === 0) {
    $db->query("ALTER TABLE users ADD COLUMN setup_completed TINYINT(1) DEFAULT 0");
    echo "  Added setup_completed column\n";
} else {
    echo "  setup_completed column exists\n";
}

// List of migrations to run
$migrations = [
    '009_license_system.sql',
    '010_super_admin_setup.sql'
];

foreach ($migrations as $migrationFile) {
    echo "\nRunning: $migrationFile\n";
    $sqlPath = __DIR__ . '/database/migrations/' . $migrationFile;
    
    if (!file_exists($sqlPath)) {
        echo "  SKIPPED (file not found)\n";
        continue;
    }
    
    $sql = file_get_contents($sqlPath);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    $success = 0;
    $errors = 0;

    foreach($statements as $stmt) {
        // Skip comments and empty statements
        if (empty($stmt) || strpos(trim($stmt), '--') === 0) {
            continue;
        }
        
        if ($db->query($stmt)) {
            $success++;
            echo ".";
        } else {
            $errors++;
            // Only show non-duplicate errors
            if (strpos($db->error, 'Duplicate') === false && 
                strpos($db->error, 'already exists') === false) {
                echo "\n  WARNING: " . $db->error . "\n";
            }
        }
    }
    echo "\n  Completed: $success OK\n";
}

// Create or update super admin user
echo "\nSetting up Super Admin user...\n";
$passwordHash = password_hash('12345', PASSWORD_DEFAULT);

// Check if superadmin exists
$result = $db->query("SELECT id FROM users WHERE username = 'superadmin'");
if ($result->num_rows === 0) {
    // Create new super admin
    $stmt = $db->prepare("
        INSERT INTO users (username, email, password_hash, role, is_active, is_super_admin, setup_completed)
        VALUES ('superadmin', 'superadmin@dicomviewer.local', ?, 'admin', 1, 1, 1)
    ");
    $stmt->bind_param("s", $passwordHash);
    $stmt->execute();
    $stmt->close();
    echo "  Created superadmin user\n";
} else {
    // Update existing
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, is_super_admin = 1, setup_completed = 1 WHERE username = 'superadmin'");
    $stmt->bind_param("s", $passwordHash);
    $stmt->execute();
    $stmt->close();
    echo "  Updated superadmin user\n";
}

echo "\n========================================\n";
echo "All migrations complete!\n";
echo "Super Admin: superadmin / 12345\n";
echo "========================================\n";

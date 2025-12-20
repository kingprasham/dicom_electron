<?php
// Simple migration script
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

$db = getDbConnection();
echo "Starting migration...\n";

// Add columns to users table
try {
    $db->query("ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0");
    echo "Added is_super_admin column\n";
} catch (Exception $e) {
    echo "is_super_admin column may already exist\n";
}

try {
    $db->query("ALTER TABLE users ADD COLUMN setup_completed TINYINT(1) DEFAULT 0");
    echo "Added setup_completed column\n";
} catch (Exception $e) {
    echo "setup_completed column may already exist\n";
}

// Create activity_log table
$sql = "CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(32),
    machine_id VARCHAR(64),
    user_id INT,
    user_name VARCHAR(255),
    event_type VARCHAR(50) NOT NULL,
    event_category VARCHAR(50) DEFAULT 'system',
    event_data TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";
if ($db->query($sql)) {
    echo "activity_log table OK\n";
} else {
    echo "activity_log: " . $db->error . "\n";
}

// Create sample_data table
$sql = "CREATE TABLE IF NOT EXISTS sample_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data_type VARCHAR(50),
    data_content TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";
if ($db->query($sql)) {
    echo "sample_data table OK\n";
} else {
    echo "sample_data: " . $db->error . "\n";
}

// Create onboarding_progress table
$sql = "CREATE TABLE IF NOT EXISTS onboarding_progress (
    id INT PRIMARY KEY DEFAULT 1,
    license_key VARCHAR(32),
    current_step INT DEFAULT 1,
    completed_steps TEXT,
    sample_data_created TINYINT(1) DEFAULT 0,
    completed_at DATETIME
)";
if ($db->query($sql)) {
    echo "onboarding_progress table OK\n";
} else {
    echo "onboarding_progress: " . $db->error . "\n";
}

$db->query("INSERT IGNORE INTO onboarding_progress (id, completed_steps) VALUES (1, '[]')");

// Create super admin
$passwordHash = password_hash('12345', PASSWORD_DEFAULT);
$result = $db->query("SELECT id FROM users WHERE username = 'superadmin'");

if ($result->num_rows === 0) {
    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, is_active, is_super_admin, setup_completed) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $username = 'superadmin';
    $email = 'superadmin@local';
    $role = 'admin';
    $active = 1;
    $superadmin = 1;
    $setup = 1;
    $stmt->bind_param("ssssiii", $username, $email, $passwordHash, $role, $active, $superadmin, $setup);
    $stmt->execute();
    echo "Created superadmin user\n";
} else {
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, is_super_admin = 1 WHERE username = 'superadmin'");
    $stmt->bind_param("s", $passwordHash);
    $stmt->execute();
    echo "Updated superadmin user\n";
}

echo "\nDone! Login: superadmin / 12345\n";

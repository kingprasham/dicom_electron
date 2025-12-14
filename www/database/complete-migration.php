<?php
/**
 * Complete Database Migration Runner
 * Automatically sets up all hospital system tables
 */

define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

echo "=== Complete Database Migration ===\n\n";

$mysqli = getDbConnection();

// Disable foreign key checks temporarily
$mysqli->query("SET FOREIGN_KEY_CHECKS=0");

$success = 0;
$errors = [];

// MIGRATION 1: Add clinic_location to cached_studies
echo "[1/8] Adding clinic_location column...\n";
try {
    $result = $mysqli->query("SHOW COLUMNS FROM cached_studies LIKE 'clinic_location'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE cached_studies ADD COLUMN clinic_location VARCHAR(100) DEFAULT NULL");
        echo "  OK: Added clinic_location\n";
    } else {
        echo "  SKIP: clinic_location already exists\n";
    }
    $success++;
} catch (Exception $e) {
    $errors[] = "clinic_location: " . $mysqli->error;
    echo "  ERROR: " . $mysqli->error . "\n";
}

// MIGRATION 2: Create hospitals table
echo "\n[2/8] Creating hospitals table...\n";
$sql = "CREATE TABLE IF NOT EXISTS hospitals (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    hospital_code VARCHAR(50) NOT NULL,
    hospital_name VARCHAR(255) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    admin_email VARCHAR(255) DEFAULT NULL,
    admin_phone VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY hospital_code (hospital_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($mysqli->query($sql)) {
    echo "  OK: hospitals table ready\n";
    $success++;
} else {
    $errors[] = "hospitals: " . $mysqli->error;
    echo "  ERROR: " . $mysqli->error . "\n";
}

// MIGRATION 3: Create user_hospital_access table
echo "\n[3/8] Creating user_hospital_access table...\n";
$sql = "CREATE TABLE IF NOT EXISTS user_hospital_access (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(11) UNSIGNED NOT NULL,
    hospital_id INT(11) UNSIGNED NOT NULL,
    access_level ENUM('owner','admin','read_only') DEFAULT 'read_only',
    granted_by INT(11) UNSIGNED DEFAULT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_hospital (user_id, hospital_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($mysqli->query($sql)) {
    echo "  OK: user_hospital_access table ready\n";
    $success++;
} else {
    $errors[] = "user_hospital_access: " . $mysqli->error;
    echo "  ERROR: " . $mysqli->error . "\n";
}

// MIGRATION 4: Add hospital_id to cached_patients
echo "\n[4/8] Adding hospital_id to cached_patients...\n";
try {
    $result = $mysqli->query("SHOW COLUMNS FROM cached_patients LIKE 'hospital_id'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE cached_patients ADD COLUMN hospital_id INT(11) UNSIGNED DEFAULT 1");
        echo "  OK: Added hospital_id to cached_patients\n";
    } else {
        echo "  SKIP: hospital_id already exists in cached_patients\n";
    }
    $success++;
} catch (Exception $e) {
    $errors[] = "cached_patients.hospital_id: " . $mysqli->error;
    echo "  ERROR: " . $mysqli->error . "\n";
}

// MIGRATION 5: Add hospital_id to cached_studies
echo "\n[5/8] Adding hospital_id to cached_studies...\n";
try {
    $result = $mysqli->query("SHOW COLUMNS FROM cached_studies LIKE 'hospital_id'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE cached_studies ADD COLUMN hospital_id INT(11) UNSIGNED DEFAULT 1");
        echo "  OK: Added hospital_id to cached_studies\n";
    } else {
        echo "  SKIP: hospital_id already exists in cached_studies\n";
    }
    $success++;
} catch (Exception $e) {
    $errors[] = "cached_studies.hospital_id: " . $mysqli->error;
    echo "  ERROR: " . $mysqli->error . "\n";
}

// MIGRATION 6: Add hospital_id to medical_reports
echo "\n[6/8] Adding hospital_id to medical_reports...\n";
try {
    $result = $mysqli->query("SHOW COLUMNS FROM medical_reports LIKE 'hospital_id'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE medical_reports ADD COLUMN hospital_id INT(11) UNSIGNED DEFAULT 1");
        echo "  OK: Added hospital_id to medical_reports\n";
    } else {
        echo "  SKIP: hospital_id already exists in medical_reports\n";
    }
    $success++;
} catch (Exception $e) {
    $errors[] = "medical_reports.hospital_id: " . $mysqli->error;
    echo "  ERROR: " . $mysqli->error . "\n";
}

// MIGRATION 7: Insert default hospital
echo "\n[7/8] Creating default hospital...\n";
$result = $mysqli->query("SELECT COUNT(*) as cnt FROM hospitals");
$count = $result->fetch_assoc()['cnt'];

if ($count == 0) {
    // Get clinic name from settings
    $stmt = $mysqli->query("SELECT setting_value FROM hospital_settings WHERE setting_key = 'clinic_location_name'");
    $row = $stmt->fetch_assoc();
    $clinicName = $row['setting_value'] ?? 'Main Hospital';
    
    $mysqli->query("INSERT INTO hospitals (id, hospital_code, hospital_name, location, is_active) 
                    VALUES (1, 'HOSP-MAIN-001', '$clinicName', 'Primary Location', 1)");
    echo "  OK: Created default hospital '$clinicName'\n";
    $success++;
} else {
    echo "  SKIP: $count hospital(s) already exist\n";
    $success++;
}

// MIGRATION 8: Assign admin users to default hospital
echo "\n[8/8] Assigning admin users...\n";
$result = $mysqli->query("SELECT id, username FROM users WHERE role = 'admin'");
$admins = [];
while ($row = $result->fetch_assoc()) {
    $admins[] = $row;
}

foreach ($admins as $admin) {
    $check = $mysqli->query("SELECT id FROM user_hospital_access WHERE user_id = {$admin['id']} AND hospital_id = 1");
    if ($check->num_rows == 0) {
        $mysqli->query("INSERT INTO user_hospital_access (user_id, hospital_id, access_level) 
                        VALUES ({$admin['id']}, 1, 'owner')");
        echo "  OK: Assigned {$admin['username']} as owner\n";
    } else {
        echo "  SKIP: {$admin['username']} already assigned\n";
    }
}
$success++;

// Add clinic settings
echo "\n[BONUS] Adding clinic settings...\n";
$settings = [
    ['clinic_location_name', 'Main Clinic', 'clinic'],
    ['multi_clinic_mode', 'false', 'clinic'],
    ['current_hospital_id', '1', 'hospital'],
    ['hospital_isolation_enabled', 'true', 'hospital']
];

foreach ($settings as $s) {
    $mysqli->query("INSERT INTO hospital_settings (setting_key, setting_value, setting_group) 
                    VALUES ('{$s[0]}', '{$s[1]}', '{$s[2]}') 
                    ON DUPLICATE KEY UPDATE setting_key=setting_key");
}
echo "  OK: Settings configured\n";

// Update existing data
echo "\n[FINAL] Updating existing data...\n";
$mysqli->query("UPDATE cached_patients SET hospital_id = 1 WHERE hospital_id IS NULL OR hospital_id = 0");
$p = $mysqli->affected_rows;
$mysqli->query("UPDATE cached_studies SET hospital_id = 1 WHERE hospital_id IS NULL OR hospital_id = 0");
$s = $mysqli->affected_rows;
$mysqli->query("UPDATE cached_studies SET clinic_location = 'Main Clinic' WHERE clinic_location IS NULL");
$c = $mysqli->affected_rows;
echo "  OK: Updated $p patients, $s studies, $c clinic locations\n";

// Re-enable foreign key checks
$mysqli->query("SET FOREIGN_KEY_CHECKS=1");

// Summary
echo "\n=================================\n";
if (empty($errors)) {
    echo "SUCCESS! All migrations completed.\n";
    echo "=================================\n\n";
    echo "What's ready:\n";
    echo "  - hospitals table\n";
    echo "  - user_hospital_access table\n";
    echo "  - hospital_id columns in all data tables\n";
    echo "  - Default hospital created\n";
    echo "  - Admin users assigned\n\n";
    echo "Next: Refresh your browser and visit:\n";
    echo "  Settings -> Hospital Management\n";
} else {
    echo "COMPLETED WITH ERRORS:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
echo "=================================\n";

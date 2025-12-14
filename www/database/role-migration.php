<?php
/**
 * Role Migration - Add Radiologist and Technician roles
 * Updates the role enum to include all medical roles
 */

define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

echo "=== Role System Migration ===\n\n";

$mysqli = getDbConnection();

// Step 1: Modify the role column to include new roles
echo "[1/3] Updating role column in users table...\n";

try {
    // ALTER the enum to include new roles
    $sql = "ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'doctor', 'radiologist', 'technician', 'viewer') NOT NULL DEFAULT 'viewer'";
    
    if ($mysqli->query($sql)) {
        echo "  OK: Role column updated with new values\n";
    } else {
        throw new Exception($mysqli->error);
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// Step 2: Update any 'user' roles to 'viewer' (cleanup)
echo "\n[2/3] Cleaning up any invalid roles...\n";
$mysqli->query("UPDATE users SET role = 'viewer' WHERE role NOT IN ('admin', 'doctor', 'radiologist', 'technician', 'viewer')");
echo "  OK: Invalid roles cleaned up ({$mysqli->affected_rows} rows affected)\n";

// Step 3: Verify the roles in database
echo "\n[3/3] Verifying current roles...\n";
$result = $mysqli->query("SELECT username, full_name, role, is_active FROM users ORDER BY role, username");

echo "\n  Current Users:\n";
echo "  " . str_repeat("-", 60) . "\n";
while ($row = $result->fetch_assoc()) {
    $status = $row['is_active'] ? 'Active' : 'Inactive';
    printf("  %-20s | %-15s | %-12s | %s\n", 
           $row['full_name'] ?: $row['username'],
           $row['role'],
           $status,
           "@" . $row['username']);
}
echo "  " . str_repeat("-", 60) . "\n";

echo "\n=== Migration Complete! ===\n";
echo "\nRole Permissions:\n";
echo "  - admin:       Full system access - manage users, settings, everything\n";
echo "  - doctor:      View patients, create/edit reports, prescriptions\n";
echo "  - radiologist: View patients, create/finalize reports (radiology focus)\n";
echo "  - technician:  Read-only access to patients and studies\n";
echo "  - viewer:      Basic read-only access (legacy)\n";
echo "\n";

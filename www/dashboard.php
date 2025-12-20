<?php
/**
 * Dashboard Entry Point
 * Routes users to appropriate page based on setup status
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/auth/session.php';

// Redirect to login if not authenticated
requireLogin();

$db = getDbConnection();

// Check if user has special properties (handle missing columns gracefully)
$userInfo = ['is_super_admin' => false, 'setup_completed' => false];
try {
    // First check if columns exist
    $columnsExist = true;
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
    if (!$result || $result->num_rows == 0) {
        $columnsExist = false;
    }

    if ($columnsExist) {
        $stmt = $db->prepare("SELECT is_super_admin, setup_completed FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $userInfo = $row;
        }
    }
} catch (Exception $e) {
    // Columns don't exist yet - will be created by migration
    logMessage("Dashboard: Could not query user setup status - " . $e->getMessage(), 'warning');
}

// Super admin goes to super admin portal
if (!empty($userInfo['is_super_admin'])) {
    header('Location: ' . BASE_PATH . '/super-admin/index.php');
    exit;
}

// Check if first-time setup is needed (for admins)
if (isAdmin()) {
    $setupNeeded = false;

    // Check 1: User's setup_completed flag
    if (empty($userInfo['setup_completed'])) {
        $setupNeeded = true;
    }

    // Check 2: Hospital name not configured (fallback check)
    // Check system_settings first (main app table), then settings as fallback
    if (!$setupNeeded) {
        $hospitalFound = false;
        try {
            // Check system_settings first (primary source)
            $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'hospital_name' AND setting_value != '' LIMIT 1");
            if ($result && $result->num_rows > 0) {
                $hospitalFound = true;
            }
            // Fallback to settings table
            if (!$hospitalFound) {
                $result = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'hospital_name' AND setting_value != '' LIMIT 1");
                if ($result && $result->num_rows > 0) {
                    $hospitalFound = true;
                }
            }
            if (!$hospitalFound) {
                $setupNeeded = true;
            }
        } catch (Exception $e) {
            // Tables don't exist - definitely need setup
            $setupNeeded = true;
        }
    }

    if ($setupNeeded) {
        // Redirect to the setup wizard
        header('Location: ' . BASE_PATH . '/setup.php');
        exit;
    }
}

// Redirect to patient list page (HTML-based workflow)
header('Location: ' . BASE_PATH . '/pages/patients.html');
exit;
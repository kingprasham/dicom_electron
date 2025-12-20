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
    $setupNeeded = true; // Default to needing setup
    $setupFlagSet = false;

    // PRIMARY CHECK: Is setup_complete flag set to '1'?
    // This flag is set by /api/setup/complete.php after wizard completion
    // and reset to '0' by /api/license/activate.php on NEW license activation
    try {
        // Check setup_complete flag in system_settings (primary source)
        $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'setup_complete' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if ($row['setting_value'] === '1') {
                $setupFlagSet = true;
            }
        }

        // Fallback: check settings table for setup_complete
        if (!$setupFlagSet) {
            $result = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'setup_complete' LIMIT 1");
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['setting_value'] === '1') {
                    $setupFlagSet = true;
                }
            }
        }

    } catch (Exception $e) {
        // Tables don't exist - definitely need setup
        $setupFlagSet = false;
    }

    // Setup is NOT needed only if the flag is explicitly set to '1'
    if ($setupFlagSet) {
        $setupNeeded = false;
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
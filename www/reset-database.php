<?php
/**
 * Database Reset Script
 * Clears all data EXCEPT super admin users
 * Also provides instructions for Orthanc reset
 */

define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

$db = getDbConnection();

// Security check - only run if accessed directly with confirmation
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'RESET_ALL_DATA';

if (!$confirmed) {
    ?>
    <!DOCTYPE html>
    <html lang="en" data-bs-theme="dark">
    <head>
        <meta charset="UTF-8">
        <title>Database Reset</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-dark text-white p-5">
        <div class="container">
            <div class="card bg-danger text-white">
                <div class="card-header">
                    <h3><i class="bi bi-exclamation-triangle"></i> ⚠️ DATABASE RESET</h3>
                </div>
                <div class="card-body">
                    <p><strong>This will delete:</strong></p>
                    <ul>
                        <li>All licenses (except keeping super admin users)</li>
                        <li>All license activations</li>
                        <li>All locations and machine assignments</li>
                        <li>All print logs and pricing</li>
                        <li>All system settings (setup_completed flag)</li>
                        <li>All medical reports</li>
                    </ul>
                    <p class="text-warning"><strong>Super admin user accounts will be preserved!</strong></p>
                    <hr>
                    <h5>For Orthanc Reset:</h5>
                    <ol>
                        <li>Stop Orthanc server</li>
                        <li>Delete the folder: <code>C:\Orthanc\OrthancStorage</code></li>
                        <li>Restart Orthanc server</li>
                    </ol>
                    <hr>
                    <a href="?confirm=RESET_ALL_DATA" class="btn btn-warning btn-lg">
                        ⚠️ CONFIRM RESET - DELETE ALL DATA
                    </a>
                    <a href="login.php" class="btn btn-secondary btn-lg ms-3">Cancel</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Perform the reset
$results = [];
$errors = [];

try {
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    
    // Tables to TRUNCATE completely
    $tablesToClear = [
        'license_activations',
        'license_usage_stats',
        'licenses',
        'installation_license',
        'locations',
        'machine_locations',
        'print_logs',
        'print_pricing',
        'print_invoices',
        'print_invoice_items',
        'medical_reports',
        'system_settings'  // Clear ALL settings!
    ];
    
    foreach ($tablesToClear as $table) {
        try {
            $db->query("TRUNCATE TABLE `$table`");
            $results[] = "✓ Cleared: $table";
        } catch (Exception $e) {
            // Table may not exist
            try {
                $db->query("DELETE FROM `$table`");
                $results[] = "✓ Deleted from: $table";
            } catch (Exception $e2) {
                $results[] = "- Skipped: $table (may not exist)";
            }
        }
    }
    
    // Delete non-super-admin users
    try {
        $db->query("DELETE FROM users WHERE is_super_admin = 0 OR is_super_admin IS NULL");
        $results[] = "✓ Removed non-super-admin users";
    } catch (Exception $e) {
        $errors[] = "Failed to clean users: " . $e->getMessage();
    }
    
    // Create empty installation_license row (required for app to work)
    try {
        $db->query("INSERT INTO installation_license (id, is_active) VALUES (1, 0)");
        $results[] = "✓ Created empty installation_license row";
    } catch (Exception $e) {
        // Already exists or error
    }
    
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
    
} catch (Exception $e) {
    $errors[] = "Reset failed: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>Reset Complete</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white p-5">
    <div class="container">
        <div class="card bg-success text-white">
            <div class="card-header">
                <h3>✅ Database Reset Complete</h3>
            </div>
            <div class="card-body">
                <h5>Results:</h5>
                <ul>
                    <?php foreach ($results as $r): ?>
                        <li><?= htmlspecialchars($r) ?></li>
                    <?php endforeach; ?>
                </ul>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-warning">
                        <h5>Warnings:</h5>
                        <ul>
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <hr>
                <h5>Remember to reset Orthanc:</h5>
                <ol>
                    <li>Stop Orthanc server</li>
                    <li>Delete: <code>C:\Orthanc\OrthancStorage</code></li>
                    <li>Restart Orthanc server</li>
                </ol>
                
                <hr>
                <p><strong>Next steps:</strong></p>
                <ol>
                    <li>Restart the application (npm start)</li>
                    <li>You'll see the license activation page</li>
                    <li>Follow the setup wizard</li>
                </ol>
                
                <a href="login.php" class="btn btn-primary btn-lg">Go to Login</a>
            </div>
        </div>
    </div>
</body>
</html>

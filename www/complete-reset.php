<?php
/**
 * COMPLETE Database & Orthanc Reset Script
 * Clears ALL data including Orthanc studies
 * Preserves ONLY super admin users
 */

define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

$db = getDbConnection();
$orthanc_url = 'http://localhost:8042';  // Default Orthanc URL

// Security check
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'YES_RESET_EVERYTHING';

if (!$confirmed) {
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>COMPLETE System Reset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-dark text-white p-5">
    <div class="container">
        <div class="card border-danger" style="background: linear-gradient(135deg, #1a0a2e, #2d0a0a);">
            <div class="card-header bg-danger text-white">
                <h3><i class="bi bi-exclamation-triangle-fill me-2"></i>COMPLETE SYSTEM RESET</h3>
            </div>
            <div class="card-body">
                <div class="alert alert-danger">
                    <h5><i class="bi bi-trash3 me-2"></i>This will DELETE:</h5>
                    <ul class="mb-0">
                        <li><strong>ALL database tables</strong> (licenses, activations, locations, prints, reports, settings)</li>
                        <li><strong>ALL Orthanc studies</strong> (patients, studies, series, instances)</li>
                        <li><strong>ALL non-super-admin users</strong></li>
                    </ul>
                </div>
                
                <div class="alert alert-success">
                    <h5><i class="bi bi-check-circle me-2"></i>This will KEEP:</h5>
                    <ul class="mb-0">
                        <li>Super admin user accounts only</li>
                    </ul>
                </div>
                
                <hr>
                <p class="text-warning"><strong>⚠️ This action CANNOT be undone!</strong></p>
                
                <div class="d-flex gap-3 mt-4">
                    <a href="?confirm=YES_RESET_EVERYTHING" class="btn btn-danger btn-lg">
                        <i class="bi bi-trash3-fill me-2"></i>CONFIRM - DELETE EVERYTHING
                    </a>
                    <a href="login.php" class="btn btn-secondary btn-lg">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// PERFORM THE RESET
$results = [];
$errors = [];

// ====================
// 1. RESET DATABASE
// ====================
$results[] = "<strong>🗄️ DATABASE RESET</strong>";

try {
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    
    // Get ALL tables in database
    $tablesResult = $db->query("SHOW TABLES");
    $allTables = [];
    while ($row = $tablesResult->fetch_row()) {
        $allTables[] = $row[0];
    }
    
    // Tables to TRUNCATE (clear all data)
    $tablesToClear = [
        // License tables
        'license_activations',
        'license_usage_stats', 
        'licenses',
        'installation_license',
        // Location/Machine tables
        'locations',
        'machine_locations',
        // Print tables
        'print_logs',
        'print_pricing',
        'print_invoices',
        'print_invoice_items',
        'print_settings',
        'printer_settings',
        'printers',
        // Report tables
        'medical_reports',
        'medical_notes',
        'prescriptions',
        // Settings tables
        'system_settings',
        'hospital_settings',
        'hospital_data_config',
        // DICOM/Orthanc tables
        'orthanc_modalities',
        'study_metadata',
        'cached_patients',
        'cached_studies',
        'measurements',
        // Sync/Monitoring tables
        'monitored_paths',
        'sync_configuration',
        // Session/Auth tables
        'sessions',
        // Progress/Setup tables
        'onboarding_progress',
        // Audit/Log tables
        'audit_logs',
        'debug_logs',
        // Backup tables
        'backup_history',
        'backup_accounts',
        'google_drive_backup_log',
        'google_drive_backup_config',
        // AI tables
        'ai_analysis',
        // Status tables
        'system_status',
        // Remark tables
        'study_remarks'
    ];
    
    foreach ($tablesToClear as $table) {
        if (in_array($table, $allTables)) {
            try {
                $db->query("TRUNCATE TABLE `$table`");
                $results[] = "✓ Cleared: $table";
            } catch (Exception $e) {
                try {
                    $db->query("DELETE FROM `$table`");
                    $results[] = "✓ Deleted from: $table";
                } catch (Exception $e2) {
                    $results[] = "⚠ Could not clear: $table";
                }
            }
        }
    }
    
    // Delete NON-super-admin users
    try {
        $countResult = $db->query("SELECT COUNT(*) as count FROM users WHERE is_super_admin = 0 OR is_super_admin IS NULL");
        $count = $countResult->fetch_assoc()['count'];
        $db->query("DELETE FROM users WHERE is_super_admin = 0 OR is_super_admin IS NULL");
        $results[] = "✓ Removed $count non-super-admin users";
    } catch (Exception $e) {
        $errors[] = "Failed to clean users: " . $e->getMessage();
    }
    
    // Create empty installation_license row
    try {
        $db->query("INSERT INTO installation_license (id, is_active, cached_is_active) VALUES (1, 0, 0)");
        $results[] = "✓ Created empty installation_license";
    } catch (Exception $e) {
        // May already exist
    }
    
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
    
} catch (Exception $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

// ====================
// 2. RESET ORTHANC
// ====================
$results[] = "";
$results[] = "<strong>🏥 ORTHANC RESET</strong>";

try {
    // Get all patients from Orthanc
    $ch = curl_init("$orthanc_url/patients");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $patients = json_decode($response, true);
        
        if (is_array($patients) && count($patients) > 0) {
            $deletedCount = 0;
            
            foreach ($patients as $patientId) {
                // Delete each patient (cascades to studies, series, instances)
                $ch = curl_init("$orthanc_url/patients/$patientId");
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_exec($ch);
                $deleteCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($deleteCode === 200) {
                    $deletedCount++;
                }
            }
            
            $results[] = "✓ Deleted $deletedCount patients from Orthanc";
        } else {
            $results[] = "✓ Orthanc is already empty (0 patients)";
        }
    } else {
        $results[] = "⚠ Could not connect to Orthanc at $orthanc_url (code: $httpCode)";
        $results[] = "  → Please manually delete: C:\\Orthanc\\OrthancStorage folder";
    }
    
} catch (Exception $e) {
    $results[] = "⚠ Orthanc error: " . $e->getMessage();
    $results[] = "  → Please manually delete: C:\\Orthanc\\OrthancStorage folder";
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>Reset Complete</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-dark text-white p-5">
    <div class="container">
        <div class="card bg-success text-white">
            <div class="card-header">
                <h3><i class="bi bi-check-circle-fill me-2"></i>RESET COMPLETE</h3>
            </div>
            <div class="card-body">
                <h5>Results:</h5>
                <ul class="list-unstyled">
                    <?php foreach ($results as $r): ?>
                        <li><?= $r ?></li>
                    <?php endforeach; ?>
                </ul>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-warning mt-3">
                        <h5>Errors:</h5>
                        <ul>
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <hr>
                <h5>✅ System is now clean. Next steps:</h5>
                <ol>
                    <li>Close and restart the application: <code>npm start</code></li>
                    <li>You'll see the <strong>License Activation</strong> page</li>
                    <li>Login as super admin to create a new license</li>
                    <li>Copy the license key and activate it</li>
                    <li>Complete the Setup Wizard</li>
                </ol>
                
                <a href="login.php" class="btn btn-primary btn-lg mt-3">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                </a>
            </div>
        </div>
    </div>
    
    <!-- Clear ALL localStorage after reset -->
    <script>
        // Clear all localStorage data
        localStorage.clear();
        console.log('✓ localStorage cleared completely');
        
        // Also clear sessionStorage
        sessionStorage.clear();
        console.log('✓ sessionStorage cleared completely');
    </script>
</body>
</html>

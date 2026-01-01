<?php
/**
 * Setup Script for Hospital Printers Table
 * Run this once to create the hospital_printers table
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DICOM_VIEWER', true);

// Include database connection
require_once __DIR__ . '/../auth/session.php';

echo "<h2>Hospital Printers Table Setup</h2>";
echo "<pre>";

try {
    $db = getDbConnection();

    echo "Creating hospital_printers table...\n\n";

    // Read and execute the migration SQL
    $sql = file_get_contents(__DIR__ . '/migrations/015_hospital_printers.sql');

    // Split into individual statements (in case there are multiple)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) { return !empty($stmt) && strpos($stmt, '--') !== 0; }
    );

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            echo "Executing: " . substr($statement, 0, 100) . "...\n";
            if ($db->query($statement)) {
                echo "✓ Success\n\n";
            } else {
                echo "✗ Error: " . $db->error . "\n\n";
            }
        }
    }

    // Verify table was created
    $result = $db->query("SHOW TABLES LIKE 'hospital_printers'");
    if ($result && $result->num_rows > 0) {
        echo "✓ Table 'hospital_printers' created successfully!\n\n";

        // Show table structure
        echo "Table structure:\n";
        $columns = $db->query("DESCRIBE hospital_printers");
        while ($col = $columns->fetch_assoc()) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }

        echo "\n";
        echo "========================================\n";
        echo "Setup completed successfully!\n";
        echo "========================================\n";
        echo "\nYou can now:\n";
        echo "1. Go to Settings > Authorized Printers\n";
        echo "2. Add printers to the hospital\n";
        echo "3. Test the custom print dialog\n";
    } else {
        echo "✗ Table creation failed\n";
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo '<br><a href="../admin/general-settings.php">Go to Settings</a> | ';
echo '<a href="../index.php">Go to Home</a>';
?>

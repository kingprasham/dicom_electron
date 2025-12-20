<?php
/**
 * DATABASE CONNECTION TEST
 * Tests connection to MySQL server (useful for network setup)
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Connection Test</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .success { color: #00ff00; }
        .warning { color: #ffaa00; }
        .error { color: #ff0000; }
        .info { color: #00aaff; }
        h1 { color: #ffff00; border-bottom: 2px solid #ffff00; padding-bottom: 10px; }
        h2 { color: #ff00ff; margin-top: 30px; }
        .section { background: #2a2a2a; padding: 15px; margin: 10px 0; border-left: 4px solid #00ff00; }
        pre { margin: 5px 0; }
        .config-box {
            background: #000;
            border: 1px solid #00ff00;
            padding: 15px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <h1>🔌 DATABASE CONNECTION TEST</h1>

<?php
// Load config if exists
$configFile = __DIR__ . '/includes/config.php';
$envFile = __DIR__ . '/.env';

echo '<div class="section">';
echo '<h2>📁 Configuration Files</h2>';

if (file_exists($configFile)) {
    echo '<pre class="success">✓ config.php found</pre>';
    define('DICOM_VIEWER', true);
    require_once $configFile;
} else {
    echo '<pre class="error">✗ config.php NOT found</pre>';
}

if (file_exists($envFile)) {
    echo '<pre class="success">✓ .env found</pre>';
} else {
    echo '<pre class="warning">⚠ .env NOT found (optional)</pre>';
}
echo '</div>';

// Show current configuration
echo '<div class="section">';
echo '<h2>⚙️ Current Database Configuration</h2>';

if (defined('DB_HOST')) {
    echo '<div class="config-box">';
    echo '<pre>Host: <span class="info">' . DB_HOST . '</span></pre>';
    echo '<pre>User: <span class="info">' . DB_USER . '</span></pre>';
    echo '<pre>Password: <span class="info">' . str_repeat('*', strlen(DB_PASS)) . '</span></pre>';
    echo '<pre>Database: <span class="info">' . DB_NAME . '</span></pre>';
    echo '<pre>Port: <span class="info">' . (defined('DB_PORT') ? DB_PORT : '3306') . '</span></pre>';
    echo '</div>';

    // Test connection
    echo '<h2>🔍 Connection Test Results</h2>';

    try {
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASS;
        $db = DB_NAME;
        $port = defined('DB_PORT') ? DB_PORT : 3306;

        // Test 1: Can we reach the host?
        echo '<div class="config-box">';
        echo '<pre class="info">Test 1: Checking if host is reachable...</pre>';

        if ($host === 'localhost' || $host === '127.0.0.1') {
            echo '<pre class="info">  → Connecting to LOCAL MySQL server</pre>';
        } else {
            echo '<pre class="info">  → Connecting to NETWORK MySQL server at ' . $host . '</pre>';

            // Test ping (Windows)
            $pingResult = exec("ping -n 1 -w 1000 $host", $output, $returnCode);
            if ($returnCode === 0) {
                echo '<pre class="success">  ✓ Host is reachable via ping</pre>';
            } else {
                echo '<pre class="error">  ✗ Host is NOT reachable via ping</pre>';
                echo '<pre class="warning">  ⚠ Check network connection and firewall</pre>';
            }
        }
        echo '</div>';

        // Test 2: Can we connect to MySQL?
        echo '<div class="config-box">';
        echo '<pre class="info">Test 2: Attempting MySQL connection...</pre>';

        $conn = @new mysqli($host, $user, $pass, '', $port);

        if ($conn->connect_error) {
            echo '<pre class="error">  ✗ Connection FAILED</pre>';
            echo '<pre class="error">  Error: ' . htmlspecialchars($conn->connect_error) . '</pre>';
            echo '<pre class="error">  Error Code: ' . $conn->connect_errno . '</pre>';

            // Provide helpful error messages
            if ($conn->connect_errno === 2002) {
                echo '<pre class="warning">  ⚠ MySQL server is not running or not accessible</pre>';
                echo '<pre class="info">  → Check if MySQL is running on ' . $host . '</pre>';
                echo '<pre class="info">  → Check firewall allows port ' . $port . '</pre>';
            } elseif ($conn->connect_errno === 1045) {
                echo '<pre class="warning">  ⚠ Access denied - wrong username or password</pre>';
                echo '<pre class="info">  → Check DB_USER and DB_PASS in config.php</pre>';
            } elseif ($conn->connect_errno === 2003) {
                echo '<pre class="warning">  ⚠ Cannot connect to MySQL server on this host</pre>';
                echo '<pre class="info">  → Check if MySQL is configured to accept network connections</pre>';
                echo '<pre class="info">  → Edit my.ini: bind-address = 0.0.0.0</pre>';
            }

            echo '</div>';
            exit;
        }

        echo '<pre class="success">  ✓ MySQL connection SUCCESSFUL</pre>';
        echo '<pre class="info">  Server version: ' . $conn->server_info . '</pre>';
        echo '<pre class="info">  Protocol version: ' . $conn->protocol_version . '</pre>';
        echo '<pre class="info">  Host info: ' . $conn->host_info . '</pre>';
        echo '</div>';

        // Test 3: Can we select the database?
        echo '<div class="config-box">';
        echo '<pre class="info">Test 3: Selecting database "' . $db . '"...</pre>';

        if (!$conn->select_db($db)) {
            echo '<pre class="error">  ✗ Database "' . $db . '" NOT found</pre>';
            echo '<pre class="warning">  ⚠ Database needs to be created</pre>';

            // Show available databases
            $result = $conn->query("SHOW DATABASES");
            if ($result) {
                echo '<pre class="info">  Available databases:</pre>';
                while ($row = $result->fetch_array()) {
                    echo '<pre>    • ' . htmlspecialchars($row[0]) . '</pre>';
                }
            }
        } else {
            echo '<pre class="success">  ✓ Database "' . $db . '" found and selected</pre>';

            // Test 4: Check tables
            echo '</div>';
            echo '<div class="config-box">';
            echo '<pre class="info">Test 4: Checking database tables...</pre>';

            $result = $conn->query("SHOW TABLES");
            if ($result) {
                $tableCount = $result->num_rows;
                echo '<pre class="success">  ✓ Found ' . $tableCount . ' tables</pre>';

                if ($tableCount > 0) {
                    echo '<pre class="info">  Tables in database:</pre>';
                    $importantTables = ['users', 'patients', 'studies', 'system_settings', 'licenses'];
                    $foundTables = [];

                    while ($row = $result->fetch_array()) {
                        $tableName = $row[0];
                        $foundTables[] = $tableName;
                        $isImportant = in_array($tableName, $importantTables);
                        $color = $isImportant ? 'success' : 'info';
                        echo '<pre class="' . $color . '">    • ' . htmlspecialchars($tableName) . '</pre>';
                    }

                    // Check if important tables exist
                    echo '<br><pre class="info">  Critical tables check:</pre>';
                    foreach ($importantTables as $table) {
                        if (in_array($table, $foundTables)) {
                            echo '<pre class="success">    ✓ ' . $table . '</pre>';
                        } else {
                            echo '<pre class="warning">    ⚠ ' . $table . ' (missing)</pre>';
                        }
                    }
                } else {
                    echo '<pre class="warning">  ⚠ Database is empty (no tables)</pre>';
                    echo '<pre class="info">  → Run migrations to create tables</pre>';
                }
            }
        }
        echo '</div>';

        // Test 5: Check users table
        if ($conn->select_db($db)) {
            echo '<div class="config-box">';
            echo '<pre class="info">Test 5: Checking users...</pre>';

            $result = @$conn->query("SELECT COUNT(*) as cnt FROM users");
            if ($result) {
                $row = $result->fetch_assoc();
                $userCount = $row['cnt'];

                if ($userCount > 0) {
                    echo '<pre class="success">  ✓ Found ' . $userCount . ' user(s)</pre>';

                    // Show usernames
                    $result = $conn->query("SELECT username, role, is_active FROM users LIMIT 10");
                    if ($result) {
                        echo '<pre class="info">  Users:</pre>';
                        while ($row = $result->fetch_assoc()) {
                            $status = $row['is_active'] ? '✓' : '✗';
                            echo '<pre>    ' . $status . ' ' . htmlspecialchars($row['username']) . ' (' . htmlspecialchars($row['role']) . ')</pre>';
                        }
                    }
                } else {
                    echo '<pre class="warning">  ⚠ No users found</pre>';
                    echo '<pre class="info">  → Create users via manage_users.php</pre>';
                }
            } else {
                echo '<pre class="warning">  ⚠ Users table not found or accessible</pre>';
            }
            echo '</div>';
        }

        // Success summary
        echo '<div class="section">';
        echo '<h2 class="success">✅ CONNECTION TEST PASSED!</h2>';
        echo '<pre class="success">All tests completed successfully.</pre>';
        echo '<pre class="info">Your database connection is working properly.</pre>';

        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            echo '<br><pre class="success">✓ Network database connection confirmed!</pre>';
            echo '<pre class="info">  This PC can access the database server at ' . $host . '</pre>';
        }
        echo '</div>';

        $conn->close();

    } catch (Exception $e) {
        echo '<div class="section">';
        echo '<h2 class="error">❌ CONNECTION TEST FAILED</h2>';
        echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '</div>';
    }

} else {
    echo '<pre class="error">Configuration constants not defined!</pre>';
    echo '<pre class="info">Make sure includes/config.php exists and defines:</pre>';
    echo '<pre>  • DB_HOST</pre>';
    echo '<pre>  • DB_USER</pre>';
    echo '<pre>  • DB_PASS</pre>';
    echo '<pre>  • DB_NAME</pre>';
}
echo '</div>';

// Network setup tips
echo '<div class="section">';
echo '<h2>💡 Network Setup Tips</h2>';
echo '<pre class="info">For multi-PC hospital setup:</pre>';
echo '<pre>1. Server PC: Install MySQL and share on network</pre>';
echo '<pre>2. Client PCs: Point DB_HOST to server IP (e.g., 192.168.1.100)</pre>';
echo '<pre>3. Create MySQL user: CREATE USER \'dicom_user\'@\'192.168.1.%\'</pre>';
echo '<pre>4. Configure firewall: Allow port 3306 on server</pre>';
echo '<pre>5. Edit my.ini: Set bind-address = 0.0.0.0</pre>';
echo '<br>';
echo '<pre class="info">See NETWORK_SETUP_GUIDE.md for detailed instructions.</pre>';
echo '</div>';
?>

</body>
</html>

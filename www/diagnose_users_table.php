<?php
/**
 * DIAGNOSE USERS TABLE
 * Check the structure and find out why role is not updating
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Diagnose Users Table</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .success { color: #00ff00; }
        .error { color: #ff0000; }
        .info { color: #00aaff; }
        .warning { color: #ffaa00; }
        h1 { color: #ffff00; border-bottom: 2px solid #ffff00; padding-bottom: 10px; }
        .section { background: #2a2a2a; padding: 15px; margin: 10px 0; border-left: 4px solid #00ff00; }
        pre { margin: 5px 0; white-space: pre-wrap; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th {
            background: #000;
            color: #00ff00;
            padding: 10px;
            text-align: left;
            border: 1px solid #00ff00;
        }
        td {
            padding: 10px;
            border: 1px solid #333;
        }
    </style>
</head>
<body>
    <h1>🔍 USERS TABLE DIAGNOSIS</h1>

<?php
try {
    $db = getDbConnection();

    // Show table structure
    echo '<div class="section">';
    echo '<h2>1. Table Structure (SHOW COLUMNS FROM users)</h2>';

    $result = $db->query("SHOW COLUMNS FROM users");

    echo '<table>';
    echo '<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';

    $hasRoleColumn = false;
    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['Field']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Type']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Null']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Key']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Default'] ?? 'NULL') . '</td>';
        echo '<td>' . htmlspecialchars($row['Extra']) . '</td>';
        echo '</tr>';

        if ($row['Field'] === 'role') {
            $hasRoleColumn = true;
        }
    }
    echo '</table>';

    if ($hasRoleColumn) {
        echo '<pre class="success">✓ "role" column EXISTS</pre>';
    } else {
        echo '<pre class="error">✗ "role" column DOES NOT EXIST!</pre>';
    }

    echo '</div>';

    // Show all data for superadmin
    echo '<div class="section">';
    echo '<h2>2. All Columns for superadmin User</h2>';

    $result = $db->query("SELECT * FROM users WHERE username = 'superadmin'");

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        echo '<table>';
        echo '<tr><th>Column Name</th><th>Value</th><th>Length</th></tr>';

        foreach ($user as $key => $value) {
            $displayValue = $value;
            if ($key === 'password_hash') {
                $displayValue = '[HIDDEN - ' . strlen($value) . ' chars]';
            } elseif (is_null($value)) {
                $displayValue = '[NULL]';
            } elseif ($value === '') {
                $displayValue = '[EMPTY STRING]';
            }

            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($key) . '</strong></td>';
            echo '<td>' . htmlspecialchars($displayValue) . '</td>';
            echo '<td>' . (is_null($value) ? 'NULL' : strlen($value) . ' chars') . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    } else {
        echo '<pre class="error">✗ Superadmin user not found!</pre>';
    }

    echo '</div>';

    // Try direct UPDATE with error checking
    echo '<div class="section">';
    echo '<h2>3. Attempting Direct UPDATE</h2>';

    echo '<pre class="info">Executing: UPDATE users SET role = \'super_admin\' WHERE username = \'superadmin\'</pre>';

    $updateResult = $db->query("UPDATE users SET role = 'super_admin' WHERE username = 'superadmin'");

    if ($updateResult) {
        echo '<pre class="success">✓ Query executed successfully</pre>';
        echo '<pre>  Affected rows: ' . $db->affected_rows . '</pre>';
    } else {
        echo '<pre class="error">✗ Query failed!</pre>';
        echo '<pre class="error">  Error: ' . $db->error . '</pre>';
    }

    echo '</div>';

    // Verify again
    echo '<div class="section">';
    echo '<h2>4. Verification After Update</h2>';

    $result = $db->query("SELECT id, username, role, is_super_admin FROM users WHERE username = 'superadmin'");

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        echo '<pre>ID: ' . $user['id'] . '</pre>';
        echo '<pre>Username: ' . htmlspecialchars($user['username']) . '</pre>';
        echo '<pre>Role: "' . htmlspecialchars($user['role']) . '" (length: ' . strlen($user['role']) . ')</pre>';
        echo '<pre>is_super_admin: ' . $user['is_super_admin'] . '</pre>';

        if ($user['role'] === 'super_admin') {
            echo '<br><pre class="success">✓✓✓ SUCCESS! Role is now "super_admin"</pre>';
        } else {
            echo '<br><pre class="error">✗ Role is STILL empty or wrong!</pre>';
            echo '<pre class="warning">⚠ This suggests there might be a trigger or the column is read-only</pre>';
        }
    }

    echo '</div>';

    // Check for triggers
    echo '<div class="section">';
    echo '<h2>5. Checking for Triggers on users Table</h2>';

    $result = $db->query("SHOW TRIGGERS LIKE 'users'");

    if ($result && $result->num_rows > 0) {
        echo '<pre class="warning">⚠ Found triggers:</pre>';
        echo '<table>';
        echo '<tr><th>Trigger</th><th>Event</th><th>Timing</th></tr>';
        while ($row = $result->fetch_assoc()) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['Trigger']) . '</td>';
            echo '<td>' . htmlspecialchars($row['Event']) . '</td>';
            echo '<td>' . htmlspecialchars($row['Timing']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<pre class="success">✓ No triggers found</pre>';
    }

    echo '</div>';

    // Manual fix attempt
    echo '<div class="section">';
    echo '<h2>6. Trying Different Update Methods</h2>';

    // Method 1: Update with explicit casting
    echo '<pre class="info">Method 1: UPDATE with CAST</pre>';
    $db->query("UPDATE users SET role = CAST('super_admin' AS CHAR(50)) WHERE username = 'superadmin'");
    echo '<pre>  Result: ' . ($db->error ? 'Error: ' . $db->error : 'Success, affected: ' . $db->affected_rows) . '</pre>';

    // Check
    $result = $db->query("SELECT role FROM users WHERE username = 'superadmin'");
    $role = $result->fetch_assoc()['role'];
    echo '<pre>  Role now: "' . htmlspecialchars($role) . '"</pre>';

    // Method 2: Update other field first, then role
    echo '<br><pre class="info">Method 2: Update is_active first, then role</pre>';
    $db->query("UPDATE users SET is_active = 1 WHERE username = 'superadmin'");
    $db->query("UPDATE users SET role = 'super_admin' WHERE username = 'superadmin'");
    echo '<pre>  Result: ' . ($db->error ? 'Error: ' . $db->error : 'Success, affected: ' . $db->affected_rows) . '</pre>';

    // Check
    $result = $db->query("SELECT role FROM users WHERE username = 'superadmin'");
    $role = $result->fetch_assoc()['role'];
    echo '<pre>  Role now: "' . htmlspecialchars($role) . '"</pre>';

    echo '</div>';

} catch (Exception $e) {
    echo '<div class="section">';
    echo '<h2 class="error">❌ ERROR</h2>';
    echo '<pre class="error">' . htmlspecialchars($e->getMessage()) . '</pre>';
    echo '</div>';
}
?>

</body>
</html>

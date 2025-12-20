<?php
/**
 * DEBUG SESSION AND PERMISSIONS
 * Check what's in the session and why redirects are happening
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/auth/session.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }
        .success { color: #00ff00; }
        .warning { color: #ffaa00; }
        .error { color: #ff0000; }
        .info { color: #00aaff; }
        h1 { color: #ffff00; border-bottom: 2px solid #ffff00; padding-bottom: 10px; }
        h2 { color: #ff00ff; margin-top: 30px; }
        .section { background: #2a2a2a; padding: 15px; margin: 10px 0; border-left: 4px solid #00ff00; }
        pre { margin: 5px 0; white-space: pre-wrap; word-wrap: break-word; }
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
    <h1>🔍 SESSION & PERMISSIONS DEBUG</h1>

<?php
echo '<div class="section">';
echo '<h2>📋 SESSION DATA</h2>';

if (isLoggedIn()) {
    echo '<pre class="success">✓ User is logged in</pre>';
    echo '<table>';
    echo '<tr><th>Key</th><th>Value</th></tr>';
    foreach ($_SESSION as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $display = '<pre>' . print_r($value, true) . '</pre>';
        } else {
            $display = htmlspecialchars($value);
        }
        echo '<tr><td>' . htmlspecialchars($key) . '</td><td>' . $display . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<pre class="error">✗ User is NOT logged in</pre>';
}

echo '</div>';

// Check database user info
if (isLoggedIn()) {
    echo '<div class="section">';
    echo '<h2>👤 USER INFO FROM DATABASE</h2>';

    try {
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT id, username, role, is_active FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            echo '<table>';
            echo '<tr><th>Field</th><th>Value</th></tr>';
            foreach ($row as $key => $value) {
                echo '<tr><td>' . htmlspecialchars($key) . '</td><td>' . htmlspecialchars($value) . '</td></tr>';
            }
            echo '</table>';

            // Check for is_super_admin column
            echo '<br><pre class="info">Checking for is_super_admin column...</pre>';
            $stmt2 = $db->prepare("SELECT is_super_admin FROM users WHERE id = ?");
            if ($stmt2) {
                $stmt2->bind_param("i", $_SESSION['user_id']);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                if ($row2 = $result2->fetch_assoc()) {
                    echo '<pre class="success">✓ is_super_admin column exists</pre>';
                    echo '<pre>  is_super_admin = ' . ($row2['is_super_admin'] ? 'TRUE (1)' : 'FALSE (0)') . '</pre>';
                } else {
                    echo '<pre class="error">✗ Could not fetch is_super_admin</pre>';
                }
                $stmt2->close();
            } else {
                echo '<pre class="error">✗ is_super_admin column does NOT exist</pre>';
                echo '<pre class="warning">⚠ Run migration to add this column!</pre>';
            }
        } else {
            echo '<pre class="error">✗ User not found in database!</pre>';
        }
        $stmt->close();
    } catch (Exception $e) {
        echo '<pre class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    }

    echo '</div>';
}

// Check permission functions
if (isLoggedIn()) {
    echo '<div class="section">';
    echo '<h2>🔐 PERMISSION CHECKS</h2>';

    echo '<table>';
    echo '<tr><th>Function</th><th>Result</th></tr>';

    // isAdmin() check
    $isAdminResult = isAdmin();
    echo '<tr>';
    echo '<td>isAdmin()</td>';
    echo '<td class="' . ($isAdminResult ? 'success' : 'error') . '">';
    echo $isAdminResult ? '✓ TRUE' : '✗ FALSE';
    echo '</td>';
    echo '</tr>';

    // hasRole checks
    $roles = ['admin', 'super_admin', 'doctor', 'radiologist'];
    foreach ($roles as $role) {
        $hasRoleResult = hasRole($role);
        echo '<tr>';
        echo '<td>hasRole(\'' . $role . '\')</td>';
        echo '<td class="' . ($hasRoleResult ? 'success' : 'error') . '">';
        echo $hasRoleResult ? '✓ TRUE' : '✗ FALSE';
        echo '</td>';
        echo '</tr>';
    }

    // hasRole with array
    $hasAdminRoles = hasRole(['admin', 'super_admin']);
    echo '<tr>';
    echo '<td>hasRole([\'admin\', \'super_admin\'])</td>';
    echo '<td class="' . ($hasAdminRoles ? 'success' : 'error') . '">';
    echo $hasAdminRoles ? '✓ TRUE' : '✗ FALSE';
    echo '</td>';
    echo '</tr>';

    echo '</table>';

    echo '</div>';
}

// Test what would happen if accessing license-manager.php
if (isLoggedIn()) {
    echo '<div class="section">';
    echo '<h2>🧪 LICENSE MANAGER ACCESS TEST</h2>';

    if (!isAdmin()) {
        echo '<pre class="error">✗ WOULD REDIRECT to patients.html</pre>';
        echo '<pre class="error">  Reason: isAdmin() returned FALSE</pre>';
        echo '<pre class="info">  Expected: isAdmin() should return TRUE for super_admin</pre>';
    } else {
        echo '<pre class="success">✓ WOULD ALLOW ACCESS</pre>';
        echo '<pre class="success">  isAdmin() returned TRUE</pre>';
    }

    echo '</div>';
}

// Show session role vs database role comparison
if (isLoggedIn()) {
    echo '<div class="section">';
    echo '<h2>⚠️ COMPARISON</h2>';

    try {
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $dbRole = $result->fetch_assoc()['role'] ?? 'UNKNOWN';
        $stmt->close();

        $sessionRole = $_SESSION['role'] ?? 'NOT SET';

        echo '<table>';
        echo '<tr><th>Source</th><th>Role Value</th><th>Status</th></tr>';
        echo '<tr>';
        echo '<td>Session ($_SESSION[\'role\'])</td>';
        echo '<td><strong>' . htmlspecialchars($sessionRole) . '</strong></td>';
        echo '<td>' . ($sessionRole !== 'NOT SET' ? '<span class="success">✓</span>' : '<span class="error">✗</span>') . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>Database (users.role)</td>';
        echo '<td><strong>' . htmlspecialchars($dbRole) . '</strong></td>';
        echo '<td>' . ($dbRole !== 'UNKNOWN' ? '<span class="success">✓</span>' : '<span class="error">✗</span>') . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>Match Status</td>';
        echo '<td colspan="2" class="' . ($sessionRole === $dbRole ? 'success' : 'error') . '">';
        if ($sessionRole === $dbRole) {
            echo '✓ SESSION AND DATABASE MATCH';
        } else {
            echo '✗ MISMATCH! Session has "' . htmlspecialchars($sessionRole) . '" but database has "' . htmlspecialchars($dbRole) . '"';
            echo '<br><pre class="warning">⚠️ You need to LOGOUT and LOGIN again to sync session with database!</pre>';
        }
        echo '</td>';
        echo '</tr>';
        echo '</table>';
    } catch (Exception $e) {
        echo '<pre class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    }

    echo '</div>';
}

// Recommendations
echo '<div class="section">';
echo '<h2>💡 RECOMMENDATIONS</h2>';

if (isLoggedIn()) {
    $sessionRole = $_SESSION['role'] ?? 'NOT SET';

    if ($sessionRole === 'super_admin') {
        if (!isAdmin()) {
            echo '<pre class="error">✗ Problem: You are super_admin but isAdmin() returns FALSE</pre>';
            echo '<pre class="warning">⚠️ Solution: The isAdmin() function needs to check for super_admin role</pre>';
        } else {
            echo '<pre class="success">✓ Everything looks good!</pre>';
            echo '<pre class="success">  You should be able to access License Manager</pre>';
        }
    } else {
        echo '<pre class="info">Session role is: ' . htmlspecialchars($sessionRole) . '</pre>';
        echo '<pre class="info">If you created superadmin but session still shows old role:</pre>';
        echo '<pre>  1. Logout completely</pre>';
        echo '<pre>  2. Clear browser cookies/cache</pre>';
        echo '<pre>  3. Login again with superadmin credentials</pre>';
    }
} else {
    echo '<pre class="warning">Please login first to see diagnostics</pre>';
}

echo '</div>';
?>

<div class="section">
    <h2>🔗 QUICK ACTIONS</h2>
    <p>
        <a href="<?= BASE_PATH ?>/login.php" style="color: #00aaff;">Login Page</a> |
        <a href="<?= BASE_PATH ?>/logout.php" style="color: #00aaff;">Logout</a> |
        <a href="<?= BASE_PATH ?>/admin/license-manager.php" style="color: #00aaff;">Try License Manager</a> |
        <a href="<?= BASE_PATH ?>/manage_users.php" style="color: #00aaff;">Manage Users</a>
    </p>
</div>

</body>
</html>

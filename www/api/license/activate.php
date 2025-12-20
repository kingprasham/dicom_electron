<?php
/**
 * License Activation API
 * 
 * POST - Activate a license key on this machine and create user session
 * DELETE - Deactivate license from this machine
 */

define('DICOM_VIEWER', true);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/LicenseManager.php';

$licenseManager = new LicenseManager();

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $licenseKey = $input['license_key'] ?? '';
            $machineId = $input['machine_id'] ?? LicenseManager::generateMachineId();
            
            if (empty($licenseKey)) {
                echo json_encode(['success' => false, 'error' => 'License key is required']);
                exit;
            }
            
            $machineInfo = [
                'machine_name' => $input['machine_name'] ?? gethostname(),
                'os_info' => $input['os_info'] ?? php_uname('s') . ' ' . php_uname('r'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ];
            
            $result = $licenseManager->activateLicense($licenseKey, $machineId, $machineInfo);
            
            if ($result['success']) {
                // Get license details for session
                $license = $licenseManager->getLicenseByKey($licenseKey);
                
                // Create or get default admin user for this installation
                $db = getDbConnection();
                
                // Look for admin user or create one
                $stmt = $db->prepare("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$user) {
                    // Create default admin user if none exists
                    $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
                    $username = 'admin';
                    $email = $license['customer_email'] ?: 'admin@local';
                    $fullName = $license['customer_name'] ?: 'Administrator';

                    // Check if setup_completed column exists
                    $hasSetupCol = false;
                    $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
                    if ($colCheck && $colCheck->num_rows > 0) {
                        $hasSetupCol = true;
                    }

                    if ($hasSetupCol) {
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active, setup_completed) VALUES (?, ?, ?, ?, 'admin', 1, 0)");
                    } else {
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 'admin', 1)");
                    }
                    $stmt->bind_param("ssss", $username, $email, $passwordHash, $fullName);
                    $stmt->execute();
                    $userId = $db->insert_id;
                    $stmt->close();

                    $user = [
                        'id' => $userId,
                        'username' => $username,
                        'full_name' => $fullName,
                        'email' => $email,
                        'role' => 'admin',
                        'setup_completed' => 0
                    ];
                }
                
                // Create session for this user
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                $_SESSION['license_activated'] = true; // Flag for tour
                
                // Update last login time
                $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->bind_param("i", $user['id']);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Create session record in database
                $sessionId = session_id();
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
                
                $sessionStmt = $db->prepare("
                    INSERT INTO sessions (session_id, user_id, ip_address, user_agent, expires_at)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $sessionStmt->bind_param("sisss", $sessionId, $user['id'], $ipAddress, $userAgent, $expiresAt);
                $sessionStmt->execute();
                $_SESSION['session_db_id'] = $sessionStmt->insert_id;
                $sessionStmt->close();
                
                logMessage("License activated and user {$user['username']} logged in via license key", 'info', 'auth.log');
                
                $result['user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role']
                ];
            }
            
            echo json_encode($result);
            break;
            
        case 'DELETE':
            // Deactivate license from this machine
            $result = $licenseManager->deactivateInstallation();
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'License deactivated' : 'Failed to deactivate'
            ]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    logMessage("License activation error: " . $e->getMessage(), 'error', 'license.log');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

<?php
/**
 * 2FA Setup Page
 * Allows users to enable/disable two-factor authentication
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';

// Require login
requireLogin('../login.php');

// Only super admin can access 2FA setup for private settings
$db = getDbConnection();
$stmt = $db->prepare("SELECT is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$userInfo = $result->fetch_assoc();
$stmt->close();

$isSuperAdmin = $userInfo && $userInfo['is_super_admin'];

if (!$isSuperAdmin) {
    // Redirect non-super admin users
    header('Location: ' . BASE_PATH . '/admin/general-settings.php?error=access_denied');
    exit;
}

$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$userEmail = $_SESSION['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Two-Factor Authentication Setup - DICOM Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/davidshimjs-qrcodejs@0.0.2/qrcode.min.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            min-height: 100vh;
            color: #fff;
        }
        .navbar-custom {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .setup-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 30px;
            max-width: 600px;
            margin: 0 auto;
        }
        .qr-container {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
            display: inline-block;
            margin: 20px 0;
        }
        .qr-container img {
            max-width: 200px;
            height: auto;
        }
        .secret-box {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 15px;
            font-family: monospace;
            font-size: 1.2rem;
            letter-spacing: 0.3rem;
            text-align: center;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0d6efd, #0dcaf0);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            flex-shrink: 0;
        }
        .status-badge {
            font-size: 1rem;
            padding: 8px 16px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom mb-4">
        <div class="container">
            <a class="navbar-brand text-white" href="<?= BASE_PATH ?>/pages/patients.html">
                <i class="bi bi-heart-pulse-fill text-primary"></i>
                DICOM Viewer Pro
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= BASE_PATH ?>/admin/general-settings.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Settings
                </a>
                <span class="text-light">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($userName) ?>
                </span>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="setup-card">
            <div class="text-center mb-4">
                <i class="bi bi-shield-lock-fill text-primary" style="font-size: 4rem;"></i>
                <h2 class="mt-3">Two-Factor Authentication</h2>
                <p class="text-muted">Secure your account with an authenticator app</p>
            </div>

            <!-- Status Section -->
            <div id="statusSection" class="text-center mb-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <!-- Setup Section (Hidden by default) -->
            <div id="setupSection" style="display: none;">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Compatible Apps:</strong> Google Authenticator, Microsoft Authenticator, Authy, and others.
                </div>

                <!-- Step 1: Scan QR Code -->
                <div class="d-flex align-items-start gap-3 mb-4">
                    <div class="step-number">1</div>
                    <div>
                        <h5 class="text-white">Scan QR Code</h5>
                        <p class="text-muted mb-2">Open your authenticator app and scan this QR code:</p>
                        <div class="qr-container text-center">
                            <div id="qrCodeContainer"></div>
                            <div id="qrLoading" class="text-dark">
                                <div class="spinner-border spinner-border-sm" role="status"></div>
                                Generating QR code...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Manual Entry -->
                <div class="d-flex align-items-start gap-3 mb-4">
                    <div class="step-number">2</div>
                    <div class="flex-grow-1">
                        <h5 class="text-white">Or Enter Manually</h5>
                        <p class="text-muted mb-2">If you can't scan, enter this secret key in your app:</p>
                        <div class="secret-box mb-2" id="secretDisplay">Loading...</div>
                        <button class="btn btn-sm btn-outline-primary" onclick="copySecret()">
                            <i class="bi bi-clipboard"></i> Copy Secret
                        </button>
                    </div>
                </div>

                <!-- Step 3: Verify Code -->
                <div class="d-flex align-items-start gap-3 mb-4">
                    <div class="step-number">3</div>
                    <div class="flex-grow-1">
                        <h5 class="text-white">Verify Setup</h5>
                        <p class="text-muted mb-2">Enter the 6-digit code from your authenticator app:</p>
                        <form id="verifyForm" class="row g-2 align-items-center">
                            <div class="col-auto">
                                <input type="text" class="form-control form-control-lg text-center" 
                                       id="verifyCode" maxlength="6" pattern="[0-9]{6}"
                                       style="width: 150px; letter-spacing: 0.5rem; font-size: 1.5rem;"
                                       placeholder="000000" required>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-success btn-lg" id="enableBtn">
                                    <i class="bi bi-check-circle me-2"></i>Enable 2FA
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Enabled Section (Hidden by default) -->
            <div id="enabledSection" style="display: none;">
                <div class="text-center">
                    <div class="badge bg-success status-badge mb-3">
                        <i class="bi bi-shield-check me-2"></i>2FA is Enabled
                    </div>
                    <p class="text-muted">Your account is protected with two-factor authentication.</p>
                    
                    <div class="alert alert-warning mt-4">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> Disabling 2FA will make your account less secure.
                    </div>

                    <form id="disableForm" class="mt-4">
                        <div class="mb-3">
                            <label class="form-label">Enter your current 2FA code to disable:</label>
                            <input type="text" class="form-control form-control-lg text-center mx-auto" 
                                   id="disableCode" maxlength="6" pattern="[0-9]{6}"
                                   style="width: 150px; letter-spacing: 0.5rem; font-size: 1.5rem;"
                                   placeholder="000000" required>
                        </div>
                        <button type="submit" class="btn btn-danger" id="disableBtn">
                            <i class="bi bi-shield-x me-2"></i>Disable 2FA
                        </button>
                    </form>
                </div>
            </div>

            <!-- Error/Success Messages -->
            <div id="messageBox" class="alert mt-3" style="display: none;"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
        let currentSecret = '';

        // Check 2FA status on load
        document.addEventListener('DOMContentLoaded', checkStatus);

        async function checkStatus() {
            try {
                // First check if 2FA is already enabled for this user
                const response = await fetch(`${basePath}/auth/check_session.php`);
                const data = await response.json();
                
                if (!data.authenticated) {
                    window.location.href = `${basePath}/login.php`;
                    return;
                }

                // Check 2FA status via the setup API (GET returns status)
                const statusResponse = await fetch(`${basePath}/api/auth/setup-2fa.php`);
                
                if (statusResponse.ok) {
                    // User has a secret, check if 2FA is actually enabled
                    const statusData = await statusResponse.json();
                    if (statusData.success && statusData.data) {
                        // 2FA is being set up - show setup section
                        currentSecret = statusData.data.secret;
                        document.getElementById('statusSection').style.display = 'none';
                        document.getElementById('setupSection').style.display = 'block';
                        generateQRCode(statusData.data.otpauth_url);
                        document.getElementById('qrLoading').style.display = 'none';
                        document.getElementById('secretDisplay').textContent = statusData.data.secret_formatted;
                        return;
                    }
                }

                // Check if 2FA is enabled by trying to access the check endpoint
                // This is a workaround - we need a dedicated status endpoint
                await check2FAEnabled();

            } catch (error) {
                console.error('Error checking status:', error);
                showMessage('Error checking 2FA status', 'danger');
            }
        }

        async function check2FAEnabled() {
            // Try to get user info to check if 2FA is enabled
            try {
                const response = await fetch(`${basePath}/api/auth/me.php`);
                const data = await response.json();
                
                document.getElementById('statusSection').style.display = 'none';
                
                if (data.success && data.user && data.user.totp_enabled) {
                    // 2FA is enabled
                    document.getElementById('enabledSection').style.display = 'block';
                } else {
                    // 2FA not enabled - generate new secret for setup
                    await generateNewSecret();
                }
            } catch (error) {
                console.error('Error:', error);
                // Default to setup mode
                await generateNewSecret();
            }
        }

        async function generateNewSecret() {
            try {
                const response = await fetch(`${basePath}/api/auth/setup-2fa.php`);
                const data = await response.json();
                
                if (data.success) {
                    currentSecret = data.data.secret;
                    document.getElementById('setupSection').style.display = 'block';
                    generateQRCode(data.data.otpauth_url);
                    document.getElementById('qrLoading').style.display = 'none';
                    document.getElementById('secretDisplay').textContent = data.data.secret_formatted;
                } else {
                    showMessage(data.error || 'Failed to generate 2FA secret', 'danger');
                }
            } catch (error) {
                console.error('Error generating secret:', error);
                showMessage('Connection error', 'danger');
            }
        }

        function generateQRCode(otpauthUrl) {
            const container = document.getElementById('qrCodeContainer');
            container.innerHTML = ''; // Clear any existing QR code
            
            if (typeof QRCode !== 'undefined') {
                new QRCode(container, {
                    text: otpauthUrl,
                    width: 200,
                    height: 200,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
            } else {
                // Fallback if QRCode library not loaded
                container.innerHTML = '<p class="text-danger">QR Code library failed to load. Please use the manual entry method.</p>';
            }
        }

        function copySecret() {
            const secretText = currentSecret;
            navigator.clipboard.writeText(secretText).then(() => {
                showMessage('Secret copied to clipboard!', 'success');
            }).catch(() => {
                // Fallback for older browsers
                const textarea = document.createElement('textarea');
                textarea.value = secretText;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                showMessage('Secret copied to clipboard!', 'success');
            });
        }

        // Verify and enable 2FA
        document.getElementById('verifyForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const code = document.getElementById('verifyCode').value;
            const btn = document.getElementById('enableBtn');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';

            try {
                const response = await fetch(`${basePath}/api/auth/setup-2fa.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code })
                });

                const data = await response.json();

                if (data.success) {
                    showMessage('Two-factor authentication has been enabled successfully!', 'success');
                    setTimeout(() => {
                        document.getElementById('setupSection').style.display = 'none';
                        document.getElementById('enabledSection').style.display = 'block';
                    }, 1500);
                } else {
                    showMessage(data.error || 'Invalid code. Please try again.', 'danger');
                    document.getElementById('verifyCode').value = '';
                    document.getElementById('verifyCode').focus();
                }
            } catch (error) {
                showMessage('Connection error. Please try again.', 'danger');
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Enable 2FA';
        });

        // Disable 2FA
        document.getElementById('disableForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to disable two-factor authentication?')) {
                return;
            }
            
            const code = document.getElementById('disableCode').value;
            const btn = document.getElementById('disableBtn');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Disabling...';

            try {
                const response = await fetch(`${basePath}/api/auth/setup-2fa.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code })
                });

                const data = await response.json();

                if (data.success) {
                    showMessage('Two-factor authentication has been disabled.', 'success');
                    setTimeout(() => {
                        document.getElementById('enabledSection').style.display = 'none';
                        generateNewSecret();
                    }, 1500);
                } else {
                    showMessage(data.error || 'Invalid code. Please try again.', 'danger');
                    document.getElementById('disableCode').value = '';
                    document.getElementById('disableCode').focus();
                }
            } catch (error) {
                showMessage('Connection error. Please try again.', 'danger');
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-shield-x me-2"></i>Disable 2FA';
        });

        // Allow only numbers in code inputs
        document.querySelectorAll('input[pattern]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        });

        function showMessage(message, type) {
            const box = document.getElementById('messageBox');
            box.className = `alert alert-${type} mt-3`;
            box.innerHTML = message;
            box.style.display = 'block';
            
            if (type === 'success') {
                setTimeout(() => {
                    box.style.display = 'none';
                }, 3000);
            }
        }
    </script>
</body>
</html>

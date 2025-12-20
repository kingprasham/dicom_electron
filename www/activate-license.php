<?php
/**
 * License Activation Page
 * Users enter their license key or start a trial here
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/LicenseManager.php';

$licenseManager = new LicenseManager();
$localLicense = $licenseManager->checkLocalLicense();
$machineId = LicenseManager::generateMachineId();

// If already have valid license, redirect to app
if ($localLicense['valid'] && !isset($_GET['manage'])) {
    header('Location: ' . BASE_PATH . '/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <meta name="machine-id" content="<?= htmlspecialchars($machineId) ?>">
    <title>License Activation - DICOM Viewer Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 50%, #0a0e27 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .activation-container {
            width: 100%;
            max-width: 550px;
            padding: 20px;
        }
        .activation-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }
        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #0d6efd, #6610f2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 2.5rem;
            color: white;
        }
        .license-input {
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            color: #fff;
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            letter-spacing: 2px;
            text-align: center;
            padding: 15px;
            text-transform: uppercase;
        }
        .license-input:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
            background: rgba(0, 0, 0, 0.4);
            color: #fff;
        }
        .license-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 3px;
        }
        .btn-activate {
            background: linear-gradient(135deg, #0d6efd, #0056b3);
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
        }
        .btn-activate:hover {
            background: linear-gradient(135deg, #0b5ed7, #004494);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(13, 110, 253, 0.4);
        }
        .divider {
            display: flex;
            align-items: center;
            margin: 25px 0;
            color: rgba(255, 255, 255, 0.5);
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.2);
        }
        .divider span {
            padding: 0 15px;
            font-size: 0.9rem;
        }
        .trial-option {
            background: rgba(25, 135, 84, 0.1);
            border: 1px solid rgba(25, 135, 84, 0.3);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .current-license {
            background: rgba(13, 110, 253, 0.1);
            border: 1px solid rgba(13, 110, 253, 0.3);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .license-key-display {
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            background: rgba(0, 0, 0, 0.3);
            padding: 8px 15px;
            border-radius: 8px;
            display: inline-block;
        }
        .expired-badge {
            background: linear-gradient(135deg, #dc3545, #b02a37);
        }
        .revoked-badge {
            background: linear-gradient(135deg, #6c757d, #495057);
        }
    </style>
</head>
<body>
    <div class="activation-container">
        <div class="activation-card">
            <div class="logo-container">
                <div class="logo-icon">
                    <i class="bi bi-heart-pulse-fill"></i>
                </div>
                <h3 class="text-white mb-1">DICOM Viewer Pro</h3>
                <p class="text-muted mb-0">License Activation</p>
            </div>

            <?php if ($localLicense['valid']): ?>
                <!-- Current License Info -->
                <div class="current-license">
                    <h6 class="text-primary mb-2">
                        <i class="bi bi-check-circle-fill"></i> License Active
                    </h6>
                    <div class="license-key-display mb-2">
                        <?= htmlspecialchars($localLicense['license_key']) ?>
                    </div>
                    <p class="mb-0 small text-muted">
                        <?php if ($localLicense['days_remaining'] !== null): ?>
                            <?= $localLicense['days_remaining'] ?> days remaining
                        <?php else: ?>
                            Perpetual License
                        <?php endif; ?>
                    </p>
                </div>
                <a href="<?= BASE_PATH ?>/login.php" class="btn btn-primary btn-activate mb-3">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Continue to Application
                </a>
                <div class="text-center">
                    <button class="btn btn-link text-muted" onclick="showChangeKey()">
                        <i class="bi bi-key"></i> Change License Key
                    </button>
                </div>
            <?php elseif (isset($localLicense['reason']) && $localLicense['reason'] === 'expired'): ?>
                <!-- Expired License -->
                <div class="alert alert-danger text-center">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>License Expired</strong>
                    <p class="mb-0 small mt-2">Your license has expired. Please enter a new license key to continue.</p>
                </div>
            <?php elseif (isset($localLicense['reason']) && $localLicense['reason'] === 'revoked'): ?>
                <!-- Revoked License -->
                <div class="alert alert-secondary text-center">
                    <i class="bi bi-slash-circle-fill me-2"></i>
                    <strong>License Revoked</strong>
                    <p class="mb-0 small mt-2">Your license has been suspended. Please contact support.</p>
                </div>
            <?php endif; ?>

            <!-- License Key Input -->
            <div id="activationForm">
                <div class="mb-4">
                    <label class="form-label text-light">Enter License Key</label>
                    <input type="text" 
                           class="form-control license-input" 
                           id="licenseKeyInput"
                           placeholder="DICOM-XXXX-XXXX-XXXX-XXXX"
                           maxlength="29"
                           autocomplete="off">
                    <div id="keyError" class="text-danger small mt-2" style="display: none;"></div>
                </div>
                
                <button class="btn btn-primary btn-activate" onclick="activateLicense()">
                    <i class="bi bi-key-fill me-2"></i> Activate License
                </button>

                <div class="divider">
                    <span>or</span>
                </div>

                <div class="trial-option">
                    <h6 class="text-success mb-2">
                        <i class="bi bi-gift-fill"></i> Start Free Trial
                    </h6>
                    <p class="text-muted small mb-3">Try DICOM Viewer Pro free for 15 days</p>
                    <button class="btn btn-outline-success" onclick="requestTrial()">
                        <i class="bi bi-play-fill me-1"></i> Start Trial
                    </button>
                </div>
            </div>

            <!-- Success State -->
            <div id="successState" style="display: none;">
                <div class="text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="text-white">License Activated!</h4>
                    <p class="text-muted" id="successMessage">Your license has been activated successfully.</p>
                    
                    <div class="d-grid gap-3 mt-4">
                        <a href="<?= BASE_PATH ?>/setup.php" class="btn btn-primary btn-activate">
                            <i class="bi bi-mortarboard me-2"></i> Complete Setup Wizard (Recommended)
                        </a>
                        <a href="<?= BASE_PATH ?>/login.php" class="btn btn-outline-secondary">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Skip Setup & Login Directly
                        </a>
                    </div>
                </div>
            </div>

            <!-- Loading State -->
            <div id="loadingState" style="display: none;">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
                    <p class="text-muted">Activating license...</p>
                </div>
            </div>
        </div>

        <p class="text-center text-muted mt-4 small">
            <i class="bi bi-info-circle"></i>
            Need a license? Contact your administrator
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]').content;
        const machineId = document.querySelector('meta[name="machine-id"]').content;

        // Format license key as user types
        document.getElementById('licenseKeyInput')?.addEventListener('input', function(e) {
            let value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            
            // Add DICOM- prefix if not present
            if (value.length > 0 && !value.startsWith('DICOM')) {
                // Format as groups of 4
                let formatted = '';
                for (let i = 0; i < value.length && i < 20; i++) {
                    if (i > 0 && i % 4 === 0) formatted += '-';
                    formatted += value[i];
                }
                e.target.value = 'DICOM-' + formatted;
            } else if (value.startsWith('DICOM')) {
                value = value.substring(5);
                let formatted = 'DICOM-';
                for (let i = 0; i < value.length && i < 20; i++) {
                    if (i > 0 && i % 4 === 0) formatted += '-';
                    formatted += value[i];
                }
                e.target.value = formatted;
            }
        });

        async function activateLicense() {
            const key = document.getElementById('licenseKeyInput').value.trim();
            const errorEl = document.getElementById('keyError');
            
            if (!key || key.length < 25) {
                errorEl.textContent = 'Please enter a valid license key';
                errorEl.style.display = 'block';
                return;
            }

            showLoading();

            try {
                const response = await fetch(`${basePath}/api/license/activate.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        license_key: key,
                        machine_id: machineId,
                        machine_name: navigator.platform,
                        os_info: navigator.userAgent
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Your license has been activated successfully!');
                } else {
                    hideLoading();
                    errorEl.textContent = data.error || 'Failed to activate license';
                    errorEl.style.display = 'block';
                }
            } catch (error) {
                hideLoading();
                errorEl.textContent = 'Connection error. Please try again.';
                errorEl.style.display = 'block';
            }
        }

        async function requestTrial() {
            // Trials require a special endpoint - for now show message
            alert('Please contact your administrator to receive a trial license key.');
        }

        function showChangeKey() {
            document.querySelector('.current-license').style.display = 'none';
            document.querySelector('a.btn-activate').style.display = 'none';
            document.querySelector('.text-center:last-child').style.display = 'none';
        }

        function showLoading() {
            document.getElementById('activationForm').style.display = 'none';
            document.getElementById('loadingState').style.display = 'block';
        }

        function hideLoading() {
            document.getElementById('activationForm').style.display = 'block';
            document.getElementById('loadingState').style.display = 'none';
        }

        function showSuccess(message) {
            document.getElementById('loadingState').style.display = 'none';
            document.getElementById('successMessage').textContent = message;
            document.getElementById('successState').style.display = 'block';
        }
    </script>
</body>
</html>

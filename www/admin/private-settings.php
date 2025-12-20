<?php
/**
 * Private Settings Page
 * Protected settings: DICOM Nodes, DICOM Printers, Orthanc PACS, Advanced, Danger Zone
 * Requires 2FA (TOTP) authentication to access
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/TotpAuth.php';

requireLogin('../login.php');

if (!isAdmin()) {
    header('Location: ../pages/patients.html');
    exit;
}

$userName = $_SESSION['username'] ?? 'Admin';

// Get detected IP for DICOM node configuration
function getLocalIPAddress() {
    $localIP = '0.0.0.0';
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $localIP = $_SERVER['SERVER_ADDR'];
    } elseif (!empty($_SERVER['LOCAL_ADDR'])) {
        $localIP = $_SERVER['LOCAL_ADDR'];
    } else {
        $localIP = gethostbyname(gethostname());
    }
    if ($localIP == '::1' || $localIP == '127.0.0.1') {
        $localIP = '0.0.0.0';
    }
    return $localIP;
}

$detectedIP = getLocalIPAddress();

// Check if user has 2FA enabled
$db = getDbConnection();
$stmt = $db->prepare("SELECT totp_enabled, totp_secret FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user2FA = $result->fetch_assoc();
$stmt->close();

$has2FAEnabled = ($user2FA && $user2FA['totp_enabled'] && !empty($user2FA['totp_secret']));
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <meta name="detected-ip" content="<?= htmlspecialchars($detectedIP) ?>">
    <meta name="has-2fa" content="<?= $has2FAEnabled ? 'true' : 'false' ?>">
    <title>Private Settings - DICOM Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/input-fix.css">
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
        .settings-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .settings-card:hover {
            border-color: #0d6efd;
            box-shadow: 0 0 20px rgba(13, 110, 253, 0.2);
        }
        .category-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(13, 110, 253, 0.5);
        }
        .category-icon {
            font-size: 1.5rem;
            color: #0d6efd;
        }
        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
        }
        .form-control:focus, .form-select:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: #0d6efd;
            color: #fff;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .table-dark-custom {
            --bs-table-bg: rgba(255, 255, 255, 0.02);
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, 0.1);
        }
        .modal-content {
            background: #1a1f3a;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
        }
        .modal-header, .modal-footer {
            border-color: rgba(255, 255, 255, 0.1);
        }
        .settings-nav-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .save-indicator {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }
        .lock-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 14, 39, 0.98);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .lock-card {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(13, 110, 253, 0.5);
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            text-align: center;
        }
        .lock-icon {
            font-size: 4rem;
            color: #0d6efd;
            margin-bottom: 20px;
        }
        .code-input {
            font-size: 2rem;
            letter-spacing: 0.5rem;
            text-align: center;
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.2);
            padding: 15px;
        }
        .code-input:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
    </style>
</head>
<body>
    <!-- Lock Overlay -->
    <div class="lock-overlay" id="lockOverlay">
        <div class="lock-card">
            <div id="lockContent">
                <!-- Initial state - checking -->
                <div id="checkingState">
                    <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
                    <h4>Checking Security...</h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white" href="<?= BASE_PATH ?>/patients.php">
                <i class="bi bi-heart-pulse-fill text-primary"></i>
                DICOM Viewer Pro
            </a>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-sm btn-outline-primary" id="lockBtn" style="display:none;">
                    <i class="bi bi-lock"></i> Lock Settings
                </button>
                <a href="<?= BASE_PATH ?>/pages/patients.html" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <span class="text-light">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($userName) ?>
                </span>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content (hidden until unlocked) -->
    <div class="container" id="settingsContent" style="display:none;">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white">
                <i class="bi bi-shield-lock text-primary"></i>
                Private Settings
            </h2>
            <div class="d-flex gap-2">
                <a href="<?= BASE_PATH ?>/admin/setup-2fa.php" class="btn btn-outline-success">
                    <i class="bi bi-shield-check"></i> Manage 2FA
                </a>
            </div>
        </div>

        <!-- Settings Navigation -->
        <div class="settings-nav-card">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="text-muted me-2">Settings:</span>
                <a href="<?= BASE_PATH ?>/admin/general-settings.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-hospital"></i> General
                </a>
                <a href="<?= BASE_PATH ?>/admin/folder-config.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-folder-symlink"></i> Folder Monitoring
                </a>
                <a href="<?= BASE_PATH ?>/admin/private-settings.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-shield-lock"></i> Private Settings
                </a>
                <a href="<?= BASE_PATH ?>/admin/setup-2fa.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-shield-check"></i> 2FA Security
                </a>
                <a href="<?= BASE_PATH ?>/admin/hospital-config.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-database"></i> Backup & Import
                </a>
            </div>
        </div>

        <!-- Save Indicator -->
        <div class="save-indicator alert alert-success" id="saveIndicator">
            <i class="bi bi-check-circle-fill"></i> Saved successfully
        </div>

        <!-- DICOM Nodes Configuration -->
        <div class="settings-card">
            <div class="category-header d-flex justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-wifi category-icon"></i>
                    <h4 class="mb-0 text-white">DICOM Nodes (Servers)</h4>
                </div>
                <button type="button" class="btn btn-primary btn-sm" onclick="openNodeModal()">
                    <i class="bi bi-plus-circle"></i> Add Node
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-dark-custom table-hover" id="nodesTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>AE Title</th>
                            <th>Host/IP</th>
                            <th>Port</th>
                            <th>Default</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- DICOM Printers Configuration -->
        <div class="settings-card">
            <div class="category-header d-flex justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-printer category-icon"></i>
                    <h4 class="mb-0 text-white">DICOM Printers</h4>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-info btn-sm" onclick="detectSystemPrinters()">
                        <i class="bi bi-search"></i> Detect Printers
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openPrinterModal()">
                        <i class="bi bi-plus-circle"></i> Add Printer
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-dark-custom table-hover" id="printersTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>AE Title</th>
                            <th>Host/IP</th>
                            <th>Port</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PCPNDT Printers Configuration -->
        <div class="settings-card">
            <div class="category-header d-flex justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-file-medical category-icon text-success"></i>
                    <h4 class="mb-0 text-white">PCPNDT Printers</h4>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-success btn-sm" onclick="openPcpndtPrinterModal()">
                        <i class="bi bi-plus-circle"></i> Add PCPNDT Printer
                    </button>
                </div>
            </div>
            <p class="text-muted small mb-3">
                Configure printers for PCPNDT Form F printing. Pre-Conception and Pre-Natal Diagnostic Techniques Act compliance.
            </p>

            <div class="table-responsive">
                <table class="table table-dark-custom table-hover" id="pcpndtPrintersTable">
                    <thead>
                        <tr>
                            <th>Printer Name</th>
                            <th>Paper Size</th>
                            <th>Color Mode</th>
                            <th>Default</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="5" class="text-center text-muted">No PCPNDT printers configured</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- PCPNDT Default Settings -->
            <div class="mt-4 p-3 border border-secondary rounded">
                <h6 class="text-info mb-3"><i class="bi bi-gear me-2"></i>Default PCPNDT Print Settings</h6>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-light">Default Paper Size</label>
                        <select class="form-select" id="pcpndtDefaultPaperSize" name="pcpndt_default_paper_size">
                            <option value="A5">A5 (148 × 210 mm)</option>
                            <option value="A4">A4 (210 × 297 mm)</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-light">Default Color Mode</label>
                        <select class="form-select" id="pcpndtDefaultColorMode" name="pcpndt_default_color_mode">
                            <option value="color">Color (Default)</option>
                            <option value="grayscale">Grayscale</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3 d-flex align-items-end">
                        <button type="button" class="btn btn-success w-100" id="savePcpndtSettingsBtn">
                            <i class="bi bi-save"></i> Save PCPNDT Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orthanc PACS Server -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-server category-icon"></i>
                <h4 class="mb-0 text-white">Orthanc PACS Server</h4>
            </div>

            <form id="orthancForm">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-light">Orthanc URL</label>
                        <input type="url" class="form-control" name="orthanc_url" placeholder="http://localhost:8042">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label text-light">Username</label>
                        <input type="text" class="form-control" name="orthanc_username" placeholder="orthanc">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label text-light">Password</label>
                        <input type="password" class="form-control" name="orthanc_password" placeholder="********">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-light">DICOM AE Title</label>
                        <input type="text" class="form-control" name="orthanc_dicom_aet" placeholder="ORTHANC" maxlength="16">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label text-light">DICOM Port</label>
                        <input type="number" class="form-control" name="orthanc_dicom_port" placeholder="4242">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label text-light">HTTP Port</label>
                        <input type="number" class="form-control" name="orthanc_http_port" placeholder="8042">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-light">DICOMweb Root Path</label>
                        <input type="text" class="form-control" name="orthanc_dicomweb_root" placeholder="/dicom-web">
                    </div>
                    <div class="col-md-3 mb-3 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-success w-100" id="testOrthancBtn">
                            <i class="bi bi-check-circle"></i> Test Connection
                        </button>
                    </div>
                    <div class="col-md-3 mb-3 d-flex align-items-end">
                        <button type="button" class="btn btn-success w-100" id="saveOrthancConfigBtn">
                            <i class="bi bi-save"></i> Save Config
                        </button>
                    </div>
                    <div class="col-12">
                        <div id="connectionResult" class="mt-2" style="display:none;"></div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Advanced Settings -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-sliders category-icon"></i>
                <h4 class="mb-0 text-white">Advanced Settings</h4>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="enable_technical_preview" id="techPreview">
                <label class="form-check-label text-light" for="techPreview">
                    Enable Technical Preview Mode
                </label>
            </div>
        </div>
    </div>

    <!-- Node Modal -->
    <div class="modal fade" id="nodeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Configure DICOM Node</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="nodeForm">
                        <input type="hidden" name="id" id="nodeId">
                        <div class="mb-3">
                            <label class="form-label">Friendly Name</label>
                            <input type="text" class="form-control" name="name" id="nodeName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">AE Title</label>
                            <input type="text" class="form-control" name="ae_title" id="nodeAET" required>
                        </div>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Host / IP</label>
                                <input type="text" class="form-control" name="host_name" id="nodeHost" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Port</label>
                                <input type="number" class="form-control" name="port" id="nodePort" placeholder="4242" required>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default" id="nodeDefault">
                            <label class="form-check-label">Set as Default Node</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveNode()">Save Node</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Printer Modal -->
    <div class="modal fade" id="printerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Configure DICOM Printer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="printerForm">
                        <input type="hidden" name="id" id="printerId">
                        <div class="mb-3">
                            <label class="form-label">Printer Name</label>
                            <input type="text" class="form-control" name="name" id="printerName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">AE Title</label>
                            <input type="text" class="form-control" name="ae_title" id="printerAET" required>
                        </div>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Host / IP</label>
                                <input type="text" class="form-control" name="host_name" id="printerHost" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Port</label>
                                <input type="number" class="form-control" name="port" id="printerPort" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="printerDesc" rows="2"></textarea>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active" id="printerActive" checked>
                            <label class="form-check-label">Printer Active</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_default" id="printerDefault">
                            <label class="form-check-label">Set as Default Printer</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePrinter()">Save Printer</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= BASE_PATH ?>/assets/js/electron-input-fix.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]').content;
        const has2FA = document.querySelector('meta[name="has-2fa"]').content === 'true';
        let nodeModal, printerModal;

        document.addEventListener('DOMContentLoaded', () => {
            nodeModal = new bootstrap.Modal(document.getElementById('nodeModal'));
            printerModal = new bootstrap.Modal(document.getElementById('printerModal'));

            checkSecurityStatus();
            setupEventListeners();
        });

        async function checkSecurityStatus() {
            const lockContent = document.getElementById('lockContent');

            try {
                // Check global 2FA status for private settings
                const response = await fetch(`${basePath}/api/settings/2fa-private-settings.php`);
                const data = await response.json();
                
                if (data.success) {
                    // If 2FA is not enabled globally, unlock immediately
                    if (!data.data.enabled) {
                        unlockSettings();
                        return;
                    }
                    
                    // If already verified in session, unlock
                    if (data.data.verified) {
                        unlockSettings();
                        return;
                    }
                    
                    // 2FA is enabled but not verified - show unlock screen
                    showUnlockScreen();
                } else {
                    // Error checking status - unlock for now
                    unlockSettings();
                }
            } catch (error) {
                console.error('Error checking 2FA status:', error);
                // On error, unlock to not block access
                unlockSettings();
            }
        }

        function show2FASetupPrompt() {
            const lockContent = document.getElementById('lockContent');
            lockContent.innerHTML = `
                <i class="bi bi-shield-exclamation lock-icon text-warning"></i>
                <h3 class="text-white mb-3">2FA Required</h3>
                <p class="text-muted mb-4">You need to set up Two-Factor Authentication (2FA) before accessing Private Settings. This adds an extra layer of security to protect sensitive configurations.</p>
                <a href="${basePath}/admin/setup-2fa.php" class="btn btn-success btn-lg">
                    <i class="bi bi-shield-check me-2"></i>Setup 2FA Now
                </a>
                <div class="mt-4">
                    <a href="${basePath}/admin/general-settings.php" class="text-muted small">
                        <i class="bi bi-arrow-left"></i> Back to General Settings
                    </a>
                </div>
            `;
        }

        function showUnlockScreen() {
            const lockContent = document.getElementById('lockContent');
            lockContent.innerHTML = `
                <i class="bi bi-shield-lock-fill lock-icon"></i>
                <h3 class="text-white mb-3">Private Settings Locked</h3>
                <p class="text-muted mb-4">Enter your 6-digit 2FA code from your authenticator app to unlock.</p>
                
                <div class="mb-4">
                    <input type="text" class="form-control code-input" id="unlockCodeInput" maxlength="6" 
                           placeholder="000000" inputmode="numeric" pattern="[0-9]*" autofocus>
                </div>
                <button class="btn btn-primary btn-lg" onclick="verify2FACode()">
                    <i class="bi bi-unlock me-2"></i>Unlock
                </button>
                
                <div class="mt-4">
                    <a href="${basePath}/admin/general-settings.php" class="text-muted small">
                        <i class="bi bi-arrow-left"></i> Back to General Settings
                    </a>
                </div>
            `;

            // Add enter key listener
            setTimeout(() => {
                const input = document.getElementById('unlockCodeInput');
                if (input) {
                    input.addEventListener('keypress', e => {
                        if (e.key === 'Enter') verify2FACode();
                    });
                    input.addEventListener('input', function() {
                        this.value = this.value.replace(/[^0-9]/g, '');
                    });
                    input.focus();
                }
            }, 100);
        }

        async function verify2FACode() {
            const code = document.getElementById('unlockCodeInput').value;

            if (code.length !== 6) {
                alert('Please enter a 6-digit code');
                return;
            }

            try {
                const response = await fetch(`${basePath}/api/settings/2fa-private-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'verify', code: code })
                });
                const data = await response.json();

                if (data.success) {
                    unlockSettings();
                } else {
                    alert(data.error || 'Invalid code. Please try again.');
                    document.getElementById('unlockCodeInput').value = '';
                    document.getElementById('unlockCodeInput').focus();
                }
            } catch (error) {
                alert('Error verifying code: ' + error.message);
            }
        }

        function unlockSettings() {
            document.getElementById('lockOverlay').style.display = 'none';
            document.getElementById('settingsContent').style.display = 'block';
            document.getElementById('lockBtn').style.display = 'inline-block';

            // Load settings data
            loadSettings();
            loadNodes();
            loadPrinters();
        }

        function lockSettings() {
            sessionStorage.removeItem('privateSettingsUnlocked');
            document.getElementById('lockOverlay').style.display = 'flex';
            document.getElementById('settingsContent').style.display = 'none';
            document.getElementById('lockBtn').style.display = 'none';
            showUnlockScreen();
        }

        function setupEventListeners() {
            document.getElementById('lockBtn').addEventListener('click', lockSettings);
            document.getElementById('testOrthancBtn').addEventListener('click', testOrthancConnection);
            document.getElementById('saveOrthancConfigBtn').addEventListener('click', saveOrthancConfiguration);
        }

        // Load general settings
        async function loadSettings() {
            try {
                const response = await fetch(`${basePath}/api/settings/get-settings.php`);
                const data = await response.json();
                if (data.success) {
                    Object.values(data.settings).flat().forEach(setting => {
                        const input = document.querySelector(`[name="${setting.setting_key}"]`);
                        if (input) {
                            if (input.type === 'checkbox') input.checked = Boolean(setting.setting_value);
                            else input.value = setting.setting_value || '';
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        // Nodes Management
        async function loadNodes() {
            const tbody = document.querySelector('#nodesTable tbody');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Loading...</td></tr>';

            try {
                const response = await fetch(`${basePath}/api/settings/nodes.php`);
                const data = await response.json();

                if (data.success && data.nodes.length > 0) {
                    tbody.innerHTML = data.nodes.map(node => `
                        <tr>
                            <td>${node.name}</td>
                            <td>${node.ae_title}</td>
                            <td>${node.host_name}</td>
                            <td>${node.port}</td>
                            <td>${node.is_default == 1 ? '<span class="badge bg-success">Default</span>' : ''}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='editNode(${JSON.stringify(node)})'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteNode(${node.id})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No nodes configured</td></tr>';
                }
            } catch (error) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading nodes</td></tr>';
            }
        }

        function openNodeModal() {
            document.getElementById('nodeForm').reset();
            document.getElementById('nodeId').value = '';
            const detectedIP = document.querySelector('meta[name="detected-ip"]')?.content || '0.0.0.0';
            if (detectedIP !== '0.0.0.0') {
                document.getElementById('nodeHost').value = detectedIP;
            }
            nodeModal.show();
        }

        window.editNode = function(node) {
            document.getElementById('nodeId').value = node.id;
            document.getElementById('nodeName').value = node.name;
            document.getElementById('nodeAET').value = node.ae_title;
            document.getElementById('nodeHost').value = node.host_name;
            document.getElementById('nodePort').value = node.port;
            document.getElementById('nodeDefault').checked = node.is_default == 1;
            nodeModal.show();
        };

        async function saveNode() {
            const data = {
                id: document.getElementById('nodeId').value,
                name: document.getElementById('nodeName').value,
                ae_title: document.getElementById('nodeAET').value,
                host_name: document.getElementById('nodeHost').value,
                port: document.getElementById('nodePort').value,
                is_default: document.getElementById('nodeDefault').checked
            };

            try {
                const response = await fetch(`${basePath}/api/settings/nodes.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();

                if (result.success) {
                    nodeModal.hide();
                    loadNodes();
                    showSuccess('Node saved');
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error saving node: ' + error.message);
            }
        }

        window.deleteNode = async function(id) {
            if (!confirm('Delete this node?')) return;

            try {
                const response = await fetch(`${basePath}/api/settings/nodes.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const result = await response.json();
                if (result.success) loadNodes();
                else alert('Error: ' + result.error);
            } catch (error) {
                alert('Error: ' + error.message);
            }
        };

        // Printers Management
        async function loadPrinters() {
            const tbody = document.querySelector('#printersTable tbody');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Loading...</td></tr>';

            try {
                const response = await fetch(`${basePath}/api/settings/printers.php`);
                const data = await response.json();

                if (data.success && data.printers.length > 0) {
                    tbody.innerHTML = data.printers.map(printer => `
                        <tr>
                            <td>${printer.name} ${printer.is_default == 1 ? '<span class="badge bg-primary ms-2">Default</span>' : ''}</td>
                            <td>${printer.ae_title}</td>
                            <td>${printer.host_name}</td>
                            <td>${printer.port}</td>
                            <td>${printer.is_active == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='editPrinter(${JSON.stringify(printer)})'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePrinter(${printer.id})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No printers configured</td></tr>';
                }
            } catch (error) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading printers</td></tr>';
            }
        }

        function openPrinterModal() {
            document.getElementById('printerForm').reset();
            document.getElementById('printerId').value = '';
            printerModal.show();
        }

        window.editPrinter = function(printer) {
            document.getElementById('printerId').value = printer.id;
            document.getElementById('printerName').value = printer.name;
            document.getElementById('printerAET').value = printer.ae_title;
            document.getElementById('printerHost').value = printer.host_name;
            document.getElementById('printerPort').value = printer.port;
            document.getElementById('printerDesc').value = printer.description || '';
            document.getElementById('printerActive').checked = printer.is_active == 1;
            document.getElementById('printerDefault').checked = printer.is_default == 1;
            printerModal.show();
        };

        async function savePrinter() {
            const data = {
                id: document.getElementById('printerId').value,
                name: document.getElementById('printerName').value,
                ae_title: document.getElementById('printerAET').value,
                host_name: document.getElementById('printerHost').value,
                port: document.getElementById('printerPort').value,
                description: document.getElementById('printerDesc').value,
                is_active: document.getElementById('printerActive').checked,
                is_default: document.getElementById('printerDefault').checked
            };

            try {
                const response = await fetch(`${basePath}/api/settings/printers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();

                if (result.success) {
                    printerModal.hide();
                    loadPrinters();
                    showSuccess('Printer saved');
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error saving printer: ' + error.message);
            }
        }

        window.deletePrinter = async function(id) {
            if (!confirm('Delete this printer?')) return;

            try {
                const response = await fetch(`${basePath}/api/settings/printers.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const result = await response.json();
                if (result.success) loadPrinters();
                else alert('Error: ' + result.error);
            } catch (error) {
                alert('Error: ' + error.message);
            }
        };

        async function detectSystemPrinters() {
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Detecting...';

            try {
                const response = await fetch(`${basePath}/api/settings/detect-printers.php`);
                const result = await response.json();

                if (result.success && result.printers && result.printers.length > 0) {
                    alert(`Found ${result.printers.length} printer(s). Add them manually using the Add Printer button.`);
                } else {
                    alert(result.message || 'No printers detected.');
                }
            } catch (error) {
                alert('Error detecting printers: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }

        // Orthanc Connection
        async function testOrthancConnection() {
            const btn = document.getElementById('testOrthancBtn');
            const resultDiv = document.getElementById('connectionResult');

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

            const orthancUrl = document.querySelector('[name="orthanc_url"]').value;
            const orthancUser = document.querySelector('[name="orthanc_username"]').value;
            const orthancPass = document.querySelector('[name="orthanc_password"]').value;

            try {
                const response = await fetch(`${basePath}/api/settings/test-connection.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        orthanc_url: orthancUrl,
                        orthanc_username: orthancUser,
                        orthanc_password: orthancPass
                    })
                });

                const data = await response.json();
                resultDiv.style.display = 'block';

                if (data.success) {
                    const orthancInfo = data.orthanc_info || {};
                    const version = orthancInfo.version || data.version || 'Unknown';
                    resultDiv.className = 'alert alert-success';
                    resultDiv.innerHTML = `<i class="bi bi-check-circle-fill"></i> Connection successful! Orthanc ${version}`;
                } else {
                    resultDiv.className = 'alert alert-danger';
                    resultDiv.innerHTML = `<i class="bi bi-x-circle-fill"></i> ${data.error}`;
                }
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.className = 'alert alert-danger';
                resultDiv.innerHTML = `<i class="bi bi-x-circle-fill"></i> Error: ${error.message}`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Test Connection';
            }
        }

        async function saveOrthancConfiguration() {
            const btn = document.getElementById('saveOrthancConfigBtn');
            const resultDiv = document.getElementById('connectionResult');

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

            const settings = {
                orthanc_url: document.querySelector('[name="orthanc_url"]').value,
                orthanc_username: document.querySelector('[name="orthanc_username"]').value,
                orthanc_password: document.querySelector('[name="orthanc_password"]').value,
                orthanc_dicom_aet: document.querySelector('[name="orthanc_dicom_aet"]').value || 'ORTHANC',
                dicom_aet: document.querySelector('[name="orthanc_dicom_aet"]').value || 'ORTHANC',
                dicom_port: parseInt(document.querySelector('[name="orthanc_dicom_port"]').value) || 4242,
                http_port: parseInt(document.querySelector('[name="orthanc_http_port"]').value) || 8042
            };

            try {
                const response = await fetch(`${basePath}/api/settings/update-orthanc-config.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(settings)
                });

                const data = await response.json();
                resultDiv.style.display = 'block';

                if (data.success) {
                    resultDiv.className = 'alert alert-success';
                    resultDiv.innerHTML = '<i class="bi bi-check-circle-fill"></i> Configuration saved!';
                    showSuccess('Orthanc configuration saved');
                } else {
                    resultDiv.className = 'alert alert-danger';
                    resultDiv.innerHTML = `<i class="bi bi-x-circle-fill"></i> ${data.error}`;
                }
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.className = 'alert alert-danger';
                resultDiv.innerHTML = `<i class="bi bi-x-circle-fill"></i> Error: ${error.message}`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-save"></i> Save Config';
            }
        }

        function showSuccess(message) {
            const indicator = document.getElementById('saveIndicator');
            indicator.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${message}`;
            indicator.style.display = 'block';
            setTimeout(() => { indicator.style.display = 'none'; }, 3000);
        }
    </script>
</body>
</html>
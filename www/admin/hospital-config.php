<?php
/**
 * Hospital Data Configuration
 * Configure Google Drive backup and import existing DICOM studies
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';

// Require login
requireLogin('../pages/login.html');

// Only admin can access
if (!isAdmin()) {
    header('Location: ../pages/patients.html');
    exit;
}

$userName = $_SESSION['username'] ?? 'Admin';
$db = getDbConnection();

// Get current hospital config
$configQuery = "SELECT * FROM hospital_data_config";
$configResult = $db->query($configQuery);
$config = [];
while ($row = $configResult->fetch_assoc()) {
    $config[$row['config_key']] = $row['config_value'];
}

// Get import statistics
$importStats = $db->query("
    SELECT 
        COUNT(*) as total_imported,
        COUNT(DISTINCT import_batch_id) as batches,
        SUM(file_size_bytes) as total_size
    FROM imported_studies
")->fetch_assoc();

// Get backup count from backup_history table
$backupCount = $db->query("
    SELECT COUNT(*) as backed_up_count 
    FROM backup_history 
    WHERE status = 'success'
")->fetch_assoc();

$importStats['backed_up_count'] = $backupCount['backed_up_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Hospital Data Configuration - Accurate Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/input-fix.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            min-height: 100vh;
        }
        .navbar-custom {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .config-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .config-card:hover {
            border-color: #0d6efd;
            box-shadow: 0 0 20px rgba(13, 110, 253, 0.2);
        }
        .stat-box {
            background: rgba(13, 110, 253, 0.1);
            border: 1px solid rgba(13, 110, 253, 0.3);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #0d6efd;
        }
        .progress-container {
            display: none;
            margin-top: 20px;
        }
        .file-browser {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            max-height: 300px;
            overflow-y: auto;
        }
        .wizard-step {
            opacity: 0.5;
            pointer-events: none;
        }
        .wizard-step.active {
            opacity: 1;
            pointer-events: auto;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .step-item.completed .step-circle {
            background: #198754;
            border-color: #198754;
            color: #fff;
        }
        .step-item.active .step-circle {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.2);
        }
        .log-viewer {
            background: #000;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
        }
        .log-entry {
            margin-bottom: 5px;
            color: #0f0;
        }
        .log-error {
            color: #f00;
        }
        .log-warning {
            color: #ff0;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white" href="<?= BASE_PATH ?>/patients.php">
                <i class="bi bi-heart-pulse-fill text-primary"></i>
                Accurate Viewer
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= BASE_PATH ?>/admin/general-settings.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <a href="<?= BASE_PATH ?>/pages/patients.html" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <span class="text-light">
                    <?= htmlspecialchars($userName) ?> (Admin)
                </span>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Header -->
        <h2 class="text-white mb-4">
            <i class="bi bi-database text-primary"></i>
            Backup & Import Configuration
        </h2>

        <!-- Settings Navigation -->
        <div class="config-card" style="padding: 15px; margin-bottom: 20px;">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="text-muted me-2">Settings:</span>
                <a href="<?= BASE_PATH ?>/admin/general-settings.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-hospital"></i> General
                </a>
                <a href="<?= BASE_PATH ?>/admin/folder-config.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-folder-symlink"></i> Folder Monitoring
                </a>
                <a href="<?= BASE_PATH ?>/admin/private-settings.php" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-shield-lock"></i> Private Settings
                </a>
                <a href="<?= BASE_PATH ?>/admin/hospital-config.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-database"></i> Backup & Import
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number"><?= $importStats['total_imported'] ?? 0 ?></div>
                    <div class="text-light">Studies Imported</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number"><?= $importStats['batches'] ?? 0 ?></div>
                    <div class="text-light">Import Batches</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number"><?= round(($importStats['total_size'] ?? 0) / (1024*1024*1024), 2) ?> GB</div>
                    <div class="text-light">Total Data</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number"><?= $importStats['backed_up_count'] ?? 0 ?></div>
                    <div class="text-light">Backed Up</div>
                </div>
            </div>
        </div>

        <!-- Backup Management -->
        <div class="config-card">
            <h4 class="text-white mb-4">
                <i class="bi bi-database text-primary"></i>
                Automated Multi-Account Backup
            </h4>

            <!-- Schedule Status -->
            <div class="alert alert-info mb-3">
                <div class="row">
                    <div class="col-md-6">
                        <strong><i class="bi bi-clock"></i> Schedule:</strong> Automatic backup every 6 hours
                    </div>
                    <div class="col-md-6">
                        <strong><i class="bi bi-calendar-check"></i> Next Backup:</strong> <span id="nextBackupTime">Loading...</span>
                    </div>
                </div>
            </div>

            <!-- Backup Accounts List -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="text-light mb-0">Backup Accounts</h5>
                    <button type="button" class="btn btn-sm btn-success" id="addAccountBtn">
                        <i class="bi bi-plus-circle"></i> Add Account
                    </button>
                </div>
                
                <div id="accountsList" class="border border-secondary rounded p-3" style="background: rgba(0,0,0,0.2);">
                    <div class="text-center text-muted">
                        <i class="bi bi-arrow-repeat spin"></i> Loading accounts...
                    </div>
                </div>
            </div>

            <!-- Backup Actions -->
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-primary" id="backupAllAccountsBtn">
                    <i class="bi bi-cloud-upload"></i> Backup to All Accounts Now
                </button>
                
                <button type="button" class="btn btn-outline-secondary" id="viewScheduleBtn">
                    <i class="bi bi-gear"></i> Configure Schedule
                </button>
            </div>

            <!-- Progress & Status -->
            <div id="backupAllProgress" style="display:none;" class="mb-3">
                <div class="progress" style="height: 30px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                         role="progressbar" style="width: 100%">
                        Creating backups...
                    </div>
                </div>
                <small class="text-muted">Backing up to all active accounts...</small>
            </div>

            <div id="backupAllStatus" style="display:none;"></div>
        </div>

        <!-- Add/Edit Account Modal -->
        <div class="modal fade" id="accountModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="accountModalTitle">Add Backup Account</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="editAccountId">
                        
                        <!-- Setup Instructions (only shown when adding new account) -->
                        <div class="alert alert-info mb-3" id="setupInstructions">
                            <h6 class="alert-heading"><i class="bi bi-lightbulb"></i> Quick Setup Guide</h6>
                            <ol class="mb-0 small">
                                <li><strong>Go to:</strong> <a href="https://console.developers.google.com" target="_blank" class="alert-link">Google Cloud Console</a></li>
                                <li><strong>Create Project:</strong> Click "New Project" → Name it (e.g., "DICOM-Backup")</li>
                                <li><strong>Enable API:</strong> Library → Search "Google Drive API" → Enable</li>
                                <li><strong>Create Credentials:</strong> Credentials → Create → Service Account</li>
                                <li><strong>Download JSON:</strong> Service Account → Keys → Add Key → JSON → Download</li>
                            </ol>
                            <div class="mt-2 d-flex gap-2">
                                <a href="<?= BASE_PATH ?>/admin/gdrive-guide.php" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-google"></i> Google Drive Guide
                                </a>
                                <a href="<?= BASE_PATH ?>/admin/dropbox-guide.php" target="_blank" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-dropbox"></i> Dropbox Guide
                                </a>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Name / Nickname</label>
                            <input type="text" class="form-control" id="accountName" placeholder="e.g., My Dropbox Backup">
                            <small class="text-muted">Friendly name to identify this account</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Backup Provider</label>
                            <select class="form-select" id="backupProvider">
                                <option value="dropbox">Dropbox (Recommended - Simpler!)</option>
                                <option value="google_drive">Google Drive</option>
                            </select>
                            <small class="text-muted">Choose where to store your backups</small>
                        </div>

                        <!-- Dropbox Fields -->
                        <div id="dropboxFields">
                            <div class="mb-3">
                                <label class="form-label">Dropbox Access Token</label>
                                <input type="text" class="form-control" id="dropboxAccessToken" placeholder="sl.ABC123...">
                                <small class="text-muted">Get this from Dropbox App settings - <a href="../setup/DROPBOX_BACKUP_SETUP.md" target="_blank" class="text-info">See Guide</a></small>
                            </div>
                            <div class="alert alert-success small">
                                <i class="bi bi-info-circle-fill"></i>
                                <strong>Easy Setup!</strong> Just paste your Dropbox token above. No JSON files needed!
                                <br><a href="https://www.dropbox.com/developers/apps" target="_blank" class="text-white"><u>Create Dropbox App →</u></a>
                            </div>
                        </div>

                        <!-- Google Drive Fields -->
                        <div id="googleDriveFields" style="display:none;">
                            <div class="mb-3">
                                <label class="form-label">Google Drive Credentials JSON</label>
                                <input type="file" class="form-control" id="accountCredentialsFile" accept=".json">
                                <small class="text-muted">OAuth 2.0 or Service Account JSON from Google Cloud Console</small>
                            </div>
                            <div class="alert alert-info small">
                                <i class="bi bi-info-circle-fill"></i>
                                <strong>Note:</strong> If using OAuth 2.0, you'll need to grant permissions. If using Service Account, share your folder with the <code>client_email</code>.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Backup Folder Name</label>
                            <input type="text" class="form-control" id="accountFolderName" value="/DICOM_Backups">
                            <small class="text-muted" id="folderHint">Folder path in Dropbox (created automatically)</small>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="accountIsActive" checked>
                            <label class="form-check-label" for="accountIsActive">
                                Active (Include in automatic backups)
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="saveAccountBtn">Save Account</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Configuration Modal -->
        <div class="modal fade" id="scheduleModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">Configure Backup Schedule</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Backup Frequency</label>
                            <select class="form-select" id="scheduleInterval">
                                <option value="1">Every 1 hour</option>
                                <option value="3">Every 3 hours</option>
                                <option value="6" selected>Every 6 hours (Recommended)</option>
                                <option value="12">Every 12 hours</option>
                                <option value="24">Every 24 hours (Daily)</option>
                            </select>
                            <small class="text-muted">How often automatic backups should run</small>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="scheduleEnabled" checked>
                            <label class="form-check-label" for="scheduleEnabled">
                                Enable Automatic Backups
                            </label>
                        </div>

                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle"></i>
                            After saving, the next backup will be scheduled based on your chosen interval.
                            Make sure Windows Task Scheduler is configured to run <code>backup-scheduler.php</code> hourly.
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="saveScheduleBtn">Save Schedule</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Auto-Folder Monitoring Link -->
        <div class="config-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="text-white mb-2">
                        <i class="bi bi-folder-symlink text-primary"></i>
                        Auto-Folder Monitoring
                    </h4>
                    <p class="text-muted mb-0">Configure automatic folder monitoring for DICOM imports</p>
                </div>
                <a href="<?= BASE_PATH ?>/admin/folder-config.php" class="btn btn-primary">
                    <i class="bi bi-gear"></i> Configure Folder Monitoring
                </a>
            </div>
        </div>

        <!-- Existing Studies Import -->
        <div class="config-card">
            <h4 class="text-white mb-4">
                <i class="bi bi-folder-fill text-primary"></i>
                Import Existing DICOM Studies
            </h4>
            
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                This will scan a directory for DICOM files and import them into the system. Large directories may take time to process.
            </div>

            <div class="mb-3">
                <label class="form-label text-light">DICOM Directory Path</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="dicomDirectoryPath" placeholder="C:\path\to\dicom\files or click Browse">
                    <button class="btn btn-outline-secondary" type="button" id="browseDirBtn">
                        <i class="bi bi-folder2-open"></i> Browse Server
                    </button>
                    <button class="btn btn-primary" type="button" id="scanDirectoryBtn">
                        <i class="bi bi-search"></i> Scan & Import
                    </button>
                </div>
                <small class="form-text text-muted">Type a path or click Browse to select a folder containing DICOM studies</small>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoBackup" checked>
                        <label class="form-check-label text-light" for="autoBackup">
                            Automatically backup imported files
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="recursiveScan" checked>
                        <label class="form-check-label text-light" for="recursiveScan">
                            Scan subdirectories recursively
                        </label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success" id="startImportBtn" disabled>
                    <i class="bi bi-upload"></i> Start Import
                </button>

                <button type="button" class="btn btn-danger" id="cancelImportBtn" style="display:none;">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
            </div>

            <!-- Progress Container -->
            <div class="progress-container" id="progressContainer">
                <div class="progress mb-2" style="height: 30px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                         id="importProgress" role="progressbar" style="width: 0%">
                        0%
                    </div>
                </div>
                <div class="text-light small">
                    <span id="progressText">Preparing...</span>
                </div>
            </div>

            <!-- Log Viewer -->
            <div id="logContainer" style="display:none;" class="mt-3">
                <h6 class="text-white">Import Log</h6>
                <div class="log-viewer" id="logViewer">
                    <div class="log-entry">Ready to import...</div>
                </div>
            </div>

            <!-- Scan Results -->
            <div id="scanResults" style="display:none;" class="mt-3">
                <h6 class="text-white">Scan Results</h6>
                <div class="file-browser" id="fileBrowser"></div>
            </div>
        </div>
    </div>

    <!-- Server Directory Browser Modal -->
    <div class="modal fade" id="dirBrowserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Select Server Directory</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text bg-secondary text-light border-secondary">Current:</span>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="browserCurrentPath" readonly>
                            <button class="btn btn-outline-light" type="button" id="browserUpBtn">
                                <i class="bi bi-arrow-up"></i> Up
                            </button>
                        </div>
                    </div>
                    <div class="list-group bg-dark border-secondary" id="browserList" style="max-height: 400px; overflow-y: auto;">
                        <div class="text-center p-3"><span class="spinner-border spinner-border-sm"></span> Loading...</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="browserSelectBtn">Select This Folder</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= BASE_PATH ?>/assets/js/electron-input-fix.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]').content;
        
        // ===== MULTI-ACCOUNT BACKUP MANAGEMENT =====
        
        let accountModal;
        let scheduleModal;
        let currentEditingCredentials = null;

        document.addEventListener('DOMContentLoaded', () => {
            accountModal = new bootstrap.Modal(document.getElementById('accountModal'));
            scheduleModal = new bootstrap.Modal(document.getElementById('scheduleModal'));
            loadBackupAccounts();
            loadNextBackupTime();
            loadScheduleConfig();
            
            // Add Account button
            document.getElementById('addAccountBtn').addEventListener('click', () => {
                console.log('Add Account button clicked');
                document.getElementById('accountModalTitle').textContent = 'Add Backup Account';
                document.getElementById('editAccountId').value = '';
                document.getElementById('accountName').value = '';
                document.getElementById('backupProvider').value = 'dropbox'; // Default to Dropbox
                document.getElementById('accountFolderName').value = '/DICOM_Backups';
                document.getElementById('accountIsActive').checked = true;
                document.getElementById('dropboxAccessToken').value = '';
                document.getElementById('accountCredentialsFile').value = '';
                
                // Show Dropbox fields, hide Google Drive fields
                document.getElementById('dropboxFields').style.display = 'block';
                document.getElementById('googleDriveFields').style.display = 'none';
                
                currentEditingCredentials = null;
                accountModal.show();
            });

            // Server Directory Browser
            const dirBrowserModal = new bootstrap.Modal(document.getElementById('dirBrowserModal'));
            let currentBrowserPath = '';

            document.getElementById('browseDirBtn').addEventListener('click', () => {
                loadServerDirs(currentBrowserPath || '');
                dirBrowserModal.show();
            });

            document.getElementById('browserUpBtn').addEventListener('click', () => {
                // Go up one level is handled by the API returning '..' entry, 
                // but we can also calculate it here if needed.
                // For now, we rely on the list having '..'
            });

            document.getElementById('browserSelectBtn').addEventListener('click', () => {
                document.getElementById('dicomDirectoryPath').value = document.getElementById('browserCurrentPath').value;
                dirBrowserModal.hide();
            });

            async function loadServerDirs(path) {
                const list = document.getElementById('browserList');
                list.innerHTML = '<div class="text-center p-3"><span class="spinner-border spinner-border-sm"></span> Loading...</div>';
                
                try {
                    const response = await fetch(`${basePath}/api/hospital-config/list-server-dirs.php?path=${encodeURIComponent(path)}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        document.getElementById('browserCurrentPath').value = data.current_path;
                        currentBrowserPath = data.current_path;
                        
                        if (data.directories.length === 0) {
                            list.innerHTML = '<div class="text-center p-3 text-muted">No directories found</div>';
                            return;
                        }
                        
                        list.innerHTML = data.directories.map(dir => `
                            <button type="button" class="list-group-item list-group-item-action bg-dark text-light border-secondary d-flex align-items-center" 
                                onclick="loadServerDirs('${dir.path.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}')">
                                <i class="bi ${dir.type === 'parent' ? 'bi-arrow-return-left' : 'bi-folder-fill'} me-2 text-warning"></i>
                                ${dir.name}
                            </button>
                        `).join('');
                        
                        // Make global function for the onclick
                        window.loadServerDirs = loadServerDirs;
                        
                    } else {
                        list.innerHTML = `<div class="text-danger p-3">Error: ${data.error}</div>`;
                    }
                } catch (error) {
                    list.innerHTML = `<div class="text-danger p-3">Error: ${error.message}</div>`;
                }
            }

            // Configure Schedule button  
            document.getElementById('viewScheduleBtn').addEventListener('click', () => {
                loadScheduleConfig();
                scheduleModal.show();
            });

            // Provider selection toggle
            document.getElementById('backupProvider').addEventListener('change', function() {
                const provider = this.value;
                const dropboxFields = document.getElementById('dropboxFields');
                const googleDriveFields = document.getElementById('googleDriveFields');
                const folderHint = document.getElementById('folderHint');
                
                if (provider === 'dropbox') {
                    dropboxFields.style.display = 'block';
                    googleDriveFields.style.display = 'none';
                    folderHint.textContent = 'Folder path in Dropbox (created automatically)';
                    document.getElementById('accountFolderName').value = '/DICOM_Backups';
                } else {
                    dropboxFields.style.display = 'none';
                    googleDriveFields.style.display = 'block';
                    folderHint.textContent = 'Create this folder in your Google Drive';
                    document.getElementById('accountFolderName').value = 'DICOM_Viewer_Backups';
                }
            });

            // Save schedule configuration
            document.getElementById('saveScheduleBtn').addEventListener('click', async () => {
                const interval = document.getElementById('scheduleInterval').value;
                const enabled = document.getElementById('scheduleEnabled').checked ? 1 : 0;
                
                const btn = document.getElementById('saveScheduleBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
                
                try {
                    const response = await fetch(`${basePath}/api/backup/update-schedule.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            interval_hours: interval,
                            enabled: enabled 
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        scheduleModal.hide();
                        loadNextBackupTime();
                        
                        const statusDiv = document.getElementById('backupAllStatus');
                        statusDiv.style.display = 'block';
                        statusDiv.className = 'alert alert-success';
                        statusDiv.innerHTML = `
                            <i class="bi bi-check-circle-fill"></i> Schedule updated! 
                            Backups will run every ${interval} hour(s).
                        `;
                        setTimeout(() => { statusDiv.style.display = 'none'; }, 5000);
                    } else {
                        alert('Error: ' + data.error);
                    }
                } catch (error) {
                    alert('Error saving schedule: ' + error.message);
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = 'Save Schedule';
                }
            });

            // Directory Scan
            document.getElementById('scanDirectoryBtn').addEventListener('click', async () => {
                const directory = document.getElementById('dicomDirectoryPath').value;
                
                if (!directory || directory.includes('[') || directory.includes('selected')) {
                    alert('Please enter a valid directory path');
                    return;
                }
                
                const btn = document.getElementById('scanDirectoryBtn');
                const statusDiv = document.getElementById('logContainer');
                
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Scanning...';
                statusDiv.style.display = 'block';
                document.getElementById('progressContainer').style.display = 'block';
                
                addLog('Scanning directory: ' + directory);
                
                try {
                    const response = await fetch(`${basePath}/api/hospital-config/scan-directory.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            directory: directory,
                            recursive: document.getElementById('recursiveScan').checked
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        addLog(`Found ${data.count || 0} DICOM files`);
                        displayScanResults(data.files || []);
                        document.getElementById('startImportBtn').disabled = false;
                    } else {
                        addLog('Error: ' + (data.error || 'Scan failed'), 'error');
                        alert('Scan failed: ' + (data.error || 'Unknown error'));
                    }
                } catch (error) {
                    addLog('Error: ' + error.message, 'error');
                    alert('Error scanning directory: ' + error.message);
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-search"></i> Scan & Import';
                }
            });

            // Start Import
            document.getElementById('startImportBtn').addEventListener('click', startImport);
        });

        // Load schedule configuration
        async function loadScheduleConfig() {
            try {
                const response = await fetch(`${basePath}/api/backup/get-schedule-info.php`);
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('scheduleInterval').value = data.interval_hours || 6;
                    document.getElementById('scheduleEnabled').checked = data.schedule_enabled == 1;
                }
            } catch (error) {
                console.error('Error loading schedule:', error);
            }
        }

        // Load all backup accounts
        async function loadBackupAccounts() {
            try {
                const response = await fetch(`${basePath}/api/backup/manage-accounts.php`);
                const data = await response.json();
                
                const container = document.getElementById('accountsList');
                
                if (data.success && data.accounts.length > 0) {
                    container.innerHTML = data.accounts.map(account => {
                        // Escape strings for HTML attributes to prevent XSS and syntax errors
                        const escapedName = String(account.account_name || '').replace(/'/g, '&#39;').replace(/"/g, '&quot;');
                        return `
                        <div class="card bg-secondary mb-2">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">
                                           ${account.account_name}
                                            ${account.is_active == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}
                                        </h6>
                                        <small class="text-muted">
                                            <i class="bi bi-envelope"></i> ${account.service_account_email}<br>
                                            <i class="bi bi-folder"></i> ${account.folder_name}
                                        </small>
                                        ${account.last_backup_date ? `<br><small class="text-info"><i class="bi bi-clock"></i> Last backup: ${new Date(account.last_backup_date).toLocaleString()}</small>` : ''}
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-light" data-account-id="${account.id}" data-action="edit">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" data-account-id="${account.id}" data-account-name="${escapedName}" data-action="remove">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    }).join('');

                    // Add event listeners using event delegation to avoid inline onclick issues
                    container.querySelectorAll('[data-action="edit"]').forEach(btn => {
                        btn.addEventListener('click', function() {
                            editAccount(parseInt(this.getAttribute('data-account-id')));
                        });
                    });

                    container.querySelectorAll('[data-action="remove"]').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const id = parseInt(this.getAttribute('data-account-id'));
                            const name = this.getAttribute('data-account-name').replace(/&#39;/g, "'").replace(/&quot;/g, '"');
                            removeAccount(id, name);
                        });
                    });
                } else {
                    container.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-inbox"></i> No backup accounts configured. Click "Add Account" to get started.</div>';
                }
            } catch (error) {
                console.error('Error loading accounts:', error);
            }
        }

        // Load next backup time
        async function loadNextBackupTime() {
            try {
                const response = await fetch(`${basePath}/api/backup/get-schedule-info.php`);
                const data = await response.json();
                
                if (data.success && data.next_backup_time) {
                    const nextTime = new Date(data.next_backup_time);
                    document.getElementById('nextBackupTime').textContent = nextTime.toLocaleString();
                } else {
                    document.getElementById('nextBackupTime').textContent = 'Not scheduled';
                }
            } catch (error) {
                document.getElementById('nextBackupTime').textContent = 'Error loading';
            }
        }

        // Edit account
        window.editAccount = async function(accountId) {
            document.getElementById('accountModalTitle').textContent = 'Edit Backup Account';
            document.getElementById('editAccountId').value = accountId;
            
            try {
                const response = await fetch(`${basePath}/api/backup/manage-accounts.php`);
                const data = await response.json();
                
                const account = data.accounts.find(a => a.id == accountId);
                if (account) {
                    document.getElementById('accountName').value = account.account_name;
                    document.getElementById('accountFolderName').value = account.folder_name;
                    document.getElementById('accountIsActive').checked = account.is_active == 1;
                    document.getElementById('credentialsUploadDiv').style.display = 'none';
                    document.getElementById('setupInstructions').style.display = 'none';
                    document.getElementById('sharingReminder').style.display = 'none';
                    accountModal.show();
                }
            } catch (error) {
                alert('Error loading account: ' + error.message);
            }
        };

        // Remove account
        window.removeAccount = async function(accountId, accountName) {
            if (!confirm(`Remove backup account "${accountName}"? This will not delete existing backups.`)) {
                return;
            }
            
            try {
                const response = await fetch(`${basePath}/api/backup/manage-accounts.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: accountId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    loadBackupAccounts();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Error removing account: ' + error.message);
            }
        };

        // Save account
        document.getElementById('saveAccountBtn').addEventListener('click', async () => {
            const accountId = document.getElementById('editAccountId').value;
            const accountName = document.getElementById('accountName').value;
            const provider = document.getElementById('backupProvider').value;
            const folderName = document.getElementById('accountFolderName').value;
            const isActive = document.getElementById('accountIsActive').checked ? 1 : 0;
            
            if (!accountName) {
                alert('Please enter an account name');
                return;
            }
            
            const btn = document.getElementById('saveAccountBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
            
            try {
                let requestData = {
                    account_name: accountName,
                    backup_provider: provider,
                    folder_name: folderName,
                    is_active: isActive
                };
                
                if (!accountId) {
                    // Adding new account
                    if (provider === 'dropbox') {
                        // Dropbox - get token
                        const dropboxToken = document.getElementById('dropboxAccessToken').value;
                        if (!dropboxToken) {
                            throw new Error('Please enter your Dropbox access token');
                        }
                        requestData.dropbox_access_token = dropboxToken;
                        
                    } else {
                        // Google Drive - get JSON file
                        const credentialsFile = document.getElementById('accountCredentialsFile').files[0];
                        if (!credentialsFile) {
                            throw new Error('Please upload Google Drive credentials JSON file');
                        }
                        
                        const fileText = await credentialsFile.text();
                        const credentials = JSON.parse(fileText);
                        
                        const isServiceAccount = credentials.type === 'service_account' && credentials.client_email;
                        const isOAuth = credentials.installed || credentials.web;
                        
                        if (!isServiceAccount && !isOAuth) {
                            throw new Error('Invalid credentials file. Must be OAuth 2.0 or Service Account JSON from Google Cloud Console.');
                        }
                        
                        requestData.credentials = credentials;
                    }
                }
                
                if (accountId) {
                    requestData.id = accountId;
                }
                
                const method = accountId ? 'PUT' : 'POST';
                const response = await fetch(`${basePath}/api/backup/manage-accounts.php`, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestData)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    accountModal.hide();
                    loadBackupAccounts();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Error saving account: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'Save Account';
            }
        });

        // Backup to all accounts
        document.getElementById('backupAllAccountsBtn').addEventListener('click', async () => {
            if (!confirm('Create backup to all active accounts? This may take several minutes.')) {
                return;
            }
            
            const btn = document.getElementById('backupAllAccountsBtn');
            const progressDiv = document.getElementById('backupAllProgress');
            const statusDiv = document.getElementById('backupAllStatus');
            
            btn.disabled = true;
            progressDiv.style.display = 'block';
            statusDiv.style.display = 'none';
            
            try {
                const response = await fetch(`${basePath}/api/backup/backup-all-accounts.php`, {
                    method: 'POST'
                });
                
                const data = await response.json();
                
                progressDiv.style.display = 'none';
                statusDiv.style.display = 'block';
                
                if (data.success) {
                    const hasFailures = data.failed > 0;
                    statusDiv.className = hasFailures ? 'alert alert-warning' : 'alert alert-success';

                    let resultDetails = '';
                    if (data.results && data.results.length > 0) {
                        resultDetails = '<div class="mt-2"><strong>Details:</strong><ul class="mb-0">';
                        data.results.forEach(result => {
                            if (result.status === 'success') {
                                resultDetails += `<li class="text-success"><i class="bi bi-check-circle"></i> ${result.account}: ${result.filename}</li>`;
                            } else {
                                resultDetails += `<li class="text-danger"><i class="bi bi-x-circle"></i> ${result.account}: ${result.error}</li>`;
                            }
                        });
                        resultDetails += '</ul></div>';
                    }

                    statusDiv.innerHTML = `
                        <i class="bi ${hasFailures ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'}"></i>
                        <strong>Backups completed!</strong><br>
                        <small>
                            Successful: ${data.successful}<br>
                            Failed: ${data.failed}
                        </small>
                        ${resultDetails}
                    `;
                    loadBackupAccounts();
                    loadNextBackupTime();
                } else {
                    statusDiv.className = 'alert alert-danger';
                    statusDiv.innerHTML = `<i class="bi bi-x-circle"></i> ${data.error}`;
                }
            } catch (error) {
                progressDiv.style.display = 'none';
                statusDiv.style.display = 'block';
                statusDiv.className = 'alert alert-danger';
                statusDiv.innerHTML = `<i class="bi bi-x-circle"></i> Error: ${error.message}`;
            } finally {
                btn.disabled = false;
            }
        });


        function displayScanResults(files) {
            const browser = document.getElementById('fileBrowser');
            const results = document.getElementById('scanResults');
            
            browser.innerHTML = `
                <div class="text-light mb-2">
                    <strong>Found ${files.length} DICOM files</strong>
                </div>
                ${files.slice(0, 50).map(f => `
                    <div class="text-muted small">
                        <i class="bi bi-file-earmark-medical"></i> ${f.path || f}
                    </div>
                `).join('')}
                ${files.length > 50 ? '<div class="text-muted small">... and ' + (files.length - 50) + ' more</div>' : ''}
            `;
            results.style.display = 'block';
        }

        async function startImport() {
            const directory = document.getElementById('dicomDirectoryPath').value;
            const autoBackup = document.getElementById('autoBackup').checked;
            
            document.getElementById('progressContainer').style.display = 'block';
            document.getElementById('logContainer').style.display = 'block';
            document.getElementById('startImportBtn').disabled = true;
            document.getElementById('cancelImportBtn').style.display = 'inline-block';
            
            addLog('Starting import process...');
            
            let batchId = null;
            let progressInterval = null;
            
            try {
                // Start import (nonblocking)
                const response = await fetch(`${basePath}/api/hospital-config/import-existing-studies.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        directory: directory,
                        auto_backup: autoBackup
                    })
                });
                
                // Parse response
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Import failed');
                }
                
                batchId = data.batch_id;
                addLog(`Import batch started: ${batchId}`);
                
                // Start polling for progress
                progressInterval = setInterval(async () => {
                    try {
                        const progResp = await fetch(`${basePath}/api/hospital-config/import-progress.php?batch_id=${batchId}`);
                        const progData = await progResp.json();
                        
                        if (progData.success) {
                            updateProgress(progData.progress, progData.current, progData.total);
                            addLog(progData.message);
                            
                            if (progData.current_file) {
                                addLog(`Processing: ${progData.current_file}`, 'info');
                            }
                            
                            // Check if completed
                            if (progData.status === 'completed' || progData.progress >= 100) {
                                clearInterval(progressInterval);
                                addLog(`Import completed! ${progData.imported_count} files imported, ${progData.error_count} errors`, 'success');
                                
                                // Trigger Sync
                                addLog('Syncing with database...', 'info');
                                try {
                                    await fetch(`${basePath}/api/sync_orthanc_api.php`);
                                    addLog('Database sync completed', 'success');
                                } catch (e) {
                                    console.error('Sync failed', e);
                                }

                                document.getElementById('startImportBtn').disabled = false;
                                document.getElementById('cancelImportBtn').style.display = 'none';
                                
                                // Reload stats
                                setTimeout(() => window.location.reload(), 2000);
                            } else if (progData.status === 'error') {
                                clearInterval(progressInterval);
                                addLog('Import failed: ' + progData.message, 'error');
                                document.getElementById('startImportBtn').disabled = false;
                                document.getElementById('cancelImportBtn').style.display = 'none';
                            }
                        }
                    } catch (err) {
                        console.error('Progress check error:', err);
                    }
                }, 1000); // Check every second
                
            } catch (error) {
                if (progressInterval) clearInterval(progressInterval);
                updateProgress(0, 0, 0);
                addLog('Error: ' + error.message, 'error');
                document.getElementById('startImportBtn').disabled = false;
                document.getElementById('cancelImportBtn').style.display = 'none';
            }
        }

        function updateProgress(percent, current, total) {
            const bar = document.getElementById('importProgress');
            bar.style.width = percent + '%';
            bar.textContent = Math.round(percent) + '%';
            
            if (current && total) {
                document.getElementById('progressText').textContent = 
                    `Processing file ${current} of ${total} (${Math.round(percent)}% complete)`;
            } else {
                document.getElementById('progressText').textContent = 
                    `Processing... ${Math.round(percent)}% complete`;
            }
        }

        function addLog(message, type = 'info') {
            const viewer = document.getElementById('logViewer');
            const timestamp = new Date().toLocaleTimeString();
            const className = type === 'error' ? 'log-error' : type === 'warning' ? 'log-warning' : 'log-entry';
            viewer.innerHTML += `<div class="${className}">[${timestamp}] ${message}</div>`;
            viewer.scrollTop = viewer.scrollHeight;
        }

        // Add rotation animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes rotate {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .rotate { animation: rotate 1s linear infinite; }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>

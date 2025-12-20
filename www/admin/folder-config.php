<?php
/**
 * Folder Monitoring Configuration
 * Configure auto-folder monitoring for DICOM imports
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';

requireLogin('../pages/login.html');

if (!isAdmin()) {
    header('Location: ../pages/patients.html');
    exit;
}

$userName = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Folder Monitoring - DICOM Viewer</title>
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
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.4);
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
        .path-item {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .path-item.active {
            border-color: #198754;
        }
        .path-item.inactive {
            opacity: 0.6;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white" href="<?= BASE_PATH ?>/patients.php">
                <i class="bi bi-heart-pulse-fill text-primary"></i>
                DICOM Viewer Pro
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= BASE_PATH ?>/pages/patients.html" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Patients
                </a>
                <span class="text-light">
                    <i class="bi bi-person-circle"></i>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white">
                <i class="bi bi-folder-symlink text-primary"></i>
                Folder Monitoring Configuration
            </h2>
        </div>

        <!-- Settings Navigation -->
        <div class="settings-nav-card">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="text-muted me-2">Settings:</span>
                <a href="<?= BASE_PATH ?>/admin/general-settings.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-hospital"></i> General
                </a>
                <a href="<?= BASE_PATH ?>/admin/folder-config.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-folder-symlink"></i> Folder Monitoring
                </a>
                <a href="<?= BASE_PATH ?>/admin/private-settings.php" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-shield-lock"></i> Private Settings
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

        <!-- Auto-Folder Monitoring -->
        <div class="config-card">
            <div class="category-header">
                <i class="bi bi-folder-symlink category-icon"></i>
                <h4 class="mb-0 text-white">Auto-Folder Monitoring (Multiple Paths)</h4>
            </div>

            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Automatically detect and sync new DICOM folders. You can monitor <strong>multiple folder paths</strong> simultaneously.
            </div>

            <!-- Add New Path -->
            <div class="mb-4">
                <label class="form-label text-light">Add Monitored Folder Path</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="monitoredFolderPath" placeholder="C:\DICOM_Data or /var/dicom">
                    <input type="text" class="form-control" id="monitoredPathName" placeholder="Name (optional)" style="max-width: 200px;">
                    <button class="btn btn-success" type="button" id="saveMonitorPathBtn">
                        <i class="bi bi-plus-circle"></i> Add Path
                    </button>
                </div>
                <small class="form-text text-muted">Add multiple folder paths to monitor for new DICOM studies</small>
            </div>

            <!-- List of Monitored Paths -->
            <div class="mb-4">
                <h6 class="text-light mb-3">Active Monitored Paths</h6>
                <div id="monitoredPathsList">
                    <div class="text-center text-muted p-3"><small>Loading...</small></div>
                </div>
            </div>

            <!-- Sync Settings -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoSyncEnabled" checked>
                        <label class="form-check-label text-light" for="autoSyncEnabled">
                            Enable Auto-Sync (checks every 30 seconds)
                        </label>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex gap-2 flex-wrap mb-4">
                <button type="button" class="btn btn-success" id="manualSyncBtn">
                    <i class="bi bi-arrow-repeat"></i> Sync Now
                </button>
                <button type="button" class="btn btn-outline-info" id="checkNewFoldersBtn">
                    <i class="bi bi-folder-check"></i> Check for New Folders
                </button>
                <button type="button" class="btn btn-primary" id="syncAllDicomBtn">
                    <i class="bi bi-database-fill-add"></i> Sync All DICOM Files
                </button>
            </div>

            <!-- Sync Status -->
            <div id="autoSyncStatus" class="mt-3" style="display:none;">
                <div class="alert alert-secondary">
                    <div class="d-flex align-items-center">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        <span id="syncStatusText">Checking for new folders...</span>
                    </div>
                </div>
            </div>

            <!-- Detected Folders List -->
            <div id="detectedFoldersContainer" style="display:none;" class="mt-3">
                <h6 class="text-light">Recently Detected Folders</h6>
                <div id="detectedFoldersList" class="border border-secondary rounded p-2" style="max-height: 200px; overflow-y: auto; background: rgba(0,0,0,0.2);">
                </div>
            </div>

            <div class="mt-3">
                <small class="text-muted">
                    <i class="bi bi-clock-history"></i> Last Check: <span id="lastSyncCheck">Never</span>
                </small>
            </div>
        </div>
    </div>

    <script src="<?= BASE_PATH ?>/assets/js/electron-input-fix.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]').content;
        let autoSyncInterval = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadMonitoredPaths();

            if (document.getElementById('autoSyncEnabled').checked) {
                startAutoSync();
            }

            document.getElementById('autoSyncEnabled').addEventListener('change', function() {
                if (this.checked) startAutoSync();
                else stopAutoSync();
            });

            document.getElementById('saveMonitorPathBtn').addEventListener('click', addMonitoredPath);
            document.getElementById('manualSyncBtn').addEventListener('click', triggerManualSync);
            document.getElementById('checkNewFoldersBtn').addEventListener('click', checkNewFolders);
            document.getElementById('syncAllDicomBtn').addEventListener('click', syncAllDicomFiles);
        });

        async function loadMonitoredPaths() {
            try {
                const response = await fetch(`${basePath}/api/hospital-config/auto-sync.php?action=get_all_paths`);
                const data = await response.json();

                const container = document.getElementById('monitoredPathsList');

                if (data.success && data.paths && data.paths.length > 0) {
                    container.innerHTML = data.paths.map(p => `
                        <div class="path-item ${p.is_active == 1 ? 'active' : 'inactive'}">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1">
                                    <strong class="text-light">${p.name || 'Unnamed'}</strong>
                                    <br><small class="text-muted"><i class="bi bi-folder"></i> ${p.path}</small>
                                    ${p.last_checked ? `<br><small class="text-info"><i class="bi bi-clock"></i> Last check: ${new Date(p.last_checked).toLocaleString()}</small>` : ''}
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm ${p.is_active == 1 ? 'btn-success' : 'btn-secondary'}" onclick="togglePath(${p.id}, ${p.is_active == 1 ? 0 : 1})" title="${p.is_active == 1 ? 'Active - Click to pause' : 'Paused - Click to activate'}">
                                        <i class="bi bi-${p.is_active == 1 ? 'check-circle' : 'pause-circle'}"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="removePath(${p.id})" title="Remove path">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    `).join('');
                } else {
                    container.innerHTML = '<div class="text-center text-muted p-3"><i class="bi bi-folder-x"></i><br><small>No paths configured. Add a folder path above.</small></div>';
                }
            } catch (error) {
                console.error('Error loading monitored paths:', error);
            }
        }

        async function addMonitoredPath() {
            const path = document.getElementById('monitoredFolderPath').value.trim();
            const name = document.getElementById('monitoredPathName').value.trim();

            if (!path) {
                alert('Please enter a folder path');
                return;
            }

            const btn = document.getElementById('saveMonitorPathBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const response = await fetch(`${basePath}/api/hospital-config/auto-sync.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add_path', path: path, name: name })
                });

                const data = await response.json();
                if (data.success) {
                    document.getElementById('monitoredFolderPath').value = '';
                    document.getElementById('monitoredPathName').value = '';
                    loadMonitoredPaths();
                    showSuccess('Path added successfully!');
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Error adding path: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-plus-circle"></i> Add Path';
            }
        }

        window.togglePath = async function(id, newState) {
            try {
                const response = await fetch(`${basePath}/api/hospital-config/auto-sync.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'toggle_path', id: id, is_active: newState })
                });

                const data = await response.json();
                if (data.success) loadMonitoredPaths();
                else alert('Error: ' + data.error);
            } catch (error) {
                alert('Error: ' + error.message);
            }
        };

        window.removePath = async function(id) {
            if (!confirm('Remove this monitored path?')) return;

            try {
                const response = await fetch(`${basePath}/api/hospital-config/auto-sync.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });

                const data = await response.json();
                if (data.success) {
                    loadMonitoredPaths();
                    showSuccess('Path removed');
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        };

        function startAutoSync() {
            if (autoSyncInterval) clearInterval(autoSyncInterval);
            autoSyncInterval = setInterval(checkAndSyncFolders, 30000);
            console.log('Auto-sync started');
        }

        function stopAutoSync() {
            if (autoSyncInterval) {
                clearInterval(autoSyncInterval);
                autoSyncInterval = null;
            }
            console.log('Auto-sync stopped');
        }

        async function checkAndSyncFolders() {
            const statusDiv = document.getElementById('autoSyncStatus');
            const statusText = document.getElementById('syncStatusText');
            const lastCheck = document.getElementById('lastSyncCheck');

            statusDiv.style.display = 'block';
            statusText.textContent = 'Checking for new folders...';

            try {
                const response = await fetch(`${basePath}/api/hospital-config/auto-sync.php?action=check_and_sync`);
                const data = await response.json();

                lastCheck.textContent = new Date().toLocaleTimeString();

                if (data.success) {
                    if (data.new_folders && data.new_folders.length > 0) {
                        statusText.textContent = `Found ${data.new_folders.length} new folder(s)! Syncing...`;
                        displayDetectedFolders(data.new_folders);
                        await triggerSyncOrthanc();
                        statusText.textContent = `Synced ${data.new_folders.length} new folder(s)`;
                    } else {
                        statusText.textContent = 'No new folders found';
                    }

                    setTimeout(() => {
                        if (!data.new_folders || data.new_folders.length === 0) {
                            statusDiv.style.display = 'none';
                        }
                    }, 3000);
                } else {
                    statusText.textContent = 'Error: ' + data.error;
                }
            } catch (error) {
                statusText.textContent = 'Error checking folders: ' + error.message;
            }
        }

        async function checkNewFolders() {
            const btn = document.getElementById('checkNewFoldersBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking...';
            btn.disabled = true;

            try {
                const response = await fetch(`${basePath}/api/hospital-config/auto-sync.php?action=check_folders`);
                const data = await response.json();

                if (data.success) {
                    if (data.folders && data.folders.length > 0) {
                        displayDetectedFolders(data.folders);
                        showSuccess(`Found ${data.folders.length} folder(s)`);
                    } else {
                        alert('No folders found in monitored path');
                    }
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        function displayDetectedFolders(folders) {
            const container = document.getElementById('detectedFoldersContainer');
            const list = document.getElementById('detectedFoldersList');

            container.style.display = 'block';
            list.innerHTML = folders.map(folder => `
                <div class="text-light small p-1 border-bottom border-secondary">
                    <i class="bi bi-folder-fill text-warning"></i> ${folder.name || folder}
                    ${folder.is_new ? '<span class="badge bg-success ms-2">NEW</span>' : ''}
                </div>
            `).join('');
        }

        async function triggerManualSync() {
            const btn = document.getElementById('manualSyncBtn');
            const originalText = btn.innerHTML;
            const statusDiv = document.getElementById('autoSyncStatus');
            const statusText = document.getElementById('syncStatusText');

            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Syncing...';
            btn.disabled = true;
            statusDiv.style.display = 'block';
            statusText.textContent = 'Checking for new folders...';

            try {
                await checkAndSyncFolders();
                statusText.textContent = 'Importing new DICOM files...';
                const importResponse = await fetch(`${basePath}/api/hospital-config/auto-sync.php?action=import_missing_files`);
                const importData = await importResponse.json();

                if (!importData.success) throw new Error(importData.error || 'Import failed');

                statusText.textContent = 'Updating database...';
                await triggerSyncOrthanc();

                const msg = `Sync Complete! Imported ${importData.imported} new files.`;
                statusText.textContent = msg;
                showSuccess(msg);
                setTimeout(() => { statusDiv.style.display = 'none'; }, 5000);
            } catch (error) {
                console.error('Sync error:', error);
                statusText.textContent = 'Error: ' + error.message;
                alert('Error during sync: ' + error.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function triggerSyncOrthanc() {
            const response = await fetch(`${basePath}/api/sync_orthanc_api.php`);
            return await response.json();
        }

        async function syncAllDicomFiles() {
            const btn = document.getElementById('syncAllDicomBtn');
            const originalText = btn.innerHTML;
            const statusDiv = document.getElementById('autoSyncStatus');
            const statusText = document.getElementById('syncStatusText');

            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Scanning...';
            btn.disabled = true;
            statusDiv.style.display = 'block';
            statusText.textContent = 'Scanning monitored paths for DICOM files...';

            try {
                const checkResponse = await fetch(`${basePath}/api/hospital-config/auto-sync.php?action=sync_missing_files`);
                const checkData = await checkResponse.json();

                if (!checkData.success) throw new Error(checkData.error || 'Failed to scan for files');

                const newFilesCount = checkData.new_files || 0;
                const totalFiles = checkData.total_files_found || 0;

                if (newFilesCount === 0) {
                    statusText.textContent = `All ${totalFiles} DICOM files are already imported.`;
                    showSuccess('All files already imported!');
                    return;
                }

                if (!confirm(`Found ${newFilesCount} new DICOM file(s) out of ${totalFiles} total.\n\nDo you want to import them now?`)) {
                    statusText.textContent = 'Import cancelled';
                    return;
                }

                statusText.textContent = `Importing ${newFilesCount} DICOM files...`;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Importing...';

                const importResponse = await fetch(`${basePath}/api/hospital-config/auto-sync.php?action=import_missing_files`);
                const importData = await importResponse.json();

                if (!importData.success) throw new Error(importData.error || 'Import failed');

                statusText.textContent = 'Syncing to database...';
                const syncResponse = await fetch(`${basePath}/api/sync_orthanc_api.php`);
                const syncData = await syncResponse.json();

                const patientsCount = syncData.stats?.total_patients || 0;
                statusText.textContent = `Import complete! ${importData.imported} files imported.`;

                showSuccess(`Imported ${importData.imported} files, ${patientsCount} patients synced`);

                if (confirm('Reload page to see new studies?')) {
                    window.location.reload();
                }
            } catch (error) {
                console.error('Sync error:', error);
                statusText.textContent = 'Error: ' + error.message;
                alert('Error during sync: ' + error.message);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
                setTimeout(() => { statusDiv.style.display = 'none'; }, 5000);
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
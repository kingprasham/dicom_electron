<?php
/**
 * License Manager - Admin Dashboard
 * View, create, and manage all license keys
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/LicenseManager.php';

requireLogin('../login.php');

// DEBUG: Log what's happening
error_log("LICENSE MANAGER ACCESS ATTEMPT");
error_log("User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("Username: " . ($_SESSION['username'] ?? 'NOT SET'));
error_log("Session Role: " . ($_SESSION['role'] ?? 'NOT SET'));
error_log("isAdmin() result: " . (isAdmin() ? 'TRUE' : 'FALSE'));

if (!isAdmin()) {
    error_log("REDIRECT: isAdmin() returned FALSE, redirecting to patients.html");
    header('Location: ../pages/patients.html');
    exit;
} else {
    error_log("ACCESS GRANTED: isAdmin() returned TRUE");
}

$userName = $_SESSION['username'] ?? 'Admin';

// Check if super admin for proper navigation
$db = getDbConnection();
$stmt = $db->prepare("SELECT is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();
$isSuperAdmin = $userInfo && $userInfo['is_super_admin'];

// Navigation links based on user type
$backLink = $isSuperAdmin ? BASE_PATH . '/super-admin/index.php' : BASE_PATH . '/pages/patients.html';
$settingsLink = $isSuperAdmin ? BASE_PATH . '/super-admin/index.php' : BASE_PATH . '/admin/general-settings.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>License Manager - DICOM Viewer</title>
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
        .card-custom {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
        }
        .license-key {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            letter-spacing: 1px;
            background: rgba(0,0,0,0.3);
            padding: 8px 12px;
            border-radius: 8px;
            display: inline-block;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
        }
        .table-dark-custom {
            --bs-table-bg: rgba(255, 255, 255, 0.02);
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, 0.1);
        }
        .modal-content {
            background: #1a1f3a;
            border: 1px solid rgba(255, 255, 255, 0.2);
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
        }
        .stats-card {
            background: linear-gradient(135deg, rgba(13, 110, 253, 0.2), rgba(13, 110, 253, 0.05));
            border: 1px solid rgba(13, 110, 253, 0.3);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .stats-card h3 { margin: 0; font-size: 2rem; }
        .stats-card small { color: #aaa; }
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
                <?php if (!$isSuperAdmin): ?>
                <a href="<?= $settingsLink ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <?php endif; ?>
                <a href="<?= $backLink ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back<?= $isSuperAdmin ? ' to Super Admin' : '' ?>
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

    <div class="container-fluid px-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white">
                <i class="bi bi-key-fill text-warning"></i>
                License Manager
            </h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createLicenseModal">
                <i class="bi bi-plus-circle"></i> Create New License
            </button>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4" id="statsRow">
            <div class="col-md-3">
                <div class="stats-card">
                    <h3 id="totalLicenses">-</h3>
                    <small>Total Licenses</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="border-color: rgba(25, 135, 84, 0.3);">
                    <h3 id="activeLicenses" class="text-success">-</h3>
                    <small>Active</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="border-color: rgba(255, 193, 7, 0.3);">
                    <h3 id="trialLicenses" class="text-warning">-</h3>
                    <small>Trials</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="border-color: rgba(220, 53, 69, 0.3);">
                    <h3 id="expiredLicenses" class="text-danger">-</h3>
                    <small>Expired/Revoked</small>
                </div>
            </div>
        </div>

        <!-- Licenses Table -->
        <div class="card-custom p-4">
            <div class="table-responsive">
                <table class="table table-dark-custom table-hover" id="licensesTable">
                    <thead>
                        <tr>
                            <th>License Key</th>
                            <th>Type</th>
                            <th>Customer</th>
                            <th>Activations</th>
                            <th>Valid Until</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="7" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create License Modal -->
    <div class="modal fade" id="createLicenseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white">
                        <i class="bi bi-plus-circle text-primary"></i> Create New License
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createLicenseForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Type</label>
                                <select class="form-select" name="license_type" id="licenseTypeSelect" onchange="toggleCustomDuration()" required>
                                    <option value="trial_15">Trial - 15 Days</option>
                                    <option value="trial_30">Trial - 1 Month</option>
                                    <option value="trial_90">Trial - 3 Months</option>
                                    <option value="full">Full License - 1 Year</option>
                                    <option value="enterprise">Enterprise - Perpetual</option>
                                    <option value="custom">Custom Duration</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3" id="customDurationDiv" style="display: none;">
                                <label class="form-label">Custom Duration (Days)</label>
                                <input type="number" class="form-control" name="custom_days" id="customDaysInput" min="1" max="3650" placeholder="e.g. 180">
                            </div>
                            <div class="col-md-6 mb-3" id="maxActivationsDiv">
                                <label class="form-label">Max Activations</label>
                                <input type="number" class="form-control" name="max_activations" value="5" min="1" max="100">
                                <small class="text-muted">Number of computers that can use this key</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name</label>
                                <input type="text" class="form-control" name="customer_name" placeholder="Dr. John Smith">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Hospital/Clinic</label>
                                <input type="text" class="form-control" name="customer_hospital" placeholder="City Hospital">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="customer_email" placeholder="doctor@hospital.com">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="customer_phone" placeholder="+91 98765 43210">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="Any additional notes..."></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createLicense()">
                        <i class="bi bi-key"></i> Generate License
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- License Details Modal -->
    <div class="modal fade" id="licenseDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white">
                        <i class="bi bi-info-circle text-info"></i> License Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="licenseDetailsBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Extend License Modal -->
    <div class="modal fade" id="extendLicenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white">
                        <i class="bi bi-calendar-plus text-primary"></i> Extend License
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Extend license validity for: <strong id="extendCustomerName"></strong></p>
                    <form id="extendLicenseForm">
                        <input type="hidden" id="extendLicenseId" name="license_id">
                        <div class="mb-3">
                            <label class="form-label">Extension Period</label>
                            <select class="form-select" id="extendDaysSelect" onchange="toggleCustomExtension()">
                                <option value="30">1 Month (30 days)</option>
                                <option value="90">3 Months (90 days)</option>
                                <option value="180">6 Months (180 days)</option>
                                <option value="365">1 Year (365 days)</option>
                                <option value="custom">Custom Duration</option>
                            </select>
                        </div>
                        <div class="mb-3" id="customExtensionDiv" style="display: none;">
                            <label class="form-label">Custom Days</label>
                            <input type="number" class="form-control" id="customExtensionDays" min="1" max="3650" placeholder="Enter number of days">
                        </div>
                        <div class="alert alert-info py-2">
                            <small><i class="bi bi-info-circle me-1"></i>The license will be extended from its current expiry date. If expired, it will be extended from today.</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="extendLicense()">
                        <i class="bi bi-calendar-plus"></i> Extend License
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal (shows generated key) -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header border-secondary bg-success bg-opacity-25">
                    <h5 class="modal-title text-success">
                        <i class="bi bi-check-circle-fill"></i> License Created!
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <p class="text-muted mb-3">Share this license key with your customer:</p>
                    <div class="license-key mb-3" id="generatedKey">DICOM-XXXX-XXXX-XXXX-XXXX</div>
                    <button class="btn btn-outline-primary btn-sm" onclick="copyGeneratedKey()">
                        <i class="bi bi-clipboard"></i> Copy to Clipboard
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= BASE_PATH ?>/assets/js/electron-input-fix.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]').content;
        let createModal, detailsModal, successModal, extendModal;
        let allLicenses = [];

        // Escape HTML for safe display
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', () => {
            createModal = new bootstrap.Modal(document.getElementById('createLicenseModal'));
            detailsModal = new bootstrap.Modal(document.getElementById('licenseDetailsModal'));
            successModal = new bootstrap.Modal(document.getElementById('successModal'));
            extendModal = new bootstrap.Modal(document.getElementById('extendLicenseModal'));

            loadLicenses();
        });

        async function loadLicenses() {
            try {
                const response = await fetch(`${basePath}/api/license/manage.php`);
                const data = await response.json();

                if (data.success) {
                    allLicenses = data.licenses;
                    renderLicenses(data.licenses);
                    updateStats(data.licenses);
                }
            } catch (error) {
                console.error('Error loading licenses:', error);
            }
        }

        function updateStats(licenses) {
            const total = licenses.length;
            const active = licenses.filter(l => l.status === 'active').length;
            const trials = licenses.filter(l => l.license_type.startsWith('trial')).length;
            const expired = licenses.filter(l => l.status === 'expired' || l.status === 'revoked').length;

            document.getElementById('totalLicenses').textContent = total;
            document.getElementById('activeLicenses').textContent = active;
            document.getElementById('trialLicenses').textContent = trials;
            document.getElementById('expiredLicenses').textContent = expired;
        }

        function renderLicenses(licenses) {
            const tbody = document.querySelector('#licensesTable tbody');
            
            if (licenses.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No licenses created yet</td></tr>';
                return;
            }

            tbody.innerHTML = licenses.map(license => {
                const statusClass = {
                    'active': 'bg-success',
                    'expired': 'bg-danger',
                    'revoked': 'bg-dark'
                }[license.status] || 'bg-secondary';

                const typeDisplay = {
                    'trial_15': '15-Day Trial',
                    'trial_30': '1-Month Trial',
                    'trial_90': '3-Month Trial',
                    'full': 'Full (1 Year)',
                    'enterprise': 'Enterprise'
                }[license.license_type] || license.license_type;

                const daysRemaining = license.days_remaining !== null 
                    ? (license.days_remaining > 0 ? `${license.days_remaining} days left` : 'Expired')
                    : 'Perpetual';

                return `
                    <tr>
                        <td>
                            <code class="license-key" style="font-size: 0.85rem; padding: 4px 8px;">
                                ${license.license_key_display}
                            </code>
                        </td>
                        <td>${typeDisplay}</td>
                        <td>
                            <strong>${license.customer_name || '-'}</strong>
                            ${license.customer_hospital ? `<br><small class="text-muted">${license.customer_hospital}</small>` : ''}
                        </td>
                        <td>
                            <span class="badge bg-info">${license.active_activations}/${license.max_activations}</span>
                        </td>
                        <td>
                            ${license.valid_until || 'Perpetual'}
                            <br><small class="text-muted">${daysRemaining}</small>
                        </td>
                        <td>
                            <span class="badge ${statusClass} status-badge">${license.status.toUpperCase()}</span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-info" onclick="viewDetails(${license.id})" title="View Details">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-secondary" onclick="copyKey('${license.license_key_display}')" title="Copy Key">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                                <button class="btn btn-outline-primary" onclick="showExtendModal(${license.id}, '${escapeHtml(license.customer_name || 'Unknown')}')" title="Extend License">
                                    <i class="bi bi-calendar-plus"></i>
                                </button>
                                ${license.status === 'active'
                                    ? `<button class="btn btn-outline-warning" onclick="suspendLicense(${license.id})" title="Suspend">
                                         <i class="bi bi-pause-circle"></i>
                                       </button>`
                                    : `<button class="btn btn-outline-success" onclick="reactivateLicense(${license.id})" title="Reactivate">
                                         <i class="bi bi-play-circle"></i>
                                       </button>`
                                }
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function toggleCustomDuration() {
            const select = document.getElementById('licenseTypeSelect');
            const customDiv = document.getElementById('customDurationDiv');
            const customInput = document.getElementById('customDaysInput');
            
            if (select.value === 'custom') {
                customDiv.style.display = 'block';
                customInput.required = true;
            } else {
                customDiv.style.display = 'none';
                customInput.required = false;
            }
        }

        async function createLicense() {
            const form = document.getElementById('createLicenseForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());

            try {
                const response = await fetch(`${basePath}/api/license/manage.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    createModal.hide();
                    form.reset();
                    toggleCustomDuration(); // Reset custom field visibility
                    
                    document.getElementById('generatedKey').textContent = result.license_key;
                    successModal.show();
                    
                    loadLicenses();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error creating license: ' + error.message);
            }
        }

        async function viewDetails(licenseId) {
            detailsModal.show();
            const body = document.getElementById('licenseDetailsBody');
            body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';

            try {
                const response = await fetch(`${basePath}/api/license/manage.php?id=${licenseId}`);
                const data = await response.json();

                if (data.success) {
                    const license = data.license;
                    const typeDisplay = {
                        'trial_15': '15-Day Trial',
                        'trial_30': '1-Month Trial',
                        'trial_90': '3-Month Trial',
                        'full': 'Full (1 Year)',
                        'enterprise': 'Enterprise'
                    }[license.license_type] || license.license_type;

                    body.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">License Key</h6>
                                <div class="license-key mb-3">${license.license_key_display}</div>
                                
                                <h6 class="text-muted mb-2">Type</h6>
                                <p>${typeDisplay}</p>
                                
                                <h6 class="text-muted mb-2">Valid Period</h6>
                                <p>${license.valid_from} to ${license.valid_until || 'Perpetual'}</p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">Customer Info</h6>
                                <p>
                                    <strong>${license.customer_name || '-'}</strong><br>
                                    ${license.customer_hospital || ''}<br>
                                    ${license.customer_email || ''}<br>
                                    ${license.customer_phone || ''}
                                </p>
                                
                                <h6 class="text-muted mb-2">Notes</h6>
                                <p>${license.notes || '-'}</p>
                            </div>
                        </div>
                        
                        <hr class="border-secondary my-4">
                        
                        <h5 class="text-white mb-3">
                            <i class="bi bi-pc-display"></i> Active Machines (${license.activations.length})
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-dark-custom table-sm">
                                <thead>
                                    <tr>
                                        <th>Machine Name</th>
                                        <th>OS</th>
                                        <th>IP Address</th>
                                        <th>Activated</th>
                                        <th>Last Seen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${license.activations.length === 0 
                                        ? '<tr><td colspan="5" class="text-center text-muted">No activations yet</td></tr>'
                                        : license.activations.map(a => `
                                            <tr>
                                                <td>${a.machine_name || '-'}</td>
                                                <td>${a.os_info || '-'}</td>
                                                <td>${a.ip_address || '-'}</td>
                                                <td>${a.activated_at}</td>
                                                <td>
                                                    ${a.last_heartbeat || '-'}
                                                    ${a.minutes_since_heartbeat !== null 
                                                        ? (a.minutes_since_heartbeat < 60 
                                                            ? '<span class="badge bg-success ms-1">Online</span>'
                                                            : '<span class="badge bg-secondary ms-1">Offline</span>')
                                                        : ''}
                                                </td>
                                            </tr>
                                        `).join('')
                                    }
                                </tbody>
                            </table>
                        </div>
                        
                        ${license.usage_stats.length > 0 ? `
                            <hr class="border-secondary my-4">
                            <h5 class="text-white mb-3">
                                <i class="bi bi-graph-up"></i> Usage Statistics (Last 30 Days)
                            </h5>
                            <div class="table-responsive">
                                <table class="table table-dark-custom table-sm">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Machine</th>
                                            <th>Prints</th>
                                            <th>Studies</th>
                                            <th>Reports</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${license.usage_stats.map(s => `
                                            <tr>
                                                <td>${s.stat_date}</td>
                                                <td>${s.machine_name || '-'}</td>
                                                <td>${s.total_prints}</td>
                                                <td>${s.studies_opened}</td>
                                                <td>${s.reports_created}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : ''}
                    `;
                }
            } catch (error) {
                body.innerHTML = `<div class="alert alert-danger">Error loading details: ${error.message}</div>`;
            }
        }

        async function suspendLicense(licenseId) {
            if (!confirm('Are you sure you want to suspend this license? Users will lose access immediately.')) return;

            try {
                const response = await fetch(`${basePath}/api/license/manage.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: licenseId, action: 'suspend' })
                });

                const result = await response.json();
                if (result.success) {
                    loadLicenses();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function reactivateLicense(licenseId) {
            try {
                const response = await fetch(`${basePath}/api/license/manage.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: licenseId, action: 'reactivate' })
                });

                const result = await response.json();
                if (result.success) {
                    loadLicenses();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function copyKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                // Show brief feedback
                const btn = event.target.closest('button');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check"></i>';
                setTimeout(() => { btn.innerHTML = originalHTML; }, 1000);
            });
        }

        function copyGeneratedKey() {
            const key = document.getElementById('generatedKey').textContent;
            navigator.clipboard.writeText(key).then(() => {
                const btn = event.target;
                btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
                setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy to Clipboard'; }, 2000);
            });
        }

        function showExtendModal(licenseId, customerName) {
            document.getElementById('extendLicenseId').value = licenseId;
            document.getElementById('extendCustomerName').textContent = customerName || 'Unknown';
            document.getElementById('extendDaysSelect').value = '365'; // Default to 1 year
            document.getElementById('customExtensionDiv').style.display = 'none';
            extendModal.show();
        }

        function toggleCustomExtension() {
            const select = document.getElementById('extendDaysSelect');
            const customDiv = document.getElementById('customExtensionDiv');

            if (select.value === 'custom') {
                customDiv.style.display = 'block';
            } else {
                customDiv.style.display = 'none';
            }
        }

        async function extendLicense() {
            const licenseId = document.getElementById('extendLicenseId').value;
            const daysSelect = document.getElementById('extendDaysSelect').value;

            let extensionDays;
            if (daysSelect === 'custom') {
                extensionDays = parseInt(document.getElementById('customExtensionDays').value);
                if (!extensionDays || extensionDays < 1) {
                    alert('Please enter a valid number of days');
                    return;
                }
            } else {
                extensionDays = parseInt(daysSelect);
            }

            try {
                const response = await fetch(`${basePath}/api/license/manage.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: parseInt(licenseId),
                        action: 'extend',
                        extension_days: extensionDays
                    })
                });

                const result = await response.json();

                if (result.success) {
                    extendModal.hide();
                    alert(`License extended successfully!\nNew expiry date: ${result.new_expiry}`);
                    loadLicenses();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error extending license: ' + error.message);
            }
        }
    </script>
</body>
</html>

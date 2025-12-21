<?php
/**
 * Super Admin Dashboard
 * Vendor-only portal to monitor all clients
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/LicenseManager.php';
require_once __DIR__ . '/../includes/ActivityLogger.php';

requireLogin('../login.php');

// Check if super admin
$db = getDbConnection();
$stmt = $db->prepare("SELECT is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$result || !$result['is_super_admin']) {
    header('Location: ../pages/patients.html');
    exit;
}

// Set session flag for other pages to use
$_SESSION['is_super_admin'] = true;

$licenseManager = new LicenseManager();
$licenses = $licenseManager->getAllLicenses();

// Get stats
$totalLicenses = count($licenses);
$activeLicenses = count(array_filter($licenses, fn($l) => $l['status'] === 'active'));
$totalActivations = array_sum(array_column($licenses, 'active_activations'));

// Recent activity
$recentActivity = ActivityLogger::getActivity(20);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Super Admin - DICOM Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a0a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            color: #fff;
        }
        .navbar-super {
            background: rgba(138, 43, 226, 0.2);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(138, 43, 226, 0.3);
        }
        .super-badge {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s;
        }
        .stat-card:hover {
            border-color: rgba(138, 43, 226, 0.5);
            transform: translateY(-2px);
        }
        .stat-card h2 {
            font-size: 2.5rem;
            font-weight: bold;
            background: linear-gradient(135deg, #8b5cf6, #06b6d4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .card-custom {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
        }
        .activity-item {
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .activity-icon.auth { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .activity-icon.study { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .activity-icon.print { background: rgba(249, 115, 22, 0.2); color: #f97316; }
        .activity-icon.report { background: rgba(139, 92, 246, 0.2); color: #8b5cf6; }
        .table-super {
            --bs-table-bg: transparent;
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, 0.1);
        }
        .license-key-small {
            font-family: monospace;
            font-size: 0.8rem;
            background: rgba(0, 0, 0, 0.3);
            padding: 2px 8px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-super mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white d-flex align-items-center gap-2" href="#">
                <i class="bi bi-shield-fill-check text-purple" style="color: #8b5cf6;"></i>
                <span>DICOM Viewer</span>
                <span class="super-badge">SUPER ADMIN</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <span class="text-light">
                    <i class="bi bi-person-fill-gear"></i>
                    <?= htmlspecialchars($_SESSION['username']) ?>
                </span>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <h2><?= $totalLicenses ?></h2>
                    <p class="text-muted mb-0">Total Licenses</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <h2 class="text-success"><?= $activeLicenses ?></h2>
                    <p class="text-muted mb-0">Active Licenses</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <h2 class="text-info"><?= $totalActivations ?></h2>
                    <p class="text-muted mb-0">Active Machines</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card print-stats-card" id="printStatsCard">
                    <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                        <i class="bi bi-printer-fill" style="color: #f97316; font-size: 1.5rem;"></i>
                        <h2 id="todayPrintCount" style="margin: 0;">-</h2>
                    </div>
                    <p class="text-muted mb-1">Today's Prints</p>
                    <div class="print-stats-mini mt-2" id="printStatsMini">
                        <small class="text-muted">Loading...</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Access Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <a href="hospitals.php" class="text-decoration-none">
                    <div class="stat-card" style="cursor: pointer;">
                        <i class="bi bi-hospital text-purple display-6 mb-2" style="color: #8b5cf6;"></i>
                        <h5 class="text-white">All Hospitals</h5>
                        <p class="text-muted mb-0 small">View & manage all installations</p>
                    </div>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="print-analytics.php" class="text-decoration-none">
                    <div class="stat-card" style="cursor: pointer;">
                        <i class="bi bi-printer-fill text-primary display-6 mb-2"></i>
                        <h5 class="text-white">Print Analytics</h5>
                        <p class="text-muted mb-0 small">Track prints, revenue & billing</p>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <a href="billing.php" class="text-decoration-none">
                    <div class="stat-card" style="cursor: pointer;">
                        <i class="bi bi-receipt text-success display-6 mb-2"></i>
                        <h5 class="text-white">Billing & Invoices</h5>
                        <p class="text-muted mb-0 small">Generate & manage invoices</p>
                    </div>
                </a>
            </div>

            <div class="col-md-3 mb-3">
                <a href="print-pricing.php" class="text-decoration-none">
                    <div class="stat-card" style="cursor: pointer;">
                        <i class="bi bi-currency-rupee text-info display-6 mb-2"></i>
                        <h5 class="text-white">Print Pricing</h5>
                        <p class="text-muted mb-0 small">Configure prices per paper size</p>
                    </div>
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Clients List -->
            <div class="col-lg-8 mb-4">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0"><i class="bi bi-people-fill text-info me-2"></i>All Clients</h5>
                        <a href="<?= BASE_PATH ?>/admin/license-manager.php" class="btn btn-sm btn-outline-light">
                            <i class="bi bi-key"></i> Manage Licenses
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-super table-hover">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>License</th>
                                    <th>Type</th>
                                    <th>Machines</th>
                                    <th>Status</th>
                                    <th>Expires</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($licenses)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">No licenses created yet</td></tr>
                                <?php else: ?>
                                    <?php foreach ($licenses as $license): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($license['customer_name'] ?: 'Unknown') ?></strong>
                                                <?php if ($license['customer_hospital']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($license['customer_hospital']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="license-key-small"><?= htmlspecialchars($license['license_key_display']) ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $types = [
                                                    'trial_15' => '15 Days',
                                                    'trial_30' => '1 Month',
                                                    'trial_90' => '3 Months',
                                                    'full' => 'Full',
                                                    'enterprise' => 'Enterprise'
                                                ];
                                                echo $types[$license['license_type']] ?? $license['license_type'];
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $license['active_activations'] ?>/<?= $license['max_activations'] ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                $statusClasses = ['active' => 'success', 'expired' => 'danger', 'revoked' => 'dark'];
                                                ?>
                                                <span class="badge bg-<?= $statusClasses[$license['status']] ?? 'secondary' ?>">
                                                    <?= strtoupper($license['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($license['valid_until']): ?>
                                                    <?= date('M j, Y', strtotime($license['valid_until'])) ?>
                                                    <?php if ($license['days_remaining'] > 0 && $license['days_remaining'] <= 7): ?>
                                                        <br><small class="text-warning"><?= $license['days_remaining'] ?> days left</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Perpetual</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 2FA Settings for Private Settings -->
            <div class="col-lg-4 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-shield-lock text-warning me-2"></i>2FA Protection</h5>
                    <p class="text-muted small">Protect private settings with 2FA. Only you will have the authenticator code.</p>
                    
                    <div id="2faStatus" class="mb-3">
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <small class="ms-2">Loading status...</small>
                        </div>
                    </div>
                    
                    <!-- QR Code Display (hidden initially) -->
                    <div id="2faQrSection" style="display: none;" class="text-center mb-3">
                        <img id="2faQrCode" src="" alt="QR Code" style="max-width: 200px; background: #fff; padding: 8px; border-radius: 8px;">
                        <p class="small text-muted mt-2">Scan with Google Authenticator or similar app</p>
                        <div class="input-group input-group-sm mb-2">
                            <span class="input-group-text">Code</span>
                            <input type="text" class="form-control" id="2faVerifyCode" placeholder="Enter 6-digit code">
                            <button class="btn btn-success" onclick="enable2FA()">Verify & Enable</button>
                        </div>
                    </div>
                    
                    <!-- Disable Section (hidden initially) -->
                    <div id="2faDisableSection" style="display: none;" class="mb-3">
                        <div class="alert alert-success py-2">
                            <i class="bi bi-check-circle me-1"></i>2FA is enabled for private settings
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Code</span>
                            <input type="text" class="form-control" id="2faDisableCode" placeholder="Enter code to disable">
                            <button class="btn btn-outline-danger" onclick="disable2FA()">Disable</button>
                        </div>
                    </div>
                    
                    <!-- Setup Button -->
                    <div id="2faSetupBtn" style="display: none;">
                        <button class="btn btn-primary w-100" onclick="setup2FA()">
                            <i class="bi bi-qr-code me-2"></i>Setup 2FA
                        </button>
                    </div>
                    
                    <div id="2faMessage" class="small mt-2"></div>
                </div>
            </div>

            <!-- Activity Feed -->
            <div class="col-lg-4 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-activity text-success me-2"></i>Recent Activity</h5>
                    <div class="activity-feed" style="max-height: 500px; overflow-y: auto;">
                        <?php if (empty($recentActivity)): ?>
                            <p class="text-center text-muted">No activity recorded yet</p>
                        <?php else: ?>
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?= $activity['event_category'] ?>">
                                        <?php
                                        $icons = [
                                            'auth' => 'bi-person-check',
                                            'study' => 'bi-folder2-open',
                                            'print' => 'bi-printer',
                                            'report' => 'bi-file-medical',
                                            'settings' => 'bi-gear',
                                            'system' => 'bi-hdd'
                                        ];
                                        ?>
                                        <i class="bi <?= $icons[$activity['event_category']] ?? 'bi-circle' ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="small">
                                            <strong><?= htmlspecialchars($activity['user_name'] ?: 'System') ?></strong>
                                            <span class="text-muted">· <?= $activity['event_type'] ?></span>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('M j, g:i A', strtotime($activity['created_at'])) ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
        
        // Initialize on load
        document.addEventListener('DOMContentLoaded', check2FAStatus);
        
        async function check2FAStatus() {
            try {
                const response = await fetch(`${basePath}/api/settings/2fa-private-settings.php`);
                const data = await response.json();
                
                document.getElementById('2faStatus').innerHTML = '';
                
                if (data.success) {
                    if (data.data.enabled) {
                        document.getElementById('2faDisableSection').style.display = 'block';
                        document.getElementById('2faSetupBtn').style.display = 'none';
                        document.getElementById('2faQrSection').style.display = 'none';
                    } else {
                        document.getElementById('2faDisableSection').style.display = 'none';
                        document.getElementById('2faSetupBtn').style.display = 'block';
                        document.getElementById('2faQrSection').style.display = 'none';
                    }
                }
            } catch (error) {
                document.getElementById('2faStatus').innerHTML = '<div class="text-danger small">Error loading status</div>';
            }
        }
        
        async function setup2FA() {
            try {
                document.getElementById('2faSetupBtn').innerHTML = '<button class="btn btn-primary w-100" disabled><span class="spinner-border spinner-border-sm"></span></button>';
                
                const response = await fetch(`${basePath}/api/settings/2fa-private-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'setup' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('2faQrCode').src = data.data.qr_url;
                    document.getElementById('2faQrSection').style.display = 'block';
                    document.getElementById('2faSetupBtn').style.display = 'none';
                    document.getElementById('2faMessage').innerHTML = '<span class="text-info">Scan the QR code, then enter the 6-digit code</span>';
                } else {
                    document.getElementById('2faMessage').innerHTML = `<span class="text-danger">${data.error}</span>`;
                    check2FAStatus();
                }
            } catch (error) {
                document.getElementById('2faMessage').innerHTML = '<span class="text-danger">Error setting up 2FA</span>';
                check2FAStatus();
            }
        }
        
        async function enable2FA() {
            const code = document.getElementById('2faVerifyCode').value.trim();
            if (!code || code.length !== 6) {
                document.getElementById('2faMessage').innerHTML = '<span class="text-danger">Enter a valid 6-digit code</span>';
                return;
            }
            
            try {
                const response = await fetch(`${basePath}/api/settings/2fa-private-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'enable', code })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('2faMessage').innerHTML = '<span class="text-success">2FA enabled successfully!</span>';
                    setTimeout(check2FAStatus, 1500);
                } else {
                    document.getElementById('2faMessage').innerHTML = `<span class="text-danger">${data.error}</span>`;
                }
            } catch (error) {
                document.getElementById('2faMessage').innerHTML = '<span class="text-danger">Error enabling 2FA</span>';
            }
        }
        
        async function disable2FA() {
            const code = document.getElementById('2faDisableCode').value.trim();
            if (!code || code.length !== 6) {
                document.getElementById('2faMessage').innerHTML = '<span class="text-danger">Enter a valid 6-digit code</span>';
                return;
            }
            
            if (!confirm('Are you sure you want to disable 2FA for private settings?')) {
                return;
            }
            
            try {
                const response = await fetch(`${basePath}/api/settings/2fa-private-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'disable', code })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('2faMessage').innerHTML = '<span class="text-success">2FA disabled</span>';
                    setTimeout(check2FAStatus, 1500);
                } else {
                    document.getElementById('2faMessage').innerHTML = `<span class="text-danger">${data.error}</span>`;
                }
            } catch (error) {
                document.getElementById('2faMessage').innerHTML = '<span class="text-danger">Error disabling 2FA</span>';
            }
        }

        // ===== PRINT STATS REAL-TIME UPDATE =====
        let lastPrintId = 0;

        async function fetchPrintStats() {
            try {
                const response = await fetch(`${basePath}/api/print/count.php?period=today`);
                const data = await response.json();

                if (data.success) {
                    updatePrintStatsDisplay(data);

                    // Check for new prints and animate
                    if (lastPrintId > 0 && data.last_print_id > lastPrintId) {
                        animatePrintCard();
                    }
                    lastPrintId = data.last_print_id;
                }
            } catch (error) {
                console.error('Failed to fetch print stats:', error);
            }
        }

        function updatePrintStatsDisplay(data) {
            const counts = data.counts || {};

            // Update main count
            const countEl = document.getElementById('todayPrintCount');
            if (countEl) {
                const oldCount = parseInt(countEl.textContent) || 0;
                const newCount = counts.total || 0;

                if (newCount !== oldCount) {
                    countEl.style.transform = 'scale(1.2)';
                    countEl.textContent = newCount;
                    setTimeout(() => countEl.style.transform = 'scale(1)', 300);
                }
            }

            // Update mini stats
            const miniEl = document.getElementById('printStatsMini');
            if (miniEl) {
                const revenue = new Intl.NumberFormat('en-IN', {
                    style: 'currency',
                    currency: 'INR',
                    maximumFractionDigits: 0
                }).format(counts.cost || 0);

                miniEl.innerHTML = `
                    <div class="d-flex justify-content-center gap-3">
                        <span class="badge bg-success bg-opacity-25 text-success">
                            <i class="bi bi-check-circle"></i> ${counts.completed || 0}
                        </span>
                        <span class="badge bg-warning bg-opacity-25 text-warning">
                            <i class="bi bi-hourglass"></i> ${counts.pending || 0}
                        </span>
                    </div>
                    <div class="mt-2">
                        <span class="badge" style="background: linear-gradient(135deg, rgba(249, 115, 22, 0.2), rgba(234, 88, 12, 0.3)); color: #f97316;">
                            <i class="bi bi-currency-rupee"></i> ${revenue}
                        </span>
                    </div>
                `;
            }

            // Update by license breakdown if available
            if (data.by_license && data.by_license.length > 0) {
                updateLicenseBreakdown(data.by_license);
            }
        }

        function updateLicenseBreakdown(byLicense) {
            // Find or create the breakdown container
            let container = document.getElementById('printsByLicense');
            if (!container) {
                const printCard = document.getElementById('printStatsCard');
                if (printCard) {
                    const breakdown = document.createElement('div');
                    breakdown.id = 'printsByLicense';
                    breakdown.className = 'mt-2 pt-2 border-top border-secondary';
                    breakdown.style.fontSize = '0.75rem';
                    printCard.appendChild(breakdown);
                    container = breakdown;
                }
            }

            if (container && byLicense.length > 0) {
                const items = byLicense.slice(0, 3).map(l => {
                    const key = l.license_key ? l.license_key.substring(0, 8) + '...' : 'Unknown';
                    return `<span class="text-muted">${key}: ${l.prints}</span>`;
                }).join(' | ');

                container.innerHTML = items;
            }
        }

        function animatePrintCard() {
            const card = document.getElementById('printStatsCard');
            if (card) {
                card.style.boxShadow = '0 0 20px rgba(34, 197, 94, 0.5)';
                card.style.borderColor = 'rgba(34, 197, 94, 0.5)';
                setTimeout(() => {
                    card.style.boxShadow = '';
                    card.style.borderColor = '';
                }, 600);
            }
        }

        // Initial fetch and start polling
        document.addEventListener('DOMContentLoaded', () => {
            fetchPrintStats();
            // Poll every 10 seconds
            setInterval(fetchPrintStats, 10000);
        });
    </script>
</body>
</html>

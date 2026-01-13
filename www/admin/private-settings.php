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

// Check if user has 2FA enabled and if super admin
$db = getDbConnection();
$stmt = $db->prepare("SELECT totp_enabled, totp_secret, is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user2FA = $result->fetch_assoc();
$stmt->close();

$has2FAEnabled = ($user2FA && $user2FA['totp_enabled'] && !empty($user2FA['totp_secret']));
$isSuperAdmin = ($user2FA && $user2FA['is_super_admin'] == 1);

// Get locations with stats for embedded location management
$locations = [];
$result = $db->query("
    SELECT l.*,
           COUNT(DISTINCT ml.activation_id) as assigned_machines,
           (SELECT COUNT(*) FROM print_logs pl WHERE pl.location_id = l.id) as total_prints,
           (SELECT SUM(total_pages) FROM print_logs pl WHERE pl.location_id = l.id) as total_pages
    FROM locations l
    LEFT JOIN machine_locations ml ON l.id = ml.location_id AND ml.is_current = 1
    GROUP BY l.id
    ORDER BY l.location_name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
}

// Get available machines for assignment
$machines = [];
$result = $db->query("
    SELECT la.id as activation_id, la.machine_id, la.machine_name, la.os_info, la.ip_address,
           ml.location_id as current_location_id,
           loc.location_name as current_location_name
    FROM license_activations la
    LEFT JOIN machine_locations ml ON la.id = ml.activation_id AND ml.is_current = 1
    LEFT JOIN locations loc ON ml.location_id = loc.id
    WHERE la.is_active = 1
    ORDER BY la.machine_name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $machines[] = $row;
    }
}
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
            <?php if ($isSuperAdmin): ?>
            <div class="d-flex gap-2">
                <a href="<?= BASE_PATH ?>/admin/setup-2fa.php" class="btn btn-outline-success">
                    <i class="bi bi-shield-check"></i> Manage 2FA
                </a>
            </div>
            <?php endif; ?>
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
                <a href="<?= BASE_PATH ?>/admin/hospital-config.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-database"></i> Backup & Import
                </a>
            </div>
        </div>

        <!-- Save Indicator -->
        <div class="save-indicator alert alert-success" id="saveIndicator">
            <i class="bi bi-check-circle-fill"></i> Saved successfully
        </div>

        <!-- DICOM Activity Monitor -->
        <div class="settings-card">
            <div class="category-header d-flex justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-activity category-icon text-success"></i>
                    <h4 class="mb-0 text-white">DICOM Activity Monitor</h4>
                    <span id="activityStatusBadge" class="badge bg-secondary ms-2">Checking...</span>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="refreshDicomActivity()" id="refreshActivityBtn">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm" onclick="logManualPing()">
                        <i class="bi bi-plus-circle"></i> Log Test Ping
                    </button>
                </div>
            </div>
            <p class="text-muted small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                Monitor incoming connections and pings from CT/MRI consoles. Activity updates automatically every 30 seconds.
            </p>

            <div id="dicomActivityList" class="border border-secondary rounded p-3" style="max-height: 300px; overflow-y: auto; background: rgba(0,0,0,0.2);">
                <div class="text-center text-muted py-3">
                    <i class="bi bi-hourglass-split me-2"></i>Loading activity...
                </div>
            </div>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="4" class="text-center text-muted">No PCPNDT printers configured</td></tr>
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

        <!-- Location Management Section (Embedded) -->
        <div class="settings-card" style="border-color: rgba(13, 202, 240, 0.3);">
            <div class="category-header d-flex justify-content-between" style="border-bottom-color: rgba(13, 202, 240, 0.5);">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-geo-alt-fill category-icon text-info"></i>
                    <h4 class="mb-0 text-white">Location Management</h4>
                </div>
                <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                    <i class="bi bi-plus-lg"></i> Add Location
                </button>
            </div>
            <p class="text-muted small mb-3">Manage rooms and locations where DICOM Viewer is installed.</p>

            <!-- Locations List -->
            <div id="locationsList">
                <?php if (empty($locations)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-geo-alt display-4 text-muted"></i>
                        <p class="text-muted mt-3">No locations created yet. Add your first location!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($locations as $loc): ?>
                        <div class="p-3 mb-2 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1);">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-info"><?= htmlspecialchars($loc['location_code']) ?></span>
                                        <strong><?= htmlspecialchars($loc['location_name']) ?></strong>
                                        <?php if (!$loc['is_active']): ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-3 text-muted small">
                                        <?php if ($loc['department']): ?>
                                            <span><i class="bi bi-hospital me-1"></i><?= htmlspecialchars($loc['department']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($loc['floor']): ?>
                                            <span><i class="bi bi-layers me-1"></i><?= htmlspecialchars($loc['floor']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-primary" onclick="editLocation(<?= $loc['id'] ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="assignMachine(<?= $loc['id'] ?>, '<?= htmlspecialchars($loc['location_name']) ?>')">
                                        <i class="bi bi-pc-display"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteLocation(<?= $loc['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex gap-3 mt-2">
                                <span class="badge bg-secondary"><i class="bi bi-pc-display me-1"></i><?= $loc['assigned_machines'] ?> Machines</span>
                                <span class="badge bg-secondary"><i class="bi bi-printer me-1"></i><?= number_format($loc['total_prints'] ?? 0) ?> Prints</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Location Modal -->
    <div class="modal fade" id="addLocationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Location</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addLocationForm">
                        <div class="mb-3">
                            <label class="form-label">Location Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location_code" placeholder="e.g., SONO1, XRAY1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location_name" placeholder="e.g., Sonography Room 1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" placeholder="e.g., Radiology">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Floor</label>
                                <input type="text" class="form-control" name="floor" placeholder="e.g., Ground Floor">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Building</label>
                                <input type="text" class="form-control" name="building" placeholder="e.g., Main Building">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-info" onclick="saveLocation()">
                        <i class="bi bi-check-lg me-2"></i>Save Location
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Location Modal -->
    <div class="modal fade" id="editLocationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Location</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editLocationForm">
                        <input type="hidden" name="id" id="editLocationId">
                        <div class="mb-3">
                            <label class="form-label">Location Code</label>
                            <input type="text" class="form-control" name="location_code" id="editLocationCode" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location Name</label>
                            <input type="text" class="form-control" name="location_name" id="editLocationName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" id="editDepartment">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Floor</label>
                                <input type="text" class="form-control" name="floor" id="editFloor">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Building</label>
                                <input type="text" class="form-control" name="building" id="editBuilding">
                            </div>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="is_active" id="editIsActive" value="1">
                            <label class="form-check-label" for="editIsActive">Active</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateLocation()">Update Location</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Machine Modal -->
    <div class="modal fade" id="assignMachineModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pc-display me-2"></i>Assign Machine to <span id="assignLocationName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="assignLocationId">
                    <div class="mb-3">
                        <label class="form-label">Select Machine</label>
                        <select class="form-select" id="machineSelect">
                            <?php foreach ($machines as $machine): ?>
                                <option value="<?= $machine['activation_id'] ?>">
                                    <?= htmlspecialchars($machine['machine_name'] ?: 'Unknown') ?>
                                    (<?= htmlspecialchars($machine['ip_address'] ?: 'No IP') ?>)
                                    <?php if ($machine['current_location_name']): ?>
                                        - Currently: <?= htmlspecialchars($machine['current_location_name']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-info" onclick="confirmAssignMachine()">Assign</button>
                </div>
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

    <!-- Detected Printers Modal -->
    <div class="modal fade" id="detectedPrintersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-search me-2"></i>Detected System Printers</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Select a printer from the list below to add it as a DICOM printer.</p>
                    <div id="detectedPrintersList">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Detecting printers...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- PCPNDT Printer Modal -->
    <div class="modal fade" id="pcpndtPrinterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-medical me-2 text-success"></i>Configure PCPNDT Printer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="pcpndtPrinterForm">
                        <input type="hidden" name="id" id="pcpndtPrinterId">
                        <div class="mb-3">
                            <label class="form-label">Select System Printer <span class="text-danger">*</span></label>
                            <select class="form-select" name="printer_name" id="pcpndtPrinterName" required>
                                <option value="">-- Detecting printers... --</option>
                            </select>
                            <small class="text-muted">Windows printers detected on this system</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Paper Size</label>
                            <select class="form-select" name="paper_size" id="pcpndtPaperSize">
                                <option value="A5">A5 (148 × 210 mm)</option>
                                <option value="A4">A4 (210 × 297 mm)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color Mode</label>
                            <select class="form-select" name="color_mode" id="pcpndtColorMode">
                                <option value="color">Color</option>
                                <option value="grayscale">Grayscale</option>
                            </select>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_default" id="pcpndtIsDefault">
                            <label class="form-check-label">Set as Default PCPNDT Printer</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="savePcpndtPrinter()">
                        <i class="bi bi-check-lg me-1"></i>Save PCPNDT Printer
                    </button>
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
            loadPcpndtPrinters();
            loadDicomActivity();
            startActivityAutoRefresh();
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

        let detectedPrintersModal = null;
        let pcpndtPrinterModal = null;
        let detectedPrintersCache = [];

        document.addEventListener('DOMContentLoaded', function() {
            detectedPrintersModal = new bootstrap.Modal(document.getElementById('detectedPrintersModal'));
            pcpndtPrinterModal = new bootstrap.Modal(document.getElementById('pcpndtPrinterModal'));
        });

        // ===== DICOM Activity Monitor =====
        let activityRefreshInterval = null;

        async function loadDicomActivity() {
            const listEl = document.getElementById('dicomActivityList');
            const badge = document.getElementById('activityStatusBadge');
            
            try {
                const response = await fetch(`${basePath}/api/dicom/activity.php?hours=24&limit=20`);
                const data = await response.json();

                if (data.success) {
                    // Update status badge
                    if (data.orthanc_connected) {
                        badge.className = 'badge bg-success ms-2';
                        badge.textContent = 'Connected';
                    } else {
                        badge.className = 'badge bg-warning ms-2';
                        badge.textContent = 'Orthanc Offline';
                    }

                    if (data.activities && data.activities.length > 0) {
                        listEl.innerHTML = data.activities.map(a => `
                            <div class="d-flex align-items-center mb-2 p-2 border-start border-3 ${getActivityBorderClass(a.event_type)}" style="background: rgba(255,255,255,0.02);">
                                <i class="bi ${getActivityIcon(a.event_type)} me-3 fs-5"></i>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <strong class="text-light">${a.event_type || 'Activity'}</strong>
                                        <small class="text-muted">${formatActivityTime(a.created_at)}</small>
                                    </div>
                                    <div class="text-muted small">
                                        ${a.message || ''}
                                        ${a.source_aet ? `<span class="badge bg-secondary ms-1">${a.source_aet}</span>` : ''}
                                        ${a.source_ip ? `<span class="text-info ms-1">${a.source_ip}</span>` : ''}
                                    </div>
                                </div>
                            </div>
                        `).join('');
                    } else {
                        listEl.innerHTML = `
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No recent DICOM activity in the last 24 hours.
                            </div>
                        `;
                    }
                } else {
                    badge.className = 'badge bg-danger ms-2';
                    badge.textContent = 'Error';
                    listEl.innerHTML = `<div class="alert alert-danger">Error loading activity: ${data.error}</div>`;
                }
            } catch (error) {
                badge.className = 'badge bg-danger ms-2';
                badge.textContent = 'Error';
                listEl.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            }
        }

        function getActivityIcon(eventType) {
            const type = (eventType || '').toLowerCase();
            if (type.includes('store') || type.includes('newinstance')) return 'bi-download text-success';
            if (type.includes('echo') || type.includes('ping')) return 'bi-broadcast-pin text-info';
            if (type.includes('query') || type.includes('find')) return 'bi-search text-warning';
            if (type.includes('move') || type.includes('retrieve')) return 'bi-arrow-left-right text-primary';
            if (type.includes('delete')) return 'bi-trash text-danger';
            return 'bi-activity text-muted';
        }

        function getActivityBorderClass(eventType) {
            const type = (eventType || '').toLowerCase();
            if (type.includes('store') || type.includes('newinstance')) return 'border-success';
            if (type.includes('echo') || type.includes('ping')) return 'border-info';
            if (type.includes('query') || type.includes('find')) return 'border-warning';
            return 'border-secondary';
        }

        function formatActivityTime(timestamp) {
            if (!timestamp) return '';
            const date = new Date(timestamp);
            const now = new Date();
            const diff = (now - date) / 1000; // seconds
            if (diff < 60) return 'Just now';
            if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
            if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
            return date.toLocaleString();
        }

        function refreshDicomActivity() {
            const btn = document.getElementById('refreshActivityBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            loadDicomActivity().then(() => {
                btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh';
            });
        }

        async function logManualPing() {
            try {
                const response = await fetch(`${basePath}/api/dicom/activity.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        event_type: 'manual_ping',
                        message: 'Manual test ping from admin',
                        source_aet: 'ADMIN_TEST'
                    })
                });
                const result = await response.json();
                if (result.success) {
                    showSuccess('Test ping logged!');
                    loadDicomActivity();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function startActivityAutoRefresh() {
            if (activityRefreshInterval) clearInterval(activityRefreshInterval);
            activityRefreshInterval = setInterval(loadDicomActivity, 30000); // Every 30 seconds
        }


        async function detectSystemPrinters() {
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Detecting...';

            try {
                const response = await fetch(`${basePath}/api/settings/detect-printers.php`);
                const result = await response.json();

                if (result.success && result.printers && result.printers.length > 0) {
                    detectedPrintersCache = result.printers;
                    renderDetectedPrinters(result.printers);
                    detectedPrintersModal.show();
                } else {
                    document.getElementById('detectedPrintersList').innerHTML = `
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            ${result.message || 'No printers detected on this system.'}
                        </div>
                    `;
                    detectedPrintersModal.show();
                }
            } catch (error) {
                alert('Error detecting printers: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }

        function renderDetectedPrinters(printers) {
            const listEl = document.getElementById('detectedPrintersList');
            
            const html = printers.map((p, index) => `
                <div class="d-flex align-items-center p-3 border border-secondary rounded mb-2" style="background: rgba(255,255,255,0.03);">
                    <i class="bi bi-printer-fill text-info me-3 fs-4"></i>
                    <div class="flex-grow-1">
                        <strong class="text-light">${p.name}</strong>
                        <div class="text-muted small">
                            <span class="me-3"><i class="bi bi-cpu me-1"></i>${p.driver || 'Unknown Driver'}</span>
                            <span><i class="bi bi-hdd me-1"></i>${p.port || 'Unknown Port'}</span>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="addDetectedPrinter(${index})">
                        <i class="bi bi-plus-circle me-1"></i>Add
                    </button>
                </div>
            `).join('');

            listEl.innerHTML = html;
        }

        async function addDetectedPrinter(index) {
            const printer = detectedPrintersCache[index];
            if (!printer) return;

            // Pre-fill the printer form and show the add printer modal
            document.getElementById('printerForm').reset();
            document.getElementById('printerId').value = '';
            document.getElementById('printerName').value = printer.name;
            document.getElementById('printerHost').value = 'localhost';
            document.getElementById('printerPort').value = '4110';
            document.getElementById('printerAET').value = 'DICOM_PRINT';
            document.getElementById('printerDesc').value = `Auto-detected: ${printer.driver || 'System Printer'}`;

            detectedPrintersModal.hide();
            printerModal.show();
        }

        // PCPNDT Printer Functions
        async function openPcpndtPrinterModal(existingPrinter = null) {
            // Load detected printers into the select
            const selectEl = document.getElementById('pcpndtPrinterName');
            selectEl.innerHTML = '<option value="">-- Loading printers... --</option>';

            try {
                const response = await fetch(`${basePath}/api/settings/detect-printers.php`);
                const result = await response.json();

                if (result.success && result.printers && result.printers.length > 0) {
                    selectEl.innerHTML = '<option value="">-- Select a printer --</option>' +
                        result.printers.map(p => `<option value="${p.name}">${p.name}</option>`).join('');
                } else {
                    selectEl.innerHTML = '<option value="">-- No printers detected --</option>';
                }
            } catch (error) {
                selectEl.innerHTML = '<option value="">-- Error loading printers --</option>';
            }

            // Reset or populate form
            if (existingPrinter) {
                document.getElementById('pcpndtPrinterId').value = existingPrinter.id || '';
                document.getElementById('pcpndtPrinterName').value = existingPrinter.printer_name || '';
                document.getElementById('pcpndtPaperSize').value = existingPrinter.paper_size || 'A5';
                document.getElementById('pcpndtColorMode').value = existingPrinter.color_mode || 'color';
                document.getElementById('pcpndtIsDefault').checked = existingPrinter.is_default == 1;
            } else {
                document.getElementById('pcpndtPrinterForm').reset();
                document.getElementById('pcpndtPrinterId').value = '';
            }

            pcpndtPrinterModal.show();
        }

        async function savePcpndtPrinter() {
            const printerName = document.getElementById('pcpndtPrinterName').value;
            if (!printerName) {
                alert('Please select a printer');
                return;
            }

            const data = {
                id: document.getElementById('pcpndtPrinterId').value || null,
                printer_name: printerName,
                paper_size: document.getElementById('pcpndtPaperSize').value,
                color_mode: document.getElementById('pcpndtColorMode').value,
                is_default: document.getElementById('pcpndtIsDefault').checked
            };

            try {
                const response = await fetch(`${basePath}/api/settings/pcpndt-printers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();

                if (result.success) {
                    pcpndtPrinterModal.hide();
                    loadPcpndtPrinters();
                    showSuccess('PCPNDT Printer saved successfully!');
                } else {
                    alert('Error: ' + (result.error || 'Failed to save printer'));
                }
            } catch (error) {
                alert('Error saving PCPNDT printer: ' + error.message);
            }
        }

        async function loadPcpndtPrinters() {
            const tbody = document.querySelector('#pcpndtPrintersTable tbody');
            if (!tbody) return;
            
            tbody.innerHTML = '<tr><td colspan="4" class="text-center">Loading...</td></tr>';

            try {
                const response = await fetch(`${basePath}/api/settings/pcpndt-printers.php`);
                const data = await response.json();

                if (data.success && data.printers && data.printers.length > 0) {
                    tbody.innerHTML = data.printers.map(printer => `
                        <tr>
                            <td>${printer.printer_name} ${printer.is_default == 1 ? '<span class="badge bg-primary ms-2">Default</span>' : ''}</td>
                            <td>${printer.paper_size || 'A5'}</td>
                            <td>${printer.color_mode || 'color'}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='openPcpndtPrinterModal(${JSON.stringify(printer)})'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePcpndtPrinter(${printer.id})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No PCPNDT printers configured</td></tr>';
                }
            } catch (error) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error loading printers</td></tr>';
            }
        }

        window.deletePcpndtPrinter = async function(id) {
            if (!confirm('Delete this PCPNDT printer?')) return;

            try {
                const response = await fetch(`${basePath}/api/settings/pcpndt-printers.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const result = await response.json();
                if (result.success) loadPcpndtPrinters();
                else alert('Error: ' + result.error);
            } catch (error) {
                alert('Error: ' + error.message);
            }
        };


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

        // ===== Location Management Functions =====
        let addLocationModal, editLocationModal, assignMachineModal;
        
        document.addEventListener('DOMContentLoaded', function() {
            addLocationModal = new bootstrap.Modal(document.getElementById('addLocationModal'));
            editLocationModal = new bootstrap.Modal(document.getElementById('editLocationModal'));
            assignMachineModal = new bootstrap.Modal(document.getElementById('assignMachineModal'));
        });

        async function saveLocation() {
            const form = document.getElementById('addLocationForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            
            if (!data.location_code || !data.location_name) {
                alert('Please fill in all required fields');
                return;
            }
            
            try {
                const response = await fetch(`${basePath}/api/locations/index.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                
                if (result.success) {
                    showSuccess('Location added successfully!');
                    addLocationModal.hide();
                    form.reset();
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Failed to add location'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function editLocation(id) {
            try {
                const response = await fetch(`${basePath}/api/locations/index.php?id=${id}`);
                const result = await response.json();
                
                if (result.success && result.location) {
                    const loc = result.location;
                    document.getElementById('editLocationId').value = loc.id;
                    document.getElementById('editLocationCode').value = loc.location_code || '';
                    document.getElementById('editLocationName').value = loc.location_name || '';
                    document.getElementById('editDepartment').value = loc.department || '';
                    document.getElementById('editFloor').value = loc.floor || '';
                    document.getElementById('editBuilding').value = loc.building || '';
                    document.getElementById('editIsActive').checked = loc.is_active == 1;
                    editLocationModal.show();
                } else {
                    alert('Error loading location');
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function updateLocation() {
            const form = document.getElementById('editLocationForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            data.is_active = document.getElementById('editIsActive').checked ? 1 : 0;
            
            try {
                const response = await fetch(`${basePath}/api/locations/index.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                
                if (result.success) {
                    showSuccess('Location updated!');
                    editLocationModal.hide();
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Failed to update'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function deleteLocation(id) {
            if (!confirm('Are you sure you want to delete this location?')) return;
            
            try {
                const response = await fetch(`${basePath}/api/locations/index.php?id=${id}`, {
                    method: 'DELETE'
                });
                const result = await response.json();
                
                if (result.success) {
                    showSuccess('Location deleted!');
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Failed to delete'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function assignMachine(locationId, locationName) {
            document.getElementById('assignLocationId').value = locationId;
            document.getElementById('assignLocationName').textContent = locationName;
            assignMachineModal.show();
        }

        async function confirmAssignMachine() {
            const locationId = document.getElementById('assignLocationId').value;
            const activationId = document.getElementById('machineSelect').value;
            
            try {
                const response = await fetch(`${basePath}/api/locations/assign-machine.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        location_id: locationId,
                        activation_id: activationId
                    })
                });
                const result = await response.json();
                
                if (result.success) {
                    showSuccess('Machine assigned!');
                    assignMachineModal.hide();
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Failed to assign'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }
    </script>
</body>
</html>
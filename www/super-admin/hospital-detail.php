<?php
/**
 * Super Admin - Hospital Detail
 * View detailed info about a specific hospital
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/LicenseManager.php';

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

$_SESSION['is_super_admin'] = true;

$licenseId = intval($_GET['id'] ?? 0);
if (!$licenseId) {
    header('Location: hospitals.php');
    exit;
}

// Get license details
$licenseManager = new LicenseManager();
$license = $licenseManager->getLicenseById($licenseId);

if (!$license) {
    header('Location: hospitals.php');
    exit;
}

// Get all machines for this hospital
$machines = [];
$stmt = $db->prepare("
    SELECT la.*,
           ml.location_id,
           loc.location_name,
           loc.location_code,
           (SELECT COUNT(*) FROM print_logs pl WHERE pl.activation_id = la.id) as print_count,
           (SELECT COALESCE(SUM(total_cost), 0) FROM print_logs pl WHERE pl.activation_id = la.id) as total_revenue
    FROM license_activations la
    LEFT JOIN machine_locations ml ON la.id = ml.activation_id AND ml.is_current = 1
    LEFT JOIN locations loc ON ml.location_id = loc.id
    WHERE la.license_id = ?
    ORDER BY la.machine_name
");
$stmt->bind_param("i", $licenseId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $machines[] = $row;
}
$stmt->close();

// Get all locations for this hospital
$locations = [];
$stmt = $db->prepare("
    SELECT l.*,
           COUNT(DISTINCT ml.activation_id) as machine_count,
           (SELECT COUNT(*) FROM print_logs pl WHERE pl.location_id = l.id) as print_count
    FROM locations l
    LEFT JOIN machine_locations ml ON l.id = ml.location_id AND ml.is_current = 1
    WHERE l.license_id = ?
    GROUP BY l.id
    ORDER BY l.location_name
");
$stmt->bind_param("i", $licenseId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}
$stmt->close();

// Get print stats for last 30 days
$stmt = $db->prepare("
    SELECT 
        DATE(pl.queued_at) as date,
        COUNT(*) as prints,
        SUM(total_pages) as pages,
        SUM(COALESCE(total_cost, 0)) as revenue
    FROM print_logs pl
    JOIN license_activations la ON pl.activation_id = la.id
    WHERE la.license_id = ?
    AND pl.queued_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(pl.queued_at)
    ORDER BY date DESC
    LIMIT 30
");
$stmt->bind_param("i", $licenseId);
$stmt->execute();
$dailyStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPrints = array_sum(array_column($dailyStats, 'prints'));
$totalPages = array_sum(array_column($dailyStats, 'pages'));
$totalRevenue = array_sum(array_column($dailyStats, 'revenue'));
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title><?= htmlspecialchars($license['customer_hospital'] ?: 'Hospital') ?> - Super Admin</title>
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
        .card-custom {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 25px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .stat-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
        }
        .machine-row {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        .machine-row:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .location-badge {
            background: rgba(138, 43, 226, 0.2);
            color: #a78bfa;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
        }
        .table-custom {
            --bs-table-bg: transparent;
            --bs-table-color: #fff;
        }
        .table-custom th {
            border-color: rgba(255, 255, 255, 0.1);
            color: #adb5bd;
            font-weight: 500;
        }
        .table-custom td {
            border-color: rgba(255, 255, 255, 0.05);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-super mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white d-flex align-items-center gap-2" href="index.php">
                <i class="bi bi-shield-fill-check" style="color: #8b5cf6;"></i>
                <span>DICOM Viewer</span>
                <span class="super-badge">SUPER ADMIN</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="hospitals.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left"></i> All Hospitals
                </a>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Hospital Header -->
        <div class="card-custom mb-4">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h3 class="mb-1"><?= htmlspecialchars($license['customer_hospital'] ?: $license['customer_name'] ?: 'Unnamed Hospital') ?></h3>
                    <p class="text-muted mb-2"><?= htmlspecialchars($license['customer_email']) ?></p>
                    <div class="d-flex gap-3">
                        <span class="badge bg-secondary"><?= htmlspecialchars($license['license_key']) ?></span>
                        <span class="badge <?= $license['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                            <?= ucfirst($license['status']) ?>
                        </span>
                    </div>
                </div>
                <div class="text-end">
                    <div class="text-muted small">License Expires</div>
                    <div class="h5 mb-0"><?= !empty($license['valid_until']) ? date('M d, Y', strtotime($license['valid_until'])) : 'Never' ?></div>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <h3 class="text-info"><?= count($machines) ?></h3>
                    <div class="text-muted">Machines</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <h3 class="text-warning"><?= count($locations) ?></h3>
                    <div class="text-muted">Locations</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <h3 class="text-primary"><?= number_format($totalPrints) ?></h3>
                    <div class="text-muted">Prints (30d)</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <h3 class="text-success">₹<?= number_format($totalRevenue, 0) ?></h3>
                    <div class="text-muted">Revenue (30d)</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Machines -->
            <div class="col-lg-6 mb-4">
                <div class="card-custom">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-pc-display me-2 text-info"></i>Machines</h5>
                        <span class="badge bg-info"><?= count($machines) ?></span>
                    </div>
                    
                    <?php if (empty($machines)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-pc-display display-4"></i>
                            <p class="mt-2">No machines registered yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($machines as $machine): ?>
                            <div class="machine-row">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($machine['machine_name'] ?: 'Unknown') ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($machine['ip_address'] ?: 'No IP') ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($machine['os_info'] ?: '') ?></div>
                                    </div>
                                    <div class="text-end">
                                        <?php if ($machine['location_name']): ?>
                                            <span class="location-badge">
                                                <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($machine['location_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unassigned</span>
                                        <?php endif; ?>
                                        <div class="mt-2 small">
                                            <span class="text-muted"><?= $machine['print_count'] ?> prints</span>
                                            <span class="text-success ms-2">₹<?= number_format($machine['total_revenue'], 0) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Locations -->
            <div class="col-lg-6 mb-4">
                <div class="card-custom">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-geo-alt me-2 text-warning"></i>Locations</h5>
                        <span class="badge bg-secondary"><?= count($locations) ?> total</span>
                    </div>
                    
                    <?php if (empty($locations)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-geo-alt display-4"></i>
                            <p class="mt-2">No locations created yet</p>
                            <small class="text-muted">Hospital admin will create locations from their workstation</small>
                        </div>
                    <?php else: ?>
                        <table class="table table-custom table-sm">
                            <thead>
                                <tr>
                                    <th>Location</th>
                                    <th class="text-center">Machines</th>
                                    <th class="text-center">Prints</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($locations as $loc): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold"><?= htmlspecialchars($loc['location_name']) ?></span>
                                            <span class="text-muted small ms-2"><?= htmlspecialchars($loc['location_code']) ?></span>
                                        </td>
                                        <td class="text-center"><?= $loc['machine_count'] ?></td>
                                        <td class="text-center"><?= $loc['print_count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Daily Stats Table -->
        <?php if (!empty($dailyStats)): ?>
        <div class="card-custom">
            <h5 class="mb-3"><i class="bi bi-graph-up me-2 text-primary"></i>Daily Print Stats (Last 30 Days)</h5>
            <table class="table table-custom">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-center">Prints</th>
                        <th class="text-center">Pages</th>
                        <th class="text-end">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyStats as $stat): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($stat['date'])) ?></td>
                            <td class="text-center"><?= number_format($stat['prints']) ?></td>
                            <td class="text-center"><?= number_format($stat['pages']) ?></td>
                            <td class="text-end text-success">₹<?= number_format($stat['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

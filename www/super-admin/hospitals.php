<?php
/**
 * Super Admin - Hospital List
 * View all hospitals (licenses) with their machines and print stats
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

// Get all licenses (hospitals)
$licenseManager = new LicenseManager();
$licenses = $licenseManager->getAllLicenses();

// Enhance with machine counts and print stats
foreach ($licenses as &$license) {
    // Get machine count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM license_activations WHERE license_id = ? AND is_active = 1");
    $stmt->bind_param("i", $license['id']);
    $stmt->execute();
    $license['machine_count'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    // Get location count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM locations WHERE license_id = ?");
    $stmt->bind_param("i", $license['id']);
    $stmt->execute();
    $license['location_count'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    
    // Get print stats (last 30 days)
    $stmt = $db->prepare("
        SELECT COUNT(*) as print_count, 
               COALESCE(SUM(total_cost), 0) as total_revenue
        FROM print_logs pl
        JOIN machine_locations ml ON pl.activation_id = ml.activation_id
        JOIN license_activations la ON ml.activation_id = la.id
        WHERE la.license_id = ? 
        AND pl.queued_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->bind_param("i", $license['id']);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $license['print_count'] = $stats['print_count'] ?? 0;
    $license['total_revenue'] = $stats['total_revenue'] ?? 0;
    $stmt->close();
}
unset($license);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Hospitals - Super Admin</title>
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
        .hospital-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s;
            cursor: pointer;
        }
        .hospital-card:hover {
            border-color: rgba(138, 43, 226, 0.5);
            transform: translateY(-3px);
            box-shadow: 0 10px 40px rgba(138, 43, 226, 0.2);
        }
        .hospital-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #fff;
        }
        .stat-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.08);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
        }
        .status-active { color: #22c55e; }
        .status-expired { color: #ef4444; }
        .status-suspended { color: #f59e0b; }
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
                <a href="index.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="bi bi-hospital me-2"></i>All Hospitals</h4>
                <p class="text-muted mb-0">Manage and monitor all installations</p>
            </div>
            <a href="<?= BASE_PATH ?>/admin/license-manager.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-2"></i>Add Hospital
            </a>
        </div>

        <?php if (empty($licenses)): ?>
            <div class="text-center py-5">
                <i class="bi bi-hospital display-1 text-muted"></i>
                <h5 class="mt-3 text-muted">No Hospitals Yet</h5>
                <p class="text-muted">Create a license to add your first hospital.</p>
                <a href="<?= BASE_PATH ?>/admin/license-manager.php" class="btn btn-primary mt-2">
                    <i class="bi bi-plus-lg me-2"></i>Create License
                </a>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($licenses as $license): ?>
                    <div class="col-lg-6 col-xl-4">
                        <div class="hospital-card" onclick="window.location='hospital-detail.php?id=<?= $license['id'] ?>'">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <div class="hospital-name"><?= htmlspecialchars($license['customer_hospital'] ?: $license['customer_name'] ?: 'Unnamed Hospital') ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($license['license_key']) ?></div>
                                </div>
                                <span class="badge <?php 
                                    echo $license['status'] === 'active' ? 'bg-success' : 
                                        ($license['status'] === 'expired' ? 'bg-danger' : 'bg-warning');
                                ?>">
                                    <?= ucfirst($license['status']) ?>
                                </span>
                            </div>
                            
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <div class="stat-badge">
                                    <i class="bi bi-pc-display text-info"></i>
                                    <span><?= $license['machine_count'] ?> Machines</span>
                                </div>
                                <div class="stat-badge">
                                    <i class="bi bi-geo-alt text-warning"></i>
                                    <span><?= $license['location_count'] ?> Locations</span>
                                </div>
                                <div class="stat-badge">
                                    <i class="bi bi-printer text-primary"></i>
                                    <span><?= $license['print_count'] ?> Prints (30d)</span>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted small">
                                    <i class="bi bi-calendar me-1"></i>
                                    Expires: <?= !empty($license['valid_until']) ? date('M d, Y', strtotime($license['valid_until'])) : 'Never' ?>
                                </div>
                                <div class="text-success fw-bold">
                                    ₹<?= number_format($license['total_revenue'], 0) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

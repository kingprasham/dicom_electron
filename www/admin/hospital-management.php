<?php
/**
 * Hospital Management Admin Page
 * Manage hospitals, user assignments, and role-based access control
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';

requireLogin('../pages/login.html');

// Only admin can access this page
if (!isAdmin()) {
    header('Location: ../pages/patients.html');
    exit;
}

$userName = $_SESSION['username'] ?? 'Admin';
$userId = $_SESSION['user_id'];
$mysqli = getDbConnection();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_hospital':
            try {
                $code = trim($_POST['hospital_code']);
                $name = trim($_POST['hospital_name']);
                $location = trim($_POST['location']);
                $email = trim($_POST['admin_email']);
                $phone = trim($_POST['admin_phone']);
                
                $stmt = $mysqli->prepare("
                    INSERT INTO hospitals (hospital_code, hospital_name, location, admin_email, admin_phone)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssss", $code, $name, $location, $email, $phone);
                
                if ($stmt->execute()) {
                    $hospitalId = $stmt->insert_id;
                    
                    // Auto-assign current admin as owner
                    $assignStmt = $mysqli->prepare("
                        INSERT INTO user_hospital_access (user_id, hospital_id, access_level, granted_by)
                        VALUES (?, ?, 'owner', ?)
                    ");
                    $assignStmt->bind_param("iii", $userId, $hospitalId, $userId);
                    $assignStmt->execute();
                    $assignStmt->close();
                    
                    $message = "Hospital '$name' added successfully!";
                    $messageType = 'success';
                } else {
                    throw new Exception($stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error adding hospital: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'assign_user':
            try {
                $userIdAssign = (int)$_POST['user_id'];
                $hospitalId = (int)$_POST['hospital_id'];
                $accessLevel = $_POST['access_level'];
                
                $stmt = $mysqli->prepare("
                    INSERT INTO user_hospital_access (user_id, hospital_id, access_level, granted_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), granted_by = VALUES(granted_by)
                ");
                $stmt->bind_param("iisi", $userIdAssign, $hospitalId, $accessLevel, $userId);
                
                if ($stmt->execute()) {
                    $message = "User access updated successfully!";
                    $messageType = 'success';
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error assigning user: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'generate_code':
            try {
                $hospitalId = (int)$_POST['hospital_id'];
                // Generate unique access code
                $accessCode = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $fullCode = "HOSP-" . $hospitalId . "-" . $accessCode;
                
                $stmt = $mysqli->prepare("
                    UPDATE hospitals SET hospital_code = ? WHERE id = ?
                ");
                $stmt->bind_param("si", $fullCode, $hospitalId);
                
                if ($stmt->execute()) {
                    $message = "New access code generated: <strong>$fullCode</strong>";
                    $messageType = 'success';
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error generating code: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'toggle_active':
            try {
                $hospitalId = (int)$_POST['hospital_id'];
                $mysqli->query("UPDATE hospitals SET is_active = NOT is_active WHERE id = $hospitalId");
                $message = "Hospital status updated!";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'remove_access':
            try {
                $accessId = (int)$_POST['access_id'];
                $mysqli->query("DELETE FROM user_hospital_access WHERE id = $accessId");
                $message = "Access removed successfully!";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
    }
}

// Get all hospitals
$hospitals = $mysqli->query("
    SELECT h.*, 
           COUNT(DISTINCT uha.user_id) as user_count,
           COUNT(DISTINCT p.id) as patient_count,
           COUNT(DISTINCT s.id) as study_count
    FROM hospitals h
    LEFT JOIN user_hospital_access uha ON h.id = uha.hospital_id
    LEFT JOIN cached_patients p ON h.id = p.hospital_id
    LEFT JOIN cached_studies s ON h.id = s.hospital_id
    GROUP BY h.id
    ORDER BY h.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Get all users with their roles
$users = $mysqli->query("
    SELECT id, username, full_name, email, role, is_active
    FROM users
    ORDER BY role, username
")->fetch_all(MYSQLI_ASSOC);

// Get all access mappings
$accessMappings = $mysqli->query("
    SELECT uha.*, u.username, u.full_name, u.role as user_role, h.hospital_name
    FROM user_hospital_access uha
    INNER JOIN users u ON uha.user_id = u.id
    INNER JOIN hospitals h ON uha.hospital_id = h.id
    ORDER BY h.hospital_name, uha.access_level, u.username
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Hospital Management - DICOM Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        .stats-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
        }
        .stats-card:hover {
            border-color: #0d6efd;
            transform: translateY(-2px);
        }
        .stats-card .icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        .settings-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }
        .settings-card:hover {
            border-color: #0d6efd;
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
        .table-dark-custom {
            --bs-table-bg: rgba(255, 255, 255, 0.02);
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, 0.1);
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
        .badge-owner { background: linear-gradient(135deg, #ffc107, #ff9800); }
        .badge-admin { background: linear-gradient(135deg, #0d6efd, #6610f2); }
        .badge-read_only { background: linear-gradient(135deg, #6c757d, #495057); }
        .hospital-code {
            font-family: monospace;
            background: rgba(13, 110, 253, 0.2);
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: bold;
        }
        .role-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .role-admin { background: #dc3545; }
        .role-doctor { background: #0d6efd; }
        .role-radiologist { background: #198754; }
        .role-technician { background: #6c757d; }
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
                <a href="<?= BASE_PATH ?>/admin/settings.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <a href="<?= BASE_PATH ?>/pages/patients.html" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Patients
                </a>
                <span class="text-light">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($userName) ?> (Admin)
                </span>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white">
                <i class="bi bi-hospital-fill text-primary"></i>
                Hospital Management
            </h2>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon text-primary"><i class="bi bi-hospital"></i></div>
                    <h3><?= count($hospitals) ?></h3>
                    <small class="text-muted">Total Hospitals</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon text-success"><i class="bi bi-people"></i></div>
                    <h3><?= count($users) ?></h3>
                    <small class="text-muted">Total Users</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon text-info"><i class="bi bi-link-45deg"></i></div>
                    <h3><?= count($accessMappings) ?></h3>
                    <small class="text-muted">Access Mappings</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon text-warning"><i class="bi bi-shield-check"></i></div>
                    <h3><?= count(array_filter($accessMappings, fn($a) => $a['access_level'] === 'owner')) ?></h3>
                    <small class="text-muted">Active Owners</small>
                </div>
            </div>
        </div>

        <!-- Add Hospital Section -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-plus-circle category-icon"></i>
                <h4 class="mb-0 text-white">Add New Hospital</h4>
            </div>
            
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="add_hospital">
                
                <div class="col-md-4">
                    <label class="form-label">Hospital Code</label>
                    <input type="text" class="form-control" name="hospital_code" 
                           placeholder="e.g., HOSP-MAIN-001" required
                           pattern="[A-Za-z0-9_-]+" title="No spaces allowed">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Hospital Name</label>
                    <input type="text" class="form-control" name="hospital_name" 
                           placeholder="e.g., City General Hospital" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Location</label>
                    <input type="text" class="form-control" name="location" 
                           placeholder="e.g., Downtown Location">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Admin Email</label>
                    <input type="email" class="form-control" name="admin_email" 
                           placeholder="admin@hospital.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Admin Phone</label>
                    <input type="tel" class="form-control" name="admin_phone" 
                           placeholder="+1 234 567 8900">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-circle"></i> Add Hospital
                    </button>
                </div>
            </form>
        </div>

        <!-- Hospitals List -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-building category-icon"></i>
                <h4 class="mb-0 text-white">Registered Hospitals</h4>
            </div>
            
            <div class="table-responsive">
                <table class="table table-dark-custom table-hover">
                    <thead>
                        <tr>
                            <th>Hospital</th>
                            <th>Access Code</th>
                            <th>Users</th>
                            <th>Patients</th>
                            <th>Studies</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hospitals as $hospital): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($hospital['hospital_name']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($hospital['location'] ?: 'No location set') ?></small>
                            </td>
                            <td>
                                <span class="hospital-code"><?= htmlspecialchars($hospital['hospital_code']) ?></span>
                                <form method="POST" class="d-inline ms-2">
                                    <input type="hidden" name="action" value="generate_code">
                                    <input type="hidden" name="hospital_id" value="<?= $hospital['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Regenerate Code">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </form>
                            </td>
                            <td><span class="badge bg-primary"><?= $hospital['user_count'] ?></span></td>
                            <td><span class="badge bg-info"><?= $hospital['patient_count'] ?></span></td>
                            <td><span class="badge bg-success"><?= $hospital['study_count'] ?></span></td>
                            <td>
                                <?php if ($hospital['is_active']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="hospital_id" value="<?= $hospital['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Toggle Status">
                                        <i class="bi bi-toggle-on"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($hospitals)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No hospitals registered yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- User Access Management -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-person-check category-icon"></i>
                <h4 class="mb-0 text-white">Assign User Access</h4>
            </div>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Role-Based Access Control:</strong>
                <ul class="mb-0 mt-2">
                    <li><strong>Owner:</strong> Full control - Can manage hospital, users, and all data</li>
                    <li><strong>Admin:</strong> Administrative access - Can manage users and view all data</li>
                    <li><strong>Read Only:</strong> View access - Can only view patients and studies (for Doctors/Radiologists)</li>
                </ul>
            </div>
            
            <form method="POST" class="row g-3 mb-4">
                <input type="hidden" name="action" value="assign_user">
                
                <div class="col-md-4">
                    <label class="form-label">Select User</label>
                    <select class="form-select" name="user_id" required>
                        <option value="">Choose a user...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>">
                                <?= htmlspecialchars($user['full_name'] ?: $user['username']) ?> 
                                (<?= ucfirst($user['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Select Hospital</label>
                    <select class="form-select" name="hospital_id" required>
                        <option value="">Choose a hospital...</option>
                        <?php foreach ($hospitals as $hospital): ?>
                            <option value="<?= $hospital['id'] ?>">
                                <?= htmlspecialchars($hospital['hospital_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Access Level</label>
                    <select class="form-select" name="access_level" required>
                        <option value="read_only">Read Only</option>
                        <option value="admin">Admin</option>
                        <option value="owner">Owner</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-person-plus"></i> Assign
                    </button>
                </div>
            </form>

            <!-- Current Access Mappings -->
            <h5 class="text-light mb-3"><i class="bi bi-list-ul"></i> Current Access Mappings</h5>
            <div class="table-responsive">
                <table class="table table-dark-custom table-hover">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>User Role</th>
                            <th>Hospital</th>
                            <th>Access Level</th>
                            <th>Granted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accessMappings as $mapping): ?>
                        <tr>
                            <td>
                                <i class="bi bi-person-circle me-1"></i>
                                <?= htmlspecialchars($mapping['full_name'] ?: $mapping['username']) ?>
                            </td>
                            <td>
                                <span class="role-badge role-<?= $mapping['user_role'] ?>">
                                    <?= ucfirst($mapping['user_role']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($mapping['hospital_name']) ?></td>
                            <td>
                                <span class="badge badge-<?= $mapping['access_level'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $mapping['access_level'])) ?>
                                </span>
                            </td>
                            <td><small><?= date('Y-m-d', strtotime($mapping['granted_at'])) ?></small></td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this access?')">
                                    <input type="hidden" name="action" value="remove_access">
                                    <input type="hidden" name="access_id" value="<?= $mapping['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($accessMappings)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No access mappings yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Orthanc Sync Info -->
        <div class="settings-card border-warning" style="background: rgba(255, 193, 7, 0.05);">
            <div class="category-header border-warning">
                <i class="bi bi-server category-icon text-warning"></i>
                <h4 class="mb-0 text-warning">Multi-Hospital Orthanc Sync</h4>
            </div>
            
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Important:</strong> Each hospital has its own independent Orthanc PACS server.
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h5 class="text-light"><i class="bi bi-diagram-3"></i> Architecture Options</h5>
                    <ol class="text-light">
                        <li><strong>Federated Model:</strong> Each hospital syncs to central database only (metadata)</li>
                        <li><strong>Cloud PACS:</strong> All hospitals push to a cloud Orthanc server</li>
                        <li><strong>Local Only:</strong> Each installation is completely independent</li>
                    </ol>
                </div>
                <div class="col-md-6">
                    <h5 class="text-light"><i class="bi bi-shield-check"></i> Current Setup</h5>
                    <p class="text-muted">
                        This installation uses <strong>Federated Metadata Sync</strong>:
                    </p>
                    <ul class="text-light">
                        <li>Patient/study metadata synced to central DB</li>
                        <li>DICOM images stay on local Orthanc servers</li>
                        <li>Cross-hospital viewing requires VPN/network access</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

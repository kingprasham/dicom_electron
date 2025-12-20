<?php
/**
 * User Management - Role-Based Access Control
 * Manages users with roles: Admin, Doctor, Radiologist, Technician
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
        case 'add_user':
            try {
                $username = trim($_POST['username']);
                $password = $_POST['password'];
                $fullName = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $role = $_POST['role'];
                
                // Validate role
                $validRoles = ['admin', 'doctor', 'radiologist', 'technician'];
                if (!in_array($role, $validRoles)) {
                    throw new Exception("Invalid role");
                }
                
                // Check if username exists
                $checkStmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $checkStmt->bind_param("ss", $username, $email);
                $checkStmt->execute();
                if ($checkStmt->get_result()->num_rows > 0) {
                    throw new Exception("Username or email already exists");
                }
                $checkStmt->close();
                
                // Hash password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $mysqli->prepare("
                    INSERT INTO users (username, password_hash, full_name, email, role, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt->bind_param("sssss", $username, $passwordHash, $fullName, $email, $role);
                
                if ($stmt->execute()) {
                    $message = "User '$fullName' created successfully as $role!";
                    $messageType = 'success';
                } else {
                    throw new Exception($stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error adding user: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'update_role':
            try {
                $targetUserId = (int)$_POST['user_id'];
                $newRole = $_POST['role'];
                
                // Can't change your own role
                if ($targetUserId === $userId) {
                    throw new Exception("You cannot change your own role");
                }
                
                $validRoles = ['admin', 'doctor', 'radiologist', 'technician'];
                if (!in_array($newRole, $validRoles)) {
                    throw new Exception("Invalid role");
                }
                
                $stmt = $mysqli->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->bind_param("si", $newRole, $targetUserId);
                
                if ($stmt->execute()) {
                    $message = "User role updated to $newRole!";
                    $messageType = 'success';
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'toggle_active':
            try {
                $targetUserId = (int)$_POST['user_id'];
                
                // Can't deactivate yourself
                if ($targetUserId === $userId) {
                    throw new Exception("You cannot deactivate your own account");
                }
                
                $mysqli->query("UPDATE users SET is_active = NOT is_active WHERE id = $targetUserId");
                $message = "User status updated!";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'reset_password':
            try {
                $targetUserId = (int)$_POST['user_id'];
                $newPassword = $_POST['new_password'];
                
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                
                $stmt = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $passwordHash, $targetUserId);
                
                if ($stmt->execute()) {
                    $message = "Password reset successfully!";
                    $messageType = 'success';
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'delete_user':
            try {
                $targetUserId = (int)$_POST['user_id'];
                
                // Can't delete yourself
                if ($targetUserId === $userId) {
                    throw new Exception("You cannot delete your own account");
                }
                
                $mysqli->query("DELETE FROM users WHERE id = $targetUserId");
                $message = "User deleted successfully!";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
    }
}

// Get all users (exclude super admin - they stay hidden)
$users = $mysqli->query("
    SELECT id, username, full_name, email, role, is_active, last_login, created_at
    FROM users
    WHERE is_super_admin = 0 OR is_super_admin IS NULL
    ORDER BY role, username
")->fetch_all(MYSQLI_ASSOC);

// Role definitions
$roles = [
    'admin' => [
        'label' => 'Administrator',
        'color' => 'danger',
        'icon' => 'bi-shield-check',
        'permissions' => ['All permissions - Full system access']
    ],
    'doctor' => [
        'label' => 'Doctor',
        'color' => 'primary',
        'icon' => 'bi-heart-pulse',
        'permissions' => [
            'View all patients and studies',
            'Create and edit medical reports',
            'Add prescriptions and remarks',
            'View DICOM images'
        ]
    ],
    'radiologist' => [
        'label' => 'Radiologist',
        'color' => 'success',
        'icon' => 'bi-file-medical',
        'permissions' => [
            'View all patients and studies',
            'Create and finalize reports',
            'Add findings and impressions',
            'View DICOM images'
        ]
    ],
    'technician' => [
        'label' => 'Technician',
        'color' => 'secondary',
        'icon' => 'bi-person-gear',
        'permissions' => [
            'View patient list',
            'View studies (read-only)',
            'Cannot create or edit reports'
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>User Management - DICOM Viewer</title>
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
        /* FIXED: Dropdown styling for dark theme */
        .form-control, .form-select {
            background-color: #2a2d3a !important;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff !important;
        }
        .form-control:focus, .form-select:focus {
            background-color: #343746 !important;
            border-color: #0d6efd;
            color: #fff !important;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .form-select option {
            background-color: #2a2d3a;
            color: #fff;
        }
        .role-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            height: 100%;
        }
        .role-card h6 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        .role-card ul {
            font-size: 0.85rem;
            padding-left: 20px;
            margin: 0;
        }
        .role-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0d6efd, #6610f2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
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
                <i class="bi bi-people-fill text-primary"></i>
                User Management
            </h2>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Role Overview -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-shield-lock category-icon"></i>
                <h4 class="mb-0 text-white">Role-Based Access Control (RBAC)</h4>
            </div>
            
            <div class="row g-3">
                <?php foreach ($roles as $roleKey => $roleInfo): ?>
                <div class="col-md-3">
                    <div class="role-card">
                        <h6>
                            <span class="badge bg-<?= $roleInfo['color'] ?>"><?= $roleInfo['label'] ?></span>
                        </h6>
                        <ul class="text-muted">
                            <?php foreach ($roleInfo['permissions'] as $perm): ?>
                                <li><?= $perm ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Add User Section -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-person-plus category-icon"></i>
                <h4 class="mb-0 text-white">Add New User</h4>
            </div>
            
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="add_user">
                
                <div class="col-md-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" name="username" 
                           placeholder="e.g., john.doe" required pattern="[a-zA-Z0-9._-]+">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" class="form-control" name="full_name" 
                           placeholder="e.g., Dr. John Doe" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" 
                           placeholder="john@hospital.com" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control" name="password" 
                           placeholder="••••••••" required minlength="6">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role" required>
                        <option value="">Select role...</option>
                        <option value="doctor">Doctor</option>
                        <option value="radiologist">Radiologist</option>
                        <option value="technician">Technician</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-person-plus"></i> Create User
                    </button>
                </div>
            </form>
        </div>

        <!-- Users List -->
        <div class="settings-card">
            <div class="category-header">
                <i class="bi bi-people category-icon"></i>
                <h4 class="mb-0 text-white">All Users (<?= count($users) ?>)</h4>
            </div>
            
            <div class="table-responsive">
                <table class="table table-dark-custom table-hover">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar">
                                        <?= strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <strong><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></strong>
                                        <br><small class="text-muted">@<?= htmlspecialchars($user['username']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <?php $roleInfo = $roles[$user['role']] ?? ['label' => ucfirst($user['role']), 'color' => 'secondary']; ?>
                                <span class="badge bg-<?= $roleInfo['color'] ?>">
                                    <i class="<?= $roleInfo['icon'] ?? 'bi-person' ?>"></i>
                                    <?= $roleInfo['label'] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <small><?= date('Y-m-d H:i', strtotime($user['last_login'])) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">Never</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['id'] != $userId): ?>
                                <!-- Change Role -->
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" 
                                            data-bs-toggle="dropdown" title="Change Role">
                                        <i class="bi bi-person-gear"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-dark">
                                        <?php foreach ($roles as $roleKey => $roleInfo): ?>
                                        <li>
                                            <form method="POST" class="dropdown-item-form">
                                                <input type="hidden" name="action" value="update_role">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <input type="hidden" name="role" value="<?= $roleKey ?>">
                                                <button type="submit" class="dropdown-item <?= $user['role'] === $roleKey ? 'active' : '' ?>">
                                                    <i class="<?= $roleInfo['icon'] ?>"></i> <?= $roleInfo['label'] ?>
                                                </button>
                                            </form>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                
                                <!-- Toggle Active -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Toggle Status">
                                        <i class="bi bi-toggle-<?= $user['is_active'] ? 'on' : 'off' ?>"></i>
                                    </button>
                                </form>
                                
                                <!-- Reset Password -->
                                <button class="btn btn-sm btn-outline-info" onclick="showResetPasswordModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')" title="Reset Password">
                                    <i class="bi bi-key"></i>
                                </button>
                                
                                <!-- Delete -->
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete User">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="badge bg-info">Current User</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: #1a1f3a; border: 1px solid rgba(255,255,255,0.1);">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white">
                        <i class="bi bi-key text-primary"></i>
                        Reset Password for <span id="resetUsername"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="resetUserId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-light">New Password</label>
                            <input type="password" class="form-control" name="new_password" 
                                   required minlength="6" placeholder="Enter new password">
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showResetPasswordModal(userId, username) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUsername').textContent = username;
            new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
        }
    </script>
</body>
</html>

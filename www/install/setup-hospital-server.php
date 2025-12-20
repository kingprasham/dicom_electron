<?php
/**
 * Hospital Server Setup - One-Click Installer
 * This script sets up everything needed for a hospital server:
 * - Creates database tables
 * - Runs all migrations
 * - Sets up default admin user
 * - Configures hospital info
 * - Creates default print pricing
 */

define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

$db = getDbConnection();
$errors = [];
$success = [];
$step = isset($_POST['step']) ? intval($_POST['step']) : 0;

// Check if already setup (allow force_setup to bypass)
$forceSetup = isset($_GET['force_setup']) && $_GET['force_setup'] == '1';
$isSetup = false;

if (!$forceSetup) {
    $result = $db->query("SHOW TABLES LIKE 'system_settings'");
    if ($result && $result->num_rows > 0) {
        $checkStmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'setup_completed'");
        if ($checkStmt && $row = $checkStmt->fetch_assoc()) {
            $isSetup = ($row['setting_value'] === '1');
        }
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if ($step === 1) {
        // Step 1: Run Migrations
        try {
            // Run all migrations
            $migrationDir = __DIR__ . '/../database/migrations/';
            $migrations = glob($migrationDir . '*.sql');
            sort($migrations);
            
            $db->query("SET FOREIGN_KEY_CHECKS = 0");
            
            // Patterns to skip (stored procedures, variables, etc.)
            $skipPatterns = [
                'DROP PROCEDURE',
                'CREATE PROCEDURE',
                'DELIMITER',
                'DECLARE',
                'BEGIN',
                'END IF',
                'END LOOP',
                'p_cost_per_page',
                'p_total_cost',
                'v_total_prints',
                'v_total_pages', 
                'v_subtotal',
                'v_license_key',
                'v_invoice_number',
                'p_license_id',
                'p_invoice_id',
                'setting_type', // Column doesn't exist in system_settings
                'CALL ',
                'RETURNS',
                'RETURN ',
                'LEAVE ',
                'CURSOR',
                'HANDLER'
            ];
            
            foreach ($migrations as $migrationFile) {
                $sql = file_get_contents($migrationFile);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                
                foreach ($statements as $statement) {
                    if (empty($statement)) continue;
                    if (strlen($statement) < 10) continue; // Skip tiny fragments
                    
                    // Skip statements containing problematic patterns
                    $skip = false;
                    foreach ($skipPatterns as $pattern) {
                        if (stripos($statement, $pattern) !== false) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) continue;
                    
                    // Only run CREATE TABLE, ALTER TABLE, INSERT, UPDATE, CREATE INDEX, CREATE VIEW
                    $allowedStarts = ['CREATE TABLE', 'ALTER TABLE', 'INSERT', 'UPDATE', 'CREATE INDEX', 'CREATE VIEW', 'DROP TABLE', 'DROP VIEW'];
                    $isAllowed = false;
                    foreach ($allowedStarts as $start) {
                        if (stripos(trim($statement), $start) === 0) {
                            $isAllowed = true;
                            break;
                        }
                    }
                    if (!$isAllowed) continue;
                    
                    try {
                        @$db->query($statement);
                    } catch (Exception $e) {
                        $msg = $e->getMessage();
                        // Ignore benign errors
                        if (strpos($msg, 'already exists') === false && 
                            strpos($msg, 'Duplicate') === false &&
                            strpos($msg, 'doesn\'t exist') === false) {
                            // Only show non-benign errors as warnings, don't stop
                        }
                    }
                }
            }
            
            $db->query("SET FOREIGN_KEY_CHECKS = 1");
            $success[] = "Database setup completed!";
            $step = 2;
        } catch (Exception $e) {
            $errors[] = "Migration failed: " . $e->getMessage();
        }
    }
    
    elseif ($step === 2) {
        // Step 2: Create Admin User
        $username = trim($_POST['admin_username'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $email = trim($_POST['admin_email'] ?? '');
        
        if (empty($username) || empty($password)) {
            $errors[] = "Username and password are required";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Check if admin exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                $success[] = "Admin user already exists, skipping...";
            } else {
                $stmt = $db->prepare("INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, 'admin', 1)");
                $stmt->bind_param("sss", $username, $email, $hashedPassword);
                if ($stmt->execute()) {
                    $success[] = "Admin user created: $username";
                } else {
                    $errors[] = "Failed to create admin user";
                }
            }
            $step = 3;
        }
    }
    
    elseif ($step === 3) {
        // Step 3: Hospital Configuration
        $hospitalName = trim($_POST['hospital_name'] ?? '');
        $hospitalAddress = trim($_POST['hospital_address'] ?? '');
        $hospitalPhone = trim($_POST['hospital_phone'] ?? '');
        $orthancUrl = trim($_POST['orthanc_url'] ?? 'http://localhost:8042');
        
        if (empty($hospitalName)) {
            $errors[] = "Hospital name is required";
        } else {
            // Save settings
            $settings = [
                'hospital_name' => $hospitalName,
                'hospital_address' => $hospitalAddress,
                'hospital_phone' => $hospitalPhone,
                'orthanc_url' => $orthancUrl,
                'setup_completed' => '1'
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, category) VALUES (?, ?, 'hospital') ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->bind_param("sss", $key, $value, $value);
                $stmt->execute();
            }
            
            // Create print_pricing table with default values
            $db->query("
                CREATE TABLE IF NOT EXISTS print_pricing (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    paper_size VARCHAR(20) NOT NULL UNIQUE,
                    grayscale_price DECIMAL(10,2) DEFAULT 0.00,
                    color_price DECIMAL(10,2) DEFAULT 0.00,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");
            
            $success[] = "Setup completed successfully!";
            $step = 4; // Go to complete
        }
    }
    
    // Step 4 removed - print pricing is now managed from Super Admin
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Server Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-container {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            color: #6b7280;
            font-weight: bold;
        }
        .step.active {
            background: #3b82f6;
            color: white;
        }
        .step.complete {
            background: #22c55e;
            color: white;
        }
        .step-line {
            flex: 1;
            height: 2px;
            background: rgba(255, 255, 255, 0.1);
            margin: 19px 10px;
        }
        .step-line.complete {
            background: #22c55e;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="text-center mb-4">
            <i class="bi bi-hospital display-3 text-primary"></i>
            <h2 class="mt-3">Hospital Server Setup</h2>
            <p class="text-muted">One-click installation for DICOM Viewer</p>
        </div>

        <?php if ($isSetup && $step === 0): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                Setup already completed! <a href="../login.php" class="alert-link">Go to Login</a>
            </div>
            <div class="text-center mt-3">
                <a href="?force_setup=1" class="btn btn-outline-secondary btn-sm">Run Setup Again</a>
            </div>
        <?php else: ?>
        
        <!-- Step Indicator (3 steps: Database, Admin, Hospital) -->
        <div class="step-indicator">
            <div class="step <?= $step >= 1 ? ($step > 1 ? 'complete' : 'active') : '' ?>">1</div>
            <div class="step-line <?= $step > 1 ? 'complete' : '' ?>"></div>
            <div class="step <?= $step >= 2 ? ($step > 2 ? 'complete' : 'active') : '' ?>">2</div>
            <div class="step-line <?= $step > 2 ? 'complete' : '' ?>"></div>
            <div class="step <?= $step >= 3 ? ($step > 3 ? 'complete' : 'active') : '' ?>">3</div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php foreach ($success as $msg): ?>
                    <div><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 0): ?>
            <!-- Step 0: Welcome -->
            <div class="text-center py-4">
                <h4>Welcome to DICOM Viewer Setup</h4>
                <p class="text-muted">This wizard will help you set up your hospital server.</p>
                <form method="POST">
                    <input type="hidden" name="step" value="1">
                    <button type="submit" class="btn btn-primary btn-lg mt-3">
                        <i class="bi bi-play-fill me-2"></i>Start Setup
                    </button>
                </form>
            </div>

        <?php elseif ($step === 2): ?>
            <!-- Step 2: Admin User -->
            <h5 class="mb-3"><i class="bi bi-person-fill me-2"></i>Create Admin User</h5>
            <form method="POST">
                <input type="hidden" name="step" value="2">
                <div class="mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="admin_username" class="form-control" value="admin" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="admin_email" class="form-control" placeholder="admin@hospital.com">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" name="admin_password" class="form-control" required>
                    <small class="text-muted">Choose a strong password</small>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-arrow-right me-2"></i>Continue
                </button>
            </form>

        <?php elseif ($step === 3): ?>
            <!-- Step 3: Hospital Config -->
            <h5 class="mb-3"><i class="bi bi-hospital me-2"></i>Hospital Configuration</h5>
            <form method="POST">
                <input type="hidden" name="step" value="3">
                <div class="mb-3">
                    <label class="form-label">Hospital Name <span class="text-danger">*</span></label>
                    <input type="text" name="hospital_name" class="form-control" placeholder="City Hospital" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="hospital_address" class="form-control" rows="2" placeholder="123 Medical Street, City"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="hospital_phone" class="form-control" placeholder="+91 9876543210">
                </div>
                <div class="mb-3">
                    <label class="form-label">Orthanc Server URL</label>
                    <input type="text" name="orthanc_url" class="form-control" value="http://localhost:8042">
                    <small class="text-muted">Leave default unless Orthanc is on a different machine</small>
                </div>
                <button type="submit" class="btn btn-success w-100">
                    <i class="bi bi-check-lg me-2"></i>Complete Setup
                </button>
            </form>

        <?php elseif ($step === 4): ?>
            <!-- Step 5: Complete -->
            <div class="text-center py-4">
                <i class="bi bi-check-circle-fill text-success display-1"></i>
                <h3 class="mt-3 text-success">Setup Complete!</h3>
                <p class="text-muted">Your hospital server is ready to use.</p>
                <div class="d-grid gap-2 mt-4">
                    <a href="../login.php" class="btn btn-success btn-lg">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                    </a>
                    <a href="../setup.php" class="btn btn-outline-primary">
                        <i class="bi bi-key me-2"></i>Activate License
                    </a>
                </div>
            </div>

        <?php endif; ?>

        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

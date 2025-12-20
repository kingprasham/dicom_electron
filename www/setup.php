<?php
/**
 * First-Time Setup Wizard
 * Requires: Hospital Info, Address, Users, Auto-Folder Monitoring
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/includes/config.php';

// Ensure both settings tables exist (run migration if needed)
try {
    $db = getDbConnection();

    // Create system_settings table (main app table)
    $db->query("
        CREATE TABLE IF NOT EXISTS `system_settings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(100) NOT NULL,
            `setting_value` text,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create settings table (backup/flag table)
    $db->query("
        CREATE TABLE IF NOT EXISTS `settings` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(100) NOT NULL,
            `setting_value` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Also ensure users table has required columns
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'setup_completed'");
    if (!$result || $result->num_rows == 0) {
        $db->query("ALTER TABLE `users` ADD COLUMN `setup_completed` TINYINT(1) NOT NULL DEFAULT 0");
    }
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
    if (!$result || $result->num_rows == 0) {
        $db->query("ALTER TABLE `users` ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {
    // Log but continue - table creation may fail if it exists
    error_log("Setup: Table creation notice - " . $e->getMessage());
}

// Check if setup is already complete (only if NOT forced)
$setupComplete = false;
$hospitalConfigured = false;

if (!isset($_GET['force'])) {
    try {
        $db = getDbConnection();

        // Check system_settings first (main app table)
        $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'hospital_name' AND setting_value != '' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $hospitalConfigured = true;
        }

        // Fallback to settings table
        if (!$hospitalConfigured) {
            $result = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'hospital_name' AND setting_value != '' LIMIT 1");
            if ($result && $result->num_rows > 0) {
                $hospitalConfigured = true;
            }
        }

        // Check setup_complete flag in system_settings first
        $result = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'setup_complete' AND setting_value = '1' LIMIT 1");
        if ($result && $result->num_rows > 0 && $hospitalConfigured) {
            $setupComplete = true;
        }

        // Fallback to settings table
        if (!$setupComplete && $hospitalConfigured) {
            $result = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'setup_complete' AND setting_value = '1' LIMIT 1");
            if ($result && $result->num_rows > 0) {
                $setupComplete = true;
            }
        }
    } catch (Exception $e) {
        // Tables don't exist - need setup
        $setupComplete = false;
    }
}

// If setup is complete, redirect to patients
if ($setupComplete && !isset($_GET['force'])) {
    header('Location: ' . BASE_PATH . '/pages/patients.html');
    exit;
}

// Get current user role
$userRole = $_SESSION['role'] ?? 'viewer';
$isAdmin = in_array($userRole, ['admin', 'super_admin']);

// If not admin, redirect to patients (only admins can run setup)
if (!$isAdmin) {
    header('Location: ' . BASE_PATH . '/pages/patients.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First-Time Setup - Accurate Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #1a1d2e;
            --bg-secondary: #22252f;
            --bg-tertiary: #2a2d3a;
            --accent: #00d9ff;
            --text-primary: #ffffff;
            --text-secondary: #b0b3c1;
        }

        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .setup-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
        }

        .setup-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .setup-header h1 {
            color: var(--accent);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .setup-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .step-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--bg-tertiary);
            border: 2px solid #444;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .step-item.active .step-circle {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }

        .step-item.completed .step-circle {
            background: #28a745;
            border-color: #28a745;
        }

        .step-item.completed .step-circle::after {
            content: '✓';
        }

        .step-label {
            margin-top: 8px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .step-item.active .step-label {
            color: var(--accent);
            font-weight: 600;
        }

        .setup-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 40px;
            border: 1px solid #333;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .setup-step {
            display: none;
        }

        .setup-step.active {
            display: block;
        }

        .step-title {
            color: var(--accent);
            font-size: 1.5rem;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-label {
            color: var(--text-primary);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            background: var(--bg-tertiary);
            border: 1px solid #444;
            color: var(--text-primary);
            padding: 12px 15px;
            border-radius: 8px;
        }

        .form-control:focus, .form-select:focus {
            background: var(--bg-tertiary);
            border-color: var(--accent);
            color: var(--text-primary);
            box-shadow: 0 0 0 3px rgba(0, 217, 255, 0.15);
        }

        .btn-next, .btn-prev {
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-next {
            background: linear-gradient(135deg, var(--accent) 0%, #0d6efd 100%);
            border: none;
            color: #000;
        }

        .btn-next:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 217, 255, 0.4);
        }

        .btn-prev {
            background: transparent;
            border: 1px solid #666;
            color: var(--text-secondary);
        }

        .btn-prev:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .path-list {
            max-height: 200px;
            overflow-y: auto;
            margin-top: 15px;
        }

        .path-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 15px;
            background: var(--bg-tertiary);
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .path-item .path-text {
            color: var(--text-primary);
            font-family: 'Consolas', monospace;
        }

        .path-item .btn-remove {
            color: #dc3545;
            background: transparent;
            border: none;
            cursor: pointer;
        }

        .user-card {
            background: var(--bg-tertiary);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .required-badge {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-left: 8px;
        }

        .progress-bar-setup {
            height: 6px;
            background: #333;
            border-radius: 3px;
            margin-bottom: 30px;
            overflow: hidden;
        }

        .progress-bar-setup .progress {
            height: 100%;
            background: linear-gradient(to right, var(--accent), #0d6efd);
            transition: width 0.5s ease;
        }

        .alert-validation {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid #dc3545;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            display: none;
        }

        .hospital-logo-preview {
            width: 100px;
            height: 100px;
            border-radius: 10px;
            background: var(--bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #444;
            cursor: pointer;
            overflow: hidden;
        }

        .hospital-logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1><i class="bi bi-gear-wide-connected"></i> First-Time Setup</h1>
            <p>Configure your DICOM viewer before getting started</p>
        </div>

        <div class="step-indicator">
            <div class="step-item active" data-step="1">
                <div class="step-circle">1</div>
                <div class="step-label">Hospital Info</div>
            </div>
            <div class="step-item" data-step="2">
                <div class="step-circle">2</div>
                <div class="step-label">Address</div>
            </div>
            <div class="step-item" data-step="3">
                <div class="step-circle">3</div>
                <div class="step-label">Users</div>
            </div>
            <div class="step-item" data-step="4">
                <div class="step-circle">4</div>
                <div class="step-label">Folder Monitoring</div>
            </div>
        </div>

        <div class="progress-bar-setup">
            <div class="progress" style="width: 25%;"></div>
        </div>

        <div class="setup-card">
            <!-- Step 1: Hospital Info -->
            <div class="setup-step active" data-step="1">
                <h3 class="step-title">
                    <i class="bi bi-hospital"></i>
                    Hospital Information
                    <span class="required-badge">Required</span>
                </h3>

                <div class="alert-validation" id="step1Errors"></div>

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Hospital/Clinic Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="hospitalName" placeholder="Enter hospital name" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Logo</label>
                        <div class="hospital-logo-preview" onclick="document.getElementById('hospitalLogo').click()">
                            <i class="bi bi-image fs-2 text-muted" id="logoPlaceholder"></i>
                            <img id="logoPreview" style="display: none;">
                        </div>
                        <input type="file" id="hospitalLogo" accept="image/*" style="display: none;" onchange="previewLogo(this)">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control" id="department" placeholder="e.g., Radiology Department" value="Radiology & Medical Imaging">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="hospitalPhone" placeholder="+91 9876543210">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" id="hospitalEmail" placeholder="info@hospital.com">
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button class="btn btn-next" onclick="nextStep()">
                        Continue <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 2: Address -->
            <div class="setup-step" data-step="2">
                <h3 class="step-title">
                    <i class="bi bi-geo-alt"></i>
                    Hospital Address
                    <span class="required-badge">Required</span>
                </h3>

                <div class="alert-validation" id="step2Errors"></div>

                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Address Line 1 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="addressLine1" placeholder="Street address, building name" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Address Line 2</label>
                        <input type="text" class="form-control" id="addressLine2" placeholder="Area, landmark (optional)">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">City <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="city" placeholder="City" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">State <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="state" placeholder="State" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">PIN Code</label>
                        <input type="text" class="form-control" id="pinCode" placeholder="400001">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" id="country" value="India">
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-prev" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button class="btn btn-next" onclick="nextStep()">
                        Continue <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 3: Users -->
            <div class="setup-step" data-step="3">
                <h3 class="step-title">
                    <i class="bi bi-people"></i>
                    Create Admin User
                    <span class="required-badge">Required</span>
                </h3>

                <div class="alert-validation" id="step3Errors"></div>

                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Important:</strong> Create an admin account so you can login without the license key in the future.
                </div>

                <div class="admin-user-form p-4 rounded" style="background: var(--bg-tertiary); border: 2px solid var(--accent);">
                    <h5 class="text-white mb-4"><i class="bi bi-shield-check me-2 text-success"></i>Administrator Account</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="adminUsername" placeholder="admin" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="adminPassword" placeholder="Enter secure password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="adminFullName" placeholder="Administrator">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="adminEmail" placeholder="admin@hospital.com">
                        </div>
                    </div>
                </div>

                <div id="usersList" class="mt-4">
                    <!-- Additional users will be added here -->
                </div>

                <div class="new-user-form mt-4 p-3 rounded" style="background: var(--bg-tertiary);">
                    <h6 class="text-info mb-3"><i class="bi bi-plus-circle me-2"></i>Add Additional User (Optional)</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="newUsername" placeholder="Username">
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="newFullName" placeholder="Full Name">
                        </div>
                        <div class="col-md-6">
                            <input type="email" class="form-control" id="newEmail" placeholder="Email">
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="newRole">
                                <option value="viewer">Viewer</option>
                                <option value="technician">Technician</option>
                                <option value="doctor">Doctor</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <input type="password" class="form-control" id="newPassword" placeholder="Password">
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-success w-100" onclick="addUser()">
                                <i class="bi bi-plus me-2"></i>Add User
                            </button>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-prev" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button class="btn btn-next" onclick="nextStep()">
                        Continue <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 4: Folder Monitoring -->
            <div class="setup-step" data-step="4">
                <h3 class="step-title">
                    <i class="bi bi-folder2-open"></i>
                    Auto-Folder Monitoring
                    <span class="badge bg-secondary ms-2">Optional</span>
                </h3>

                <div class="alert-validation" id="step4Errors"></div>

                <p class="text-muted mb-4">
                    Add folders that will be automatically monitored for new DICOM files. 
                    When new files appear, they will be automatically imported.
                </p>

                <div class="mb-3">
                    <label class="form-label">Add Folder Path</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="newFolderPath" placeholder="C:\DICOM\Incoming or /data/dicom/incoming">
                        <button class="btn btn-success" onclick="addFolderPath()">
                            <i class="bi bi-plus"></i> Add
                        </button>
                    </div>
                    <small class="text-muted">Enter full path to folder containing DICOM files</small>
                </div>

                <div class="path-list" id="folderPathsList">
                    <!-- Paths will be added here -->
                </div>

                <div class="mt-4 p-3 rounded" style="background: var(--bg-tertiary);">
                    <h6 class="text-info"><i class="bi bi-clock me-2"></i>Monitoring Settings</h6>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Check Interval</label>
                            <select class="form-select" id="checkInterval">
                                <option value="30">Every 30 seconds</option>
                                <option value="60" selected>Every 1 minute</option>
                                <option value="300">Every 5 minutes</option>
                                <option value="600">Every 10 minutes</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">After Import</label>
                            <select class="form-select" id="afterImport">
                                <option value="move">Move to processed folder</option>
                                <option value="delete">Delete original files</option>
                                <option value="keep">Keep in place</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-prev" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button class="btn btn-next btn-lg" onclick="completeSetup()">
                        <i class="bi bi-check-circle me-2"></i> Complete Setup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStep = 1;
        const totalSteps = 4;
        const folderPaths = [];
        const newUsers = [];

        function updateStepIndicators() {
            document.querySelectorAll('.step-item').forEach((item, idx) => {
                const stepNum = idx + 1;
                item.classList.remove('active', 'completed');
                if (stepNum < currentStep) {
                    item.classList.add('completed');
                } else if (stepNum === currentStep) {
                    item.classList.add('active');
                }
            });

            // Update progress bar
            const progress = (currentStep / totalSteps) * 100;
            document.querySelector('.progress-bar-setup .progress').style.width = progress + '%';

            // Show/hide steps
            document.querySelectorAll('.setup-step').forEach(step => {
                step.classList.remove('active');
            });
            document.querySelector(`.setup-step[data-step="${currentStep}"]`).classList.add('active');
        }

        function validateStep(step) {
            const errorDiv = document.getElementById(`step${step}Errors`);
            let errors = [];

            if (step === 1) {
                if (!document.getElementById('hospitalName').value.trim()) {
                    errors.push('Hospital name is required');
                }
            } else if (step === 2) {
                if (!document.getElementById('addressLine1').value.trim()) {
                    errors.push('Address Line 1 is required');
                }
                if (!document.getElementById('city').value.trim()) {
                    errors.push('City is required');
                }
                if (!document.getElementById('state').value.trim()) {
                    errors.push('State is required');
                }
            } else if (step === 3) {
                if (!document.getElementById('adminUsername').value.trim()) {
                    errors.push('Admin username is required');
                }
                if (!document.getElementById('adminPassword').value.trim()) {
                    errors.push('Admin password is required');
                }
                if (document.getElementById('adminPassword').value.length < 4) {
                    errors.push('Password must be at least 4 characters');
                }
            } else if (step === 4) {
                // Folder monitoring is optional - no validation needed
                // Users can configure folder paths later from Settings
            }

            if (errors.length > 0) {
                errorDiv.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>' + errors.join('<br>');
                errorDiv.style.display = 'block';
                return false;
            }

            errorDiv.style.display = 'none';
            return true;
        }

        function nextStep() {
            if (!validateStep(currentStep)) return;
            
            if (currentStep < totalSteps) {
                currentStep++;
                updateStepIndicators();
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                updateStepIndicators();
            }
        }

        function previewLogo(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('logoPreview').src = e.target.result;
                    document.getElementById('logoPreview').style.display = 'block';
                    document.getElementById('logoPlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function addFolderPath() {
            const pathInput = document.getElementById('newFolderPath');
            const path = pathInput.value.trim();
            
            if (!path) {
                alert('Please enter a folder path');
                return;
            }

            if (folderPaths.includes(path)) {
                alert('This path is already added');
                return;
            }

            folderPaths.push(path);
            renderFolderPaths();
            pathInput.value = '';
        }

        function removeFolderPath(index) {
            folderPaths.splice(index, 1);
            renderFolderPaths();
        }

        function renderFolderPaths() {
            const container = document.getElementById('folderPathsList');
            if (folderPaths.length === 0) {
                container.innerHTML = '<p class="text-muted text-center py-3"><i class="bi bi-folder-x me-2"></i>No folders added yet</p>';
                return;
            }

            container.innerHTML = folderPaths.map((path, idx) => `
                <div class="path-item">
                    <span class="path-text"><i class="bi bi-folder me-2 text-warning"></i>${path}</span>
                    <button class="btn-remove" onclick="removeFolderPath(${idx})">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            `).join('');
        }

        function addUser() {
            const username = document.getElementById('newUsername').value.trim();
            const fullName = document.getElementById('newFullName').value.trim();
            const email = document.getElementById('newEmail').value.trim();
            const role = document.getElementById('newRole').value;
            const password = document.getElementById('newPassword').value;

            if (!username || !password) {
                alert('Username and password are required');
                return;
            }

            newUsers.push({ username, fullName, email, role, password });

            // Add to UI
            const userCard = document.createElement('div');
            userCard.className = 'user-card';
            userCard.innerHTML = `
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-person fs-3 text-info me-3"></i>
                        <div>
                            <strong>${username}</strong>
                            <span class="badge bg-info ms-2">${role}</span>
                            <br><small class="text-muted">${fullName || 'No name'} • ${email || 'No email'}</small>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" onclick="this.parentElement.parentElement.remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
            document.getElementById('usersList').appendChild(userCard);

            // Clear form
            document.getElementById('newUsername').value = '';
            document.getElementById('newFullName').value = '';
            document.getElementById('newEmail').value = '';
            document.getElementById('newPassword').value = '';
        }

        async function completeSetup() {
            if (!validateStep(4)) return;

            // Collect all data
            const setupData = {
                hospital: {
                    name: document.getElementById('hospitalName').value.trim(),
                    department: document.getElementById('department').value.trim(),
                    phone: document.getElementById('hospitalPhone').value.trim(),
                    email: document.getElementById('hospitalEmail').value.trim()
                },
                address: {
                    line1: document.getElementById('addressLine1').value.trim(),
                    line2: document.getElementById('addressLine2').value.trim(),
                    city: document.getElementById('city').value.trim(),
                    state: document.getElementById('state').value.trim(),
                    pinCode: document.getElementById('pinCode').value.trim(),
                    country: document.getElementById('country').value.trim()
                },
                // Primary admin user (mandatory)
                adminUser: {
                    username: document.getElementById('adminUsername').value.trim(),
                    password: document.getElementById('adminPassword').value,
                    fullName: document.getElementById('adminFullName').value.trim() || 'Administrator',
                    email: document.getElementById('adminEmail').value.trim(),
                    role: 'admin'
                },
                // Additional users (optional)
                users: newUsers,
                folderMonitoring: {
                    paths: folderPaths,
                    interval: document.getElementById('checkInterval').value,
                    afterImport: document.getElementById('afterImport').value
                }
            };

            try {
                // First, upload logo if selected
                const logoFile = document.getElementById('hospitalLogo').files[0];
                if (logoFile) {
                    const logoFormData = new FormData();
                    logoFormData.append('logo', logoFile);

                    try {
                        const logoResponse = await fetch('<?= BASE_PATH ?>/api/settings/upload-logo.php', {
                            method: 'POST',
                            body: logoFormData
                        });
                        const logoResult = await logoResponse.json();
                        if (!logoResult.success) {
                            console.warn('Logo upload failed:', logoResult.error);
                        }
                    } catch (logoErr) {
                        console.warn('Logo upload error:', logoErr);
                    }
                }

                // Complete setup
                const response = await fetch('<?= BASE_PATH ?>/api/setup/complete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(setupData)
                });

                const result = await response.json();

                if (result.success) {
                    // Clear any existing tour flags to ensure fresh tour experience
                    localStorage.removeItem('patientsTourCompleted');
                    localStorage.removeItem('studiesTourCompleted');
                    localStorage.removeItem('viewerTourCompleted');
                    localStorage.removeItem('fullTourCompleted');
                    localStorage.removeItem('fullTourSkipped');
                    // Clear sync flag so progress bar shows for initial sync
                    localStorage.removeItem('initialSyncCompleted');

                    alert('Setup completed successfully! Redirecting to the application...');
                    window.location.href = '<?= BASE_PATH ?>/pages/patients.html?tour=1';
                } else {
                    throw new Error(result.error || 'Setup failed');
                }
            } catch (error) {
                alert('Error completing setup: ' + error.message);
            }
        }

        // Initialize
        renderFolderPaths();
    </script>
</body>
</html>

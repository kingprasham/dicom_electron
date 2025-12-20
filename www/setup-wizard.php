<?php
/**
 * First-Time Setup Wizard
 * Guides new admins through initial configuration
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/auth/session.php';

requireLogin('login.php');

// Only admins can access setup wizard
if (!isAdmin()) {
    header('Location: pages/patients.html');
    exit;
}

$userName = $_SESSION['username'] ?? 'Admin';

// Get current onboarding progress
$db = getDbConnection();
$progress = $db->query("SELECT * FROM onboarding_progress WHERE id = 1")->fetch_assoc();
$currentStep = $progress['current_step'] ?? 1;
$completedSteps = json_decode($progress['completed_steps'] ?? '[]', true);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Setup Wizard - DICOM Viewer Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/input-fix.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            min-height: 100vh;
            color: #fff;
        }
        .wizard-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 40px;
        }
        .step-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transition: all 0.3s;
        }
        .step-dot.active {
            background: #0d6efd;
            transform: scale(1.3);
        }
        .step-dot.completed {
            background: #22c55e;
        }
        .wizard-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 40px;
            min-height: 500px;
        }
        .step-content {
            display: none;
        }
        .step-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .feature-card {
            background: rgba(13, 110, 253, 0.1);
            border: 1px solid rgba(13, 110, 253, 0.3);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            height: 100%;
        }
        .feature-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #0d6efd, #6610f2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.5rem;
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
        .btn-wizard {
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
        }
        .tutorial-video {
            background: #000;
            border-radius: 16px;
            aspect-ratio: 16/9;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }
        .demo-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #f59e0b;
            color: #000;
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
        }
        .skip-link {
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .skip-link:hover {
            color: rgba(255, 255, 255, 0.8);
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <!-- Logo -->
        <div class="text-center mb-4">
            <h2 class="text-white">
                <i class="bi bi-heart-pulse-fill text-primary me-2"></i>
                DICOM Viewer Pro
            </h2>
            <p class="text-muted">Initial Setup Wizard</p>
            <a href="<?= BASE_PATH ?>/pages/patients.html?skip_wizard=1" class="btn btn-outline-secondary btn-sm mt-2" onclick="skipWizard()">
                <i class="bi bi-skip-forward me-1"></i> Skip Tutorial & Go to App
            </a>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-dot" data-step="1"></div>
            <div class="step-dot" data-step="2"></div>
            <div class="step-dot" data-step="3"></div>
            <div class="step-dot" data-step="4"></div>
            <div class="step-dot" data-step="5"></div>
            <div class="step-dot" data-step="6"></div>
            <div class="step-dot" data-step="7"></div>
        </div>

        <div class="wizard-card">
            <!-- Step 1: Welcome -->
            <div class="step-content" data-step="1">
                <div class="text-center mb-5">
                    <div class="feature-icon mx-auto" style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="bi bi-hand-wave"></i>
                    </div>
                    <h3 class="text-white mt-4">Welcome to DICOM Viewer Pro!</h3>
                    <p class="text-muted">Let's set up your medical imaging workstation in a few easy steps.</p>
                </div>

                <div class="row g-4 mb-5">
                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="bi bi-image"></i>
                            </div>
                            <h6 class="text-white">View DICOM Images</h6>
                            <p class="text-muted small mb-0">Open and analyze medical images with advanced tools</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="bi bi-file-medical"></i>
                            </div>
                            <h6 class="text-white">Create Reports</h6>
                            <p class="text-muted small mb-0">Generate professional medical reports</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="bi bi-printer"></i>
                            </div>
                            <h6 class="text-white">Print Studies</h6>
                            <p class="text-muted small mb-0">Print to DICOM printers or PDF</p>
                        </div>
                    </div>
                </div>

                <div class="text-center">
                    <button class="btn btn-primary btn-wizard btn-lg" onclick="nextStep()">
                        Get Started <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 2: Hospital Information -->
            <div class="step-content" data-step="2">
                <h4 class="text-white mb-4"><i class="bi bi-hospital text-primary me-2"></i>Hospital Information</h4>
                <p class="text-muted mb-4">Enter your hospital or clinic details. This will appear on reports and prints.</p>

                <form id="hospitalForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Hospital/Clinic Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="hospital_name" required placeholder="City General Hospital">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" placeholder="Radiology Department">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="address" rows="2" required placeholder="123 Medical Center Drive"></textarea>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="city" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">State</label>
                            <input type="text" class="form-control" name="state">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">PIN Code</label>
                            <input type="text" class="form-control" name="pincode">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" required placeholder="+91 98765 43210">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" placeholder="radiology@hospital.com">
                        </div>
                    </div>
                </form>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-wizard" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button class="btn btn-primary btn-wizard" onclick="saveHospitalAndNext()">
                        Save & Continue <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 3: PACS Connection -->
            <div class="step-content" data-step="3">
                <h4 class="text-white mb-4"><i class="bi bi-server text-primary me-2"></i>PACS/Orthanc Connection</h4>
                <p class="text-muted mb-4">Configure your PACS server connection. This can be done later if not ready.</p>

                <form id="pacsForm">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Orthanc Server URL</label>
                            <input type="url" class="form-control" name="orthanc_url" placeholder="http://localhost:8042">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">AE Title</label>
                            <input type="text" class="form-control" name="orthanc_aet" placeholder="ORTHANC">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="orthanc_user" placeholder="orthanc">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="orthanc_pass" placeholder="********">
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="testConnection()">
                        <i class="bi bi-check-circle"></i> Test Connection
                    </button>
                    <span id="connectionStatus" class="ms-2"></span>
                </form>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-wizard" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <div>
                        <a href="#" class="skip-link me-3" onclick="nextStep(); return false;">Skip for now</a>
                        <button class="btn btn-primary btn-wizard" onclick="savePACSAndNext()">
                            Save & Continue <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 4: Quick Tutorial -->
            <div class="step-content" data-step="4">
                <h4 class="text-white mb-4"><i class="bi bi-play-circle text-primary me-2"></i>Quick Tutorial</h4>
                <p class="text-muted mb-4">Watch this quick overview of the main features.</p>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="tutorial-video">
                            <div class="text-center text-muted">
                                <i class="bi bi-film" style="font-size: 4rem;"></i>
                                <p class="mt-3">Tutorial video placeholder</p>
                                <small>We'll show you around the software!</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="text-white mb-3">Key Features:</h6>
                        <ul class="list-unstyled">
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <span class="text-light">Open DICOM images</span>
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <span class="text-light">Window/Level adjustments</span>
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <span class="text-light">Measurements & annotations</span>
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <span class="text-light">Multi-viewport layouts</span>
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <span class="text-light">Generate reports</span>
                            </li>
                            <li class="mb-3">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                <span class="text-light">Print to DICOM printers</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-wizard" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button class="btn btn-primary btn-wizard" onclick="nextStep()">
                        Continue <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 5: Create Users -->
            <div class="step-content" data-step="5">
                <h4 class="text-white mb-4"><i class="bi bi-people text-primary me-2"></i>Create User Accounts</h4>
                <p class="text-muted mb-4">Add user accounts for your team. You can add more users later from Settings.</p>

                <div id="usersList" class="mb-4">
                    <!-- Users will be listed here -->
                </div>

                <div class="card bg-dark border-secondary p-3 mb-4">
                    <h6 class="text-white mb-3">Add New User</h6>
                    <form id="addUserForm">
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <input type="text" class="form-control" name="username" placeholder="Username" required>
                            </div>
                            <div class="col-md-4 mb-2">
                                <input type="email" class="form-control" name="email" placeholder="Email">
                            </div>
                            <div class="col-md-3 mb-2">
                                <select class="form-select" name="role">
                                    <option value="viewer">Viewer</option>
                                    <option value="technician">Technician</option>
                                    <option value="radiologist">Radiologist</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </div>
                            <div class="col-md-1 mb-2">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                        </div>
                        <small class="text-muted">Default password will be: <code>changeme123</code></small>
                    </form>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-wizard" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <div>
                        <a href="#" class="skip-link me-3" onclick="nextStep(); return false;">Skip for now</a>
                        <button class="btn btn-primary btn-wizard" onclick="nextStep()">
                            Continue <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 6: Printers -->
            <div class="step-content" data-step="6">
                <h4 class="text-white mb-4"><i class="bi bi-printer text-primary me-2"></i>Print Settings</h4>
                <p class="text-muted mb-4">Configure your default print settings. DICOM printers can be added later.</p>

                <form id="printForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Default Paper Size</label>
                            <select class="form-select" name="paper_size">
                                <option value="A4">A4 (210 x 297 mm)</option>
                                <option value="Letter">Letter (8.5 x 11 in)</option>
                                <option value="8x10">8x10 inches</option>
                                <option value="10x12">10x12 inches</option>
                                <option value="14x17">14x17 inches</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Default Film Type</label>
                            <select class="form-select" name="film_type">
                                <option value="Blue Film">Blue Film</option>
                                <option value="Clear Film">Clear Film</option>
                                <option value="Photo Paper">Photo Paper</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Images Per Sheet</label>
                            <select class="form-select" name="images_per_sheet">
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="4" selected>4</option>
                                <option value="6">6</option>
                                <option value="9">9</option>
                            </select>
                        </div>
                    </div>
                </form>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-wizard" onclick="prevStep()">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button class="btn btn-primary btn-wizard" onclick="savePrintAndNext()">
                        Save & Continue <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 7: Complete -->
            <div class="step-content" data-step="7">
                <div class="text-center py-4">
                    <div class="feature-icon mx-auto mb-4" style="width: 100px; height: 100px; font-size: 3rem; background: linear-gradient(135deg, #22c55e, #16a34a);">
                        <i class="bi bi-check-lg"></i>
                    </div>
                    <h3 class="text-white mb-3">Setup Complete!</h3>
                    <p class="text-muted mb-4">Your DICOM Viewer Pro is ready to use. You can change any settings later from the Settings menu.</p>

                    <div class="alert alert-info d-inline-block">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Next Steps:</strong> We've added a sample patient study for you to explore. It will be removed when you import your first real study.
                    </div>

                    <div class="mt-5">
                        <button class="btn btn-success btn-wizard btn-lg" onclick="completeSetup()">
                            <i class="bi bi-rocket-takeoff me-2"></i> Launch DICOM Viewer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= BASE_PATH ?>/assets/js/electron-input-fix.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]').content;
        let currentStep = <?= $currentStep ?>;
        const totalSteps = 7;

        document.addEventListener('DOMContentLoaded', () => {
            showStep(currentStep);
        });

        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.step-dot').forEach(el => {
                el.classList.remove('active');
                if (parseInt(el.dataset.step) < step) {
                    el.classList.add('completed');
                } else {
                    el.classList.remove('completed');
                }
            });

            // Show current step
            document.querySelector(`.step-content[data-step="${step}"]`).classList.add('active');
            document.querySelector(`.step-dot[data-step="${step}"]`).classList.add('active');

            // Save progress
            saveProgress(step);
        }

        function nextStep() {
            if (currentStep < totalSteps) {
                currentStep++;
                showStep(currentStep);
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        }

        async function saveProgress(step) {
            try {
                await fetch(`${basePath}/api/setup/progress.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ step: step })
                });
            } catch (e) { console.error(e); }
        }

        async function saveHospitalAndNext() {
            const form = document.getElementById('hospitalForm');
            const formData = new FormData(form);
            const data = { settings: Object.fromEntries(formData.entries()) };

            try {
                const response = await fetch(`${basePath}/api/settings/save-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                if (result.success) {
                    nextStep();
                } else {
                    alert('Error saving settings: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                console.error(e);
                nextStep(); // Continue anyway
            }
        }

        async function savePACSAndNext() {
            const form = document.getElementById('pacsForm');
            const formData = new FormData(form);
            const data = { settings: Object.fromEntries(formData.entries()) };

            try {
                await fetch(`${basePath}/api/settings/save-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
            } catch (e) { console.error(e); }
            nextStep();
        }

        async function savePrintAndNext() {
            const form = document.getElementById('printForm');
            const formData = new FormData(form);
            const data = { settings: Object.fromEntries(formData.entries()) };

            try {
                await fetch(`${basePath}/api/settings/save-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
            } catch (e) { console.error(e); }
            nextStep();
        }

        async function testConnection() {
            const status = document.getElementById('connectionStatus');
            status.innerHTML = '<span class="text-warning"><i class="bi bi-hourglass-split"></i> Testing...</span>';

            const form = document.getElementById('pacsForm');
            const formData = new FormData(form);

            try {
                const response = await fetch(`${basePath}/api/settings/test-connection.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        orthanc_url: formData.get('orthanc_url'),
                        orthanc_username: formData.get('orthanc_user'),
                        orthanc_password: formData.get('orthanc_pass')
                    })
                });
                const result = await response.json();
                
                if (result.success) {
                    status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Connected!</span>';
                } else {
                    status.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> ' + (result.error || 'Failed') + '</span>';
                }
            } catch (e) {
                status.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Connection error</span>';
            }
        }

        async function skipWizard() {
            // Mark setup as complete and redirect
            try {
                await fetch(`${basePath}/api/setup/complete.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
            } catch (e) { console.error(e); }
        }

        async function completeSetup() {
            try {
                await fetch(`${basePath}/api/setup/complete.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
            } catch (e) { console.error(e); }
            
            window.location.href = `${basePath}/pages/patients.html`;
        }

        // Add user form handler
        document.getElementById('addUserForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            try {
                const response = await fetch(`${basePath}/api/users/create.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: formData.get('username'),
                        email: formData.get('email'),
                        role: formData.get('role'),
                        password: 'changeme123'
                    })
                });
                const result = await response.json();
                
                if (result.success) {
                    this.reset();
                    loadUsers();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Error creating user');
            }
        });

        async function loadUsers() {
            try {
                const response = await fetch(`${basePath}/api/users/list.php`);
                const result = await response.json();
                
                if (result.success) {
                    const container = document.getElementById('usersList');
                    container.innerHTML = result.users.filter(u => !u.is_super_admin).map(user => `
                        <div class="d-flex align-items-center justify-content-between p-2 border-bottom border-secondary">
                            <div>
                                <strong class="text-white">${user.username}</strong>
                                <span class="badge bg-secondary ms-2">${user.role}</span>
                            </div>
                            <small class="text-muted">${user.email || ''}</small>
                        </div>
                    `).join('') || '<p class="text-muted">No users created yet</p>';
                }
            } catch (e) { console.error(e); }
        }

        // Load users when on step 5
        if (currentStep === 5) loadUsers();
    </script>
</body>
</html>

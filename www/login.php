<?php
// Load session and config (BASE_PATH is defined in config.php)
define('DICOM_VIEWER', true);
require_once __DIR__ . '/auth/session.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_PATH . '/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title>Login - Accurate Viewer</title>
    <link href="<?= BASE_PATH ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header .logo {
            background: linear-gradient(135deg, #0d6efd, #0dcaf0);
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(13, 110, 253, 0.4);
        }

        .login-header .logo i {
            font-size: 40px;
            color: white;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: white;
            margin-bottom: 8px;
        }

        .login-header p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            margin: 0;
        }

        /* Tab Styles */
        .login-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .login-tab {
            flex: 1;
            padding: 12px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            background: transparent;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .login-tab:hover {
            border-color: rgba(255, 255, 255, 0.3);
            color: rgba(255, 255, 255, 0.8);
        }

        .login-tab.active {
            border-color: #0d6efd;
            background: rgba(13, 110, 253, 0.15);
            color: #fff;
        }

        .login-tab i {
            font-size: 1.2rem;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-floating {
            margin-bottom: 18px;
        }

        .form-floating > label {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-control {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
            padding: 12px 16px;
            font-size: 15px;
        }

        .form-control:focus {
            background: rgba(255, 255, 255, 0.12);
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
            color: white;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .license-input {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-align: center;
        }

        .form-check-input {
            background-color: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.15);
        }

        .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }

        .form-check-label {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            background: linear-gradient(135deg, #0d6efd, #0dcaf0);
            border: none;
            border-radius: 10px;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 5px 20px rgba(13, 110, 253, 0.4);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(13, 110, 253, 0.6);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-license {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            box-shadow: 0 5px 20px rgba(34, 197, 94, 0.4);
        }

        .btn-license:hover {
            box-shadow: 0 8px 30px rgba(34, 197, 94, 0.6);
        }

        .alert {
            border-radius: 10px;
            border: none;
            margin-bottom: 20px;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        /* Contact Admin Section */
        .contact-admin {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .contact-admin-title {
            color: rgba(255, 255, 255, 0.5);
            font-size: 12px;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .contact-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 13px;
            padding: 6px 12px;
            border-radius: 6px;
            transition: all 0.2s;
            margin: 0 5px;
        }

        .contact-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .contact-link i {
            font-size: 1rem;
        }

        .footer-text {
            text-align: center;
            margin-top: 20px;
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: rgba(255, 255, 255, 0.6);
            z-index: 10;
        }

        .password-toggle:hover {
            color: rgba(255, 255, 255, 0.9);
        }

        @media (max-width: 576px) {
            .login-card {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 20px;
            }

            .login-tabs {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <i class="bi bi-heart-pulse-fill"></i>
                </div>
                <h1>Accurate DICOM Viewer</h1>
                <p>Sign in to access medical imaging</p>
            </div>

            <!-- Login Method Tabs -->
            <div class="login-tabs">
                <button class="login-tab active" data-tab="credentials" onclick="switchTab('credentials')">
                    <i class="bi bi-person-fill"></i>
                    Admin Login
                </button>
                <button class="login-tab" data-tab="license" onclick="switchTab('license')">
                    <i class="bi bi-key-fill"></i>
                    License Key
                </button>
            </div>

            <div id="errorMessage" class="alert alert-danger" style="display: none;">
                <i class="bi bi-exclamation-circle-fill me-2"></i>
                <span id="errorText"></span>
            </div>

            <!-- Credentials Login Tab -->
            <div id="credentialsTab" class="tab-content active">
                <form id="loginForm">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="email" name="email" placeholder="Username or Email" required>
                        <label for="email"><i class="bi bi-person me-2"></i>Username or Email</label>
                    </div>

                    <div class="form-floating" style="position: relative;">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                        <label for="password"><i class="bi bi-lock me-2"></i>Password</label>
                        <i class="bi bi-eye password-toggle" id="togglePassword"></i>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="remember">
                        <label class="form-check-label" for="rememberMe">
                            Remember me for 30 days
                        </label>
                    </div>

                    <button type="submit" class="btn btn-login" id="loginBtn">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                    </button>
                </form>

            </div>

            <!-- License Key Login Tab -->
            <div id="licenseTab" class="tab-content">
                <form id="licenseForm">
                    <div class="text-center mb-3">
                        <small class="text-muted">Enter your license key to activate and access the application</small>
                    </div>
                    
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control license-input" id="licenseKey" name="license_key" 
                               placeholder="DICOM-XXXX-XXXX-XXXX-XXXX" required>
                        <label for="licenseKey"><i class="bi bi-key me-2"></i>License Key</label>
                    </div>

                    <button type="submit" class="btn btn-login btn-license" id="activateBtn">
                        <i class="bi bi-check-circle me-2"></i>Activate & Enter
                    </button>
                </form>

                <div id="licenseSuccess" style="display: none;" class="text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    <h5 class="text-white mt-3">License Activated!</h5>
                    <p class="text-muted">Redirecting to setup wizard...</p>
                </div>
            </div>

            <!-- Contact Admin Section -->
            <div class="contact-admin">
                <div class="contact-admin-title">Need Help? Contact Admin</div>
                <div>
                    <a href="tel:+919136335529" class="contact-link">
                        <i class="bi bi-telephone-fill text-success"></i>
                        +91 9136335529
                    </a>
                    <a href="mailto:prashamk15@gmail.com" class="contact-link">
                        <i class="bi bi-envelope-fill text-info"></i>
                        prashamk15@gmail.com
                    </a>
                </div>
            </div>

            <div class="footer-text">
                <i class="bi bi-shield-check me-1"></i>Secure medical data access
            </div>
        </div>
    </div>

    <script src="<?= BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';

        // Tab switching
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.login-tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.tab === tabName);
            });

            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName + 'Tab').classList.add('active');

            // Hide error
            hideError();

            // Focus appropriate field
            if (tabName === 'credentials') {
                document.getElementById('email').focus();
            } else {
                document.getElementById('licenseKey').focus();
            }
        }

        // Password toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('bi-eye');
            this.classList.toggle('bi-eye-slash');
        });

        // Form elements
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const errorMessage = document.getElementById('errorMessage');
        const errorText = document.getElementById('errorText');
        const twoFactorSection = document.getElementById('twoFactorSection');
        const twoFactorForm = document.getElementById('twoFactorForm');
        const verifyBtn = document.getElementById('verifyBtn');
        const backToLoginBtn = document.getElementById('backToLoginBtn');

        // Credentials Login
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            loginBtn.disabled = true;
            loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in...';
            errorMessage.style.display = 'none';

            const formData = new FormData(loginForm);
            const jsonData = Object.fromEntries(formData.entries());
            jsonData.username = jsonData.email;

            try {
                const response = await fetch(`${basePath}/api/auth/login.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(jsonData)
                });

                const data = await response.json();

                if (data.success) {
                    if (data.requires_2fa) {
                        loginForm.style.display = 'none';
                        twoFactorSection.style.display = 'block';
                        document.getElementById('totpCode').focus();
                        
                        loginBtn.disabled = false;
                        loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>Sign In';
                    } else {
                        loginBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Success!';
                        loginBtn.classList.remove('btn-login');
                        loginBtn.classList.add('btn-success');

                        // Redirect to dashboard (NO tour for admin login)
                        setTimeout(() => {
                            window.location.href = `${basePath}/dashboard.php`;
                        }, 500);
                    }
                } else {
                    showError(data.message || data.error || 'Invalid email or password');
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>Sign In';
                }
            } catch (error) {
                showError('Connection error. Please check your network.');
                loginBtn.disabled = false;
                loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>Sign In';
            }
        });

        // 2FA Form (only if elements exist)
        if (twoFactorForm) {
            twoFactorForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                verifyBtn.disabled = true;
                verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
                errorMessage.style.display = 'none';

                const code = document.getElementById('totpCode').value;

                try {
                    const response = await fetch(`${basePath}/api/auth/verify-2fa.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code })
                    });

                    const data = await response.json();

                    if (data.success) {
                        verifyBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Success!';
                        verifyBtn.classList.remove('btn-login');
                        verifyBtn.classList.add('btn-success');

                        setTimeout(() => {
                            window.location.href = `${basePath}/dashboard.php`;
                        }, 500);
                    } else {
                        showError(data.error || 'Invalid verification code');
                        document.getElementById('totpCode').value = '';
                        document.getElementById('totpCode').focus();

                        verifyBtn.disabled = false;
                        verifyBtn.innerHTML = '<i class="bi bi-unlock me-2"></i>Verify';
                    }
                } catch (error) {
                    showError('Connection error. Please try again.');
                    verifyBtn.disabled = false;
                    verifyBtn.innerHTML = '<i class="bi bi-unlock me-2"></i>Verify';
                }
            });
        }

        // Back to login (only if element exists)
        if (backToLoginBtn) {
            backToLoginBtn.addEventListener('click', function() {
                twoFactorSection.style.display = 'none';
                loginForm.style.display = 'block';
                document.getElementById('totpCode').value = '';
                document.getElementById('password').value = '';
                document.getElementById('password').focus();
            });
        }

        // License Key Activation
        const licenseForm = document.getElementById('licenseForm');
        const activateBtn = document.getElementById('activateBtn');
        const licenseSuccess = document.getElementById('licenseSuccess');

        licenseForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            activateBtn.disabled = true;
            activateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Activating...';
            errorMessage.style.display = 'none';

            const licenseKey = document.getElementById('licenseKey').value.trim().toUpperCase();

            try {
                const response = await fetch(`${basePath}/api/license/activate.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ license_key: licenseKey })
                });

                const data = await response.json();

                if (data.success) {
                    licenseForm.style.display = 'none';
                    licenseSuccess.style.display = 'block';

                    // Redirect to setup wizard first (which will then redirect to patients with tour)
                    // The setup.php handles first-time hospital configuration
                    setTimeout(() => {
                        window.location.href = `${basePath}/setup.php`;
                    }, 1500);
                } else {
                    showError(data.error || 'Invalid or expired license key');
                    activateBtn.disabled = false;
                    activateBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Activate & Enter';
                }
            } catch (error) {
                showError('Connection error. Please try again.');
                activateBtn.disabled = false;
                activateBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Activate & Enter';
            }
        });

        // Format license key input
        document.getElementById('licenseKey').addEventListener('input', function(e) {
            let value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            
            // Auto-add dashes
            if (value.length > 0) {
                const parts = [];
                if (value.length >= 5) {
                    parts.push(value.substring(0, 5));
                    value = value.substring(5);
                } else {
                    parts.push(value);
                    value = '';
                }
                while (value.length > 0) {
                    parts.push(value.substring(0, 4));
                    value = value.substring(4);
                }
                this.value = parts.join('-');
            }
            hideError();
        });

        // Allow only numbers in TOTP code
        document.getElementById('totpCode').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            hideError();
        });

        // Error handling
        function showError(message) {
            errorText.textContent = message;
            errorMessage.style.display = 'block';
        }

        function hideError() {
            if (errorMessage.style.display !== 'none') {
                errorMessage.style.display = 'none';
            }
        }

        document.getElementById('email').addEventListener('input', hideError);
        document.getElementById('password').addEventListener('input', hideError);
    </script>
</body>
</html>

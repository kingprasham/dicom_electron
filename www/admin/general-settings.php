<?php
/**
 * General Settings Page
 * Public settings: Hospital Information, Address, Print Settings
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';

requireLogin('../pages/login.html');

if (!isAdmin()) {
    header('Location: ../pages/patients.html');
    exit;
}

$userName = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>General Settings - DICOM Viewer</title>
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
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }
        .nav-pills-custom .nav-link {
            color: rgba(255, 255, 255, 0.7);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            margin-right: 10px;
        }
        .nav-pills-custom .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
        }
        .nav-pills-custom .nav-link.active {
            color: #fff;
            background: #0d6efd;
            border-color: #0d6efd;
        }
        .save-indicator {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }
        .settings-nav-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
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
                <a href="<?= BASE_PATH ?>/pages/patients.html" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Patients
                </a>
                <span class="text-light">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($userName) ?> (Admin)
                </span>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white">
                <i class="bi bi-gear-fill text-primary"></i>
                General Settings
            </h2>
            <div class="d-flex gap-2">
                <a href="<?= BASE_PATH ?>/admin/user-management.php" class="btn btn-outline-info">
                    <i class="bi bi-people"></i> User Management
                </a>
                <button class="btn btn-success" id="saveAllBtn">
                    <i class="bi bi-check-circle"></i> Save All Settings
                </button>
            </div>
        </div>

        <!-- Settings Navigation -->
        <div class="settings-nav-card">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="text-muted me-2">Settings:</span>
                <a href="<?= BASE_PATH ?>/admin/general-settings.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-hospital"></i> General
                </a>
                <a href="<?= BASE_PATH ?>/admin/folder-config.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-folder-symlink"></i> Folder Monitoring
                </a>
                <a href="<?= BASE_PATH ?>/admin/private-settings.php" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-shield-lock"></i> Private Settings
                </a>

                <a href="<?= BASE_PATH ?>/admin/hospital-config.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-database"></i> Backup & Import
                </a>

            </div>
        </div>

        <!-- Save Indicator -->
        <div class="save-indicator alert alert-success" id="saveIndicator">
            <i class="bi bi-check-circle-fill"></i> Settings saved successfully
        </div>

        <!-- Settings Form -->
        <form id="settingsForm">
            <!-- Hospital Information -->
            <div class="settings-card">
                <div class="category-header">
                    <i class="bi bi-hospital category-icon"></i>
                    <h4 class="mb-0 text-white">Hospital Information</h4>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-light">Hospital Name</label>
                        <input type="text" class="form-control" name="hospital_name" placeholder="Enter hospital name">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-light">Timezone</label>
                        <select class="form-select" name="hospital_timezone">
                            <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
                            <option value="America/New_York">America/New_York (EST)</option>
                            <option value="Europe/London">Europe/London (GMT)</option>
                            <option value="Asia/Dubai">Asia/Dubai (GST)</option>
                            <option value="Australia/Sydney">Australia/Sydney (AEST)</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label text-light">Location / Address</label>
                        <textarea class="form-control" name="hospital_location" rows="2" placeholder="Enter complete hospital address or location"></textarea>
                    </div>
                </div>

                <!-- Hospital Logo Upload -->
                <div class="row mt-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-light">Hospital Logo</label>
                        <div class="d-flex align-items-center gap-3">
                            <div id="logoPreviewContainer" class="border border-secondary rounded p-2" style="width: 120px; height: 80px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.05);">
                                <img id="logoPreview" src="" alt="Logo Preview" style="max-width: 100%; max-height: 100%; display: none;">
                                <span id="noLogoText" class="text-muted small"><i class="bi bi-image"></i> No Logo</span>
                            </div>
                            <div class="flex-grow-1">
                                <input type="file" class="form-control" id="hospitalLogoInput" accept=".jpg,.jpeg,.png" style="display: none;">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('hospitalLogoInput').click()">
                                    <i class="bi bi-upload"></i> Upload Logo
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="removeLogoBtn" onclick="removeLogo()" style="display: none;">
                                    <i class="bi bi-trash"></i> Remove
                                </button>
                                <div class="form-text text-muted small mt-1">
                                    Supported: JPG, PNG. Max 2MB. Recommended: 200x100px
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-light">Logo Display</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="show_logo_header" id="showLogoHeader" checked>
                            <label class="form-check-label text-light" for="showLogoHeader">
                                Show logo in application header
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="show_logo_print" id="showLogoPrint" checked>
                            <label class="form-check-label text-light" for="showLogoPrint">
                                Show logo in print/PDF exports
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hospital Address -->
            <div class="settings-card">
                <div class="category-header">
                    <i class="bi bi-geo-alt category-icon"></i>
                    <h4 class="mb-0 text-white">Hospital Address</h4>
                </div>
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label text-light">Address Line 1</label>
                        <input type="text" class="form-control" name="hospital_address1" placeholder="Building name, Street address">
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="form-label text-light">Address Line 2</label>
                        <input type="text" class="form-control" name="hospital_address2" placeholder="Area, Landmark">
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="form-label text-light">Address Line 3 <span class="text-muted">(Optional)</span></label>
                        <input type="text" class="form-control" name="hospital_address3" placeholder="Additional address details">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-light">City</label>
                        <input type="text" class="form-control" name="hospital_city" placeholder="Enter city">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-light">State</label>
                        <select class="form-select" name="hospital_state">
                            <option value="">Select State</option>
                            <option value="Andhra Pradesh">Andhra Pradesh</option>
                            <option value="Arunachal Pradesh">Arunachal Pradesh</option>
                            <option value="Assam">Assam</option>
                            <option value="Bihar">Bihar</option>
                            <option value="Chhattisgarh">Chhattisgarh</option>
                            <option value="Goa">Goa</option>
                            <option value="Gujarat">Gujarat</option>
                            <option value="Haryana">Haryana</option>
                            <option value="Himachal Pradesh">Himachal Pradesh</option>
                            <option value="Jharkhand">Jharkhand</option>
                            <option value="Karnataka">Karnataka</option>
                            <option value="Kerala">Kerala</option>
                            <option value="Madhya Pradesh">Madhya Pradesh</option>
                            <option value="Maharashtra">Maharashtra</option>
                            <option value="Manipur">Manipur</option>
                            <option value="Meghalaya">Meghalaya</option>
                            <option value="Mizoram">Mizoram</option>
                            <option value="Nagaland">Nagaland</option>
                            <option value="Odisha">Odisha</option>
                            <option value="Punjab">Punjab</option>
                            <option value="Rajasthan">Rajasthan</option>
                            <option value="Sikkim">Sikkim</option>
                            <option value="Tamil Nadu">Tamil Nadu</option>
                            <option value="Telangana">Telangana</option>
                            <option value="Tripura">Tripura</option>
                            <option value="Uttar Pradesh">Uttar Pradesh</option>
                            <option value="Uttarakhand">Uttarakhand</option>
                            <option value="West Bengal">West Bengal</option>
                            <option value="Delhi">Delhi</option>
                            <option value="Jammu and Kashmir">Jammu and Kashmir</option>
                            <option value="Ladakh">Ladakh</option>
                            <option value="Puducherry">Puducherry</option>
                            <option value="Chandigarh">Chandigarh</option>
                            <option value="Andaman and Nicobar Islands">Andaman and Nicobar Islands</option>
                            <option value="Dadra and Nagar Haveli and Daman and Diu">Dadra and Nagar Haveli and Daman and Diu</option>
                            <option value="Lakshadweep">Lakshadweep</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label text-light">Pincode</label>
                        <input type="text" class="form-control" name="hospital_pincode" placeholder="6-digit pincode" maxlength="6" pattern="[0-9]{6}">
                    </div>
                </div>
            </div>

            <!-- Print Settings -->
            <div class="settings-card">
                <div class="category-header d-flex justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-printer-fill category-icon"></i>
                        <h4 class="mb-0 text-white">Print Settings</h4>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Configure once, print consistently:</strong> These settings will be applied to all prints across the system.
                </div>

                <!-- Print Border Settings -->
                <div class="mb-4">
                    <h6 class="text-primary mb-3">
                        <i class="bi bi-border-outer me-2"></i>Print Border
                    </h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label text-light">Enable Border</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="printBorderEnabled" name="print_border_enabled" checked>
                                <label class="form-check-label text-muted" for="printBorderEnabled">Add border to images</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-light">Border Color</label>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <input type="color" class="form-control form-control-color" id="printBorderColor" name="print_border_color" value="#000000" title="Choose border color" style="width: 50px;">
                                <div class="d-flex gap-1 flex-wrap">
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#000000" title="Black" style="width: 28px; height: 28px; background: #000000; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#333333" title="Dark Gray" style="width: 28px; height: 28px; background: #333333; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#666666" title="Gray" style="width: 28px; height: 28px; background: #666666; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#ffffff" title="White" style="width: 28px; height: 28px; background: #ffffff; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#0d6efd" title="Blue" style="width: 28px; height: 28px; background: #0d6efd; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#198754" title="Green" style="width: 28px; height: 28px; background: #198754; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#dc3545" title="Red" style="width: 28px; height: 28px; background: #dc3545; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#ffc107" title="Yellow" style="width: 28px; height: 28px; background: #ffc107; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#6f42c1" title="Purple" style="width: 28px; height: 28px; background: #6f42c1; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#fd7e14" title="Orange" style="width: 28px; height: 28px; background: #fd7e14; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#20c997" title="Teal" style="width: 28px; height: 28px; background: #20c997; border-color: #555; padding: 0;"></button>
                                    <button type="button" class="btn btn-outline-light border-color-btn" data-color="#0dcaf0" title="Cyan" style="width: 28px; height: 28px; background: #0dcaf0; border-color: #555; padding: 0;"></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-light">Border Thickness: <span id="borderThicknessLabel">2</span>px</label>
                            <input type="range" class="form-range" id="printBorderWidth" name="print_border_width" min="1" max="8" value="2">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-light">Border Style</label>
                            <select class="form-select bg-dark text-light" id="printBorderStyle" name="print_border_style" style="border-color: rgba(255,255,255,0.2);">
                                <option value="solid">Solid</option>
                                <option value="dashed">Dashed</option>
                                <option value="dotted">Dotted</option>
                                <option value="double">Double</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-light">Preview:</label>
                            <div id="borderPreviewBox" style="width: 120px; height: 70px; background: #333; border: 2px solid #000000; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                <span class="text-muted small">Sample</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Include Information Section -->
                <div class="mb-4">
                    <h6 class="text-primary mb-3">
                        <i class="bi bi-card-text me-2"></i>Information to Include on Prints
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="printIncludePatientInfo" name="includePatientInfo" checked>
                                <label class="form-check-label text-light" for="printIncludePatientInfo">
                                    <i class="bi bi-person-badge me-2 text-info"></i>Patient Information
                                </label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="printIncludeStudyInfo" name="includeStudyInfo" checked>
                                <label class="form-check-label text-light" for="printIncludeStudyInfo">
                                    <i class="bi bi-file-medical me-2 text-success"></i>Study Information
                                </label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="printIncludeInstitutionInfo" name="includeInstitutionInfo" checked>
                                <label class="form-check-label text-light" for="printIncludeInstitutionInfo">
                                    <i class="bi bi-hospital me-2 text-primary"></i>Hospital/Institution Info
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="printIncludeAnnotations" name="includeAnnotations" checked>
                                <label class="form-check-label text-light" for="printIncludeAnnotations">
                                    <i class="bi bi-pencil-square me-2 text-warning"></i>Annotations & Shapes
                                </label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="printIncludeWindowLevel" name="includeWindowLevel" checked>
                                <label class="form-check-label text-light" for="printIncludeWindowLevel">
                                    <i class="bi bi-sliders me-2 text-success"></i>Window/Level Values
                                </label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="printIncludeMeasurements" name="includeMeasurements" checked>
                                <label class="form-check-label text-light" for="printIncludeMeasurements">
                                    <i class="bi bi-rulers me-2 text-danger"></i>Measurements
                                </label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="printIncludeTimestamp" name="includeTimestamp" checked>
                                <label class="form-check-label text-light" for="printIncludeTimestamp">
                                    <i class="bi bi-clock me-2 text-info"></i>Timestamp
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Page Layout Section -->
                <div class="mb-4">
                    <h6 class="text-primary mb-3">
                        <i class="bi bi-layout-text-window me-2"></i>Page Layout
                    </h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-light">Paper Size</label>
                            <select class="form-select" id="printPaperSize" name="paperSize">
                                <option value="A4" selected>A4 (210 x 297 mm)</option>
                                <option value="A3">A3 (297 x 420 mm)</option>
                                <option value="Letter">Letter (8.5 x 11 in)</option>
                                <option value="Legal">Legal (8.5 x 14 in)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-light">Orientation</label>
                            <select class="form-select" id="printOrientation" name="orientation">
                                <option value="landscape" selected>Landscape</option>
                                <option value="portrait">Portrait</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-light">Margins</label>
                            <select class="form-select" id="printMargins" name="margins">
                                <option value="none">None</option>
                                <option value="narrow">Narrow (5mm)</option>
                                <option value="normal" selected>Normal (10mm)</option>
                                <option value="wide">Wide (20mm)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Output Quality Section -->
                <div class="mb-4">
                    <h6 class="text-primary mb-3">
                        <i class="bi bi-image me-2"></i>Output Quality
                    </h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-light">Print Quality</label>
                            <select class="form-select" id="printQuality" name="quality">
                                <option value="draft">Draft (Fast, Lower Quality)</option>
                                <option value="normal">Normal</option>
                                <option value="high" selected>High Quality (Recommended)</option>
                                <option value="maximum">Maximum (Slow, Best Quality)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-light">Color Mode</label>
                            <select class="form-select" id="printColorMode" name="colorMode">
                                <option value="grayscale" selected>Grayscale (Medical Standard)</option>
                                <option value="color">Color</option>
                                <option value="inverted">Inverted Grayscale</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Authorized Printers Section -->
            <div class="settings-card">
                <div class="category-header d-flex justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-printer category-icon text-success"></i>
                        <h4 class="mb-0 text-white">Authorized Printers</h4>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="addPrinterBtn">
                        <i class="bi bi-plus-circle me-1"></i> Add Printer
                    </button>
                </div>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Custom Print Dialog:</strong> Only printers configured here will appear in the print dialog. This restricts users to using approved printers only.
                </div>

                <!-- System Printers Detection (for Electron) -->
                <div id="electronPrinterSection" class="mb-4" style="display: none;">
                    <h6 class="text-primary mb-3">
                        <i class="bi bi-search me-2"></i>Detected System Printers
                    </h6>
                    <div id="systemPrintersList" class="mb-3">
                        <div class="text-muted"><i class="bi bi-hourglass-split me-2"></i>Detecting printers...</div>
                    </div>
                </div>

                <!-- Configured Printers List -->
                <div class="mb-4">
                    <h6 class="text-primary mb-3">
                        <i class="bi bi-list-check me-2"></i>Configured Printers
                    </h6>
                    <div id="hospitalPrintersList">
                        <div class="text-muted text-center py-4">
                            <i class="bi bi-printer fs-1 d-block mb-2 text-secondary"></i>
                            No printers configured. Click "Add Printer" to get started.
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Add Printer Modal -->
        <div class="modal fade" id="addPrinterModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-printer-fill me-2"></i>Add Printer</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="addPrinterForm">
                            <div class="mb-3">
                                <label class="form-label">Select Printer</label>
                                <select class="form-select bg-dark text-light border-secondary" id="printerSelect" required>
                                    <option value="">-- Select from detected printers --</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Display Name</label>
                                <input type="text" class="form-control bg-dark text-light border-secondary" id="printerDisplayName" placeholder="Friendly name for this printer">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description (Optional)</label>
                                <input type="text" class="form-control bg-dark text-light border-secondary" id="printerDescription" placeholder="e.g., Reception Area Printer">
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="printerIsDefault">
                                <label class="form-check-label" for="printerIsDefault">Set as default printer</label>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="savePrinterBtn">
                            <i class="bi bi-check-lg me-1"></i>Add Printer
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="settings-card border-danger" style="background: rgba(220, 53, 69, 0.05);">
            <div class="category-header border-danger">
                <i class="bi bi-exclamation-octagon-fill category-icon text-danger"></i>
                <h4 class="mb-0 text-danger">Danger Zone</h4>
            </div>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="text-white">System Reset</h5>
                    <p class="text-muted mb-0">Permanently delete all data, patients, and images to start fresh.</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="<?= BASE_PATH ?>/admin/reset-data.php" class="btn btn-outline-danger">
                        <i class="bi bi-trash3"></i> Reset System Data
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= BASE_PATH ?>/assets/js/electron-input-fix.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]').content;

        document.addEventListener('DOMContentLoaded', () => {
            loadSettings();
            loadCurrentLogo();
            loadPrintSettings();
            loadPrintBorderSettings();
            setupEventListeners();
        });

        async function loadSettings() {
            try {
                const response = await fetch(`${basePath}/api/settings/get-settings.php`);
                const data = await response.json();
                if (data.success) populateForm(data.settings);
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        function populateForm(settings) {
            Object.values(settings).flat().forEach(setting => {
                const input = document.querySelector(`[name="${setting.setting_key}"]`);
                if (input) {
                    if (input.type === 'checkbox') input.checked = Boolean(setting.setting_value);
                    else input.value = setting.setting_value || '';
                }
            });
        }

        async function saveAllSettings() {
            const form = document.getElementById('settingsForm');
            const formData = new FormData(form);
            const settings = {};

            for (let [key, value] of formData.entries()) {
                const input = form.querySelector(`[name="${key}"]`);
                if (input && input.type === 'checkbox') settings[key] = input.checked;
                else settings[key] = value;
            }

            try {
                // Save general settings
                const response = await fetch(`${basePath}/api/settings/update-settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings })
                });
                const data = await response.json();

                // Also save print settings
                await savePrintSettings();
                await savePrintBorderSettings();

                if (data.success) showSuccess('All settings saved successfully!');
                else alert('Error: ' + data.error);
            } catch (error) {
                alert('Error saving settings: ' + error.message);
            }
        }

        async function loadPrintSettings() {
            try {
                const response = await fetch(`${basePath}/api/settings/print-settings.php`);
                const data = await response.json();

                if (data.success && data.settings) {
                    const settings = data.settings;
                    document.getElementById('printIncludePatientInfo').checked = settings.includePatientInfo !== false;
                    document.getElementById('printIncludeStudyInfo').checked = settings.includeStudyInfo !== false;
                    document.getElementById('printIncludeInstitutionInfo').checked = settings.includeInstitutionInfo !== false;
                    document.getElementById('printIncludeAnnotations').checked = settings.includeAnnotations !== false;
                    document.getElementById('printIncludeWindowLevel').checked = settings.includeWindowLevel !== false;
                    document.getElementById('printIncludeMeasurements').checked = settings.includeMeasurements !== false;
                    document.getElementById('printIncludeTimestamp').checked = settings.includeTimestamp !== false;
                    document.getElementById('printPaperSize').value = settings.paperSize || 'A4';
                    document.getElementById('printOrientation').value = settings.orientation || 'landscape';
                    document.getElementById('printMargins').value = settings.margins || 'normal';
                    document.getElementById('printQuality').value = settings.quality || 'high';
                    document.getElementById('printColorMode').value = settings.colorMode || 'grayscale';
                }
            } catch (error) {
                console.error('Error loading print settings:', error);
            }
        }

        async function savePrintSettings() {
            const settings = {
                includePatientInfo: document.getElementById('printIncludePatientInfo').checked,
                includeStudyInfo: document.getElementById('printIncludeStudyInfo').checked,
                includeInstitutionInfo: document.getElementById('printIncludeInstitutionInfo').checked,
                includeAnnotations: document.getElementById('printIncludeAnnotations').checked,
                includeWindowLevel: document.getElementById('printIncludeWindowLevel').checked,
                includeMeasurements: document.getElementById('printIncludeMeasurements').checked,
                includeTimestamp: document.getElementById('printIncludeTimestamp').checked,
                paperSize: document.getElementById('printPaperSize').value,
                orientation: document.getElementById('printOrientation').value,
                margins: document.getElementById('printMargins').value,
                quality: document.getElementById('printQuality').value,
                colorMode: document.getElementById('printColorMode').value
            };

            await fetch(`${basePath}/api/settings/print-settings.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(settings)
            });
        }

        function loadPrintBorderSettings() {
            const saved = localStorage.getItem('dicomPrintBorderSettings');
            if (saved) {
                try {
                    const settings = JSON.parse(saved);
                    document.getElementById('printBorderEnabled').checked = settings.printBorderEnabled ?? true;
                    document.getElementById('printBorderColor').value = settings.printBorderColor || '#000000';
                    document.getElementById('printBorderWidth').value = settings.printBorderWidth || 2;
                    document.getElementById('borderThicknessLabel').textContent = settings.printBorderWidth || 2;
                    document.getElementById('printBorderStyle').value = settings.printBorderStyle || 'solid';
                    updateBorderPreview();
                } catch (e) {
                    console.error('Error loading print border settings:', e);
                }
            }
        }

        function savePrintBorderSettings() {
            const settings = {
                printBorderEnabled: document.getElementById('printBorderEnabled').checked,
                printBorderColor: document.getElementById('printBorderColor').value,
                printBorderWidth: parseInt(document.getElementById('printBorderWidth').value),
                printBorderStyle: document.getElementById('printBorderStyle').value
            };
            localStorage.setItem('dicomPrintBorderSettings', JSON.stringify(settings));
        }

        function updateBorderPreview() {
            const preview = document.getElementById('borderPreviewBox');
            const enabled = document.getElementById('printBorderEnabled').checked;
            const color = document.getElementById('printBorderColor').value;
            const width = document.getElementById('printBorderWidth').value;
            const style = document.getElementById('printBorderStyle').value;

            if (enabled) {
                preview.style.border = `${width}px ${style} ${color}`;
            } else {
                preview.style.border = '2px dashed #666';
            }
        }

        function setupEventListeners() {
            document.getElementById('saveAllBtn').addEventListener('click', saveAllSettings);
            document.getElementById('hospitalLogoInput').addEventListener('change', handleLogoUpload);

            // Print border live preview
            document.getElementById('printBorderWidth').addEventListener('input', (e) => {
                document.getElementById('borderThicknessLabel').textContent = e.target.value;
                updateBorderPreview();
            });
            document.getElementById('printBorderColor').addEventListener('input', updateBorderPreview);
            document.getElementById('printBorderStyle').addEventListener('change', updateBorderPreview);
            document.getElementById('printBorderEnabled').addEventListener('change', updateBorderPreview);

            // Color preset buttons
            document.querySelectorAll('.border-color-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    document.getElementById('printBorderColor').value = e.target.dataset.color;
                    updateBorderPreview();
                });
            });
        }

        async function handleLogoUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            const validTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!validTypes.includes(file.type)) {
                alert('Please select a valid image file (JPG or PNG)');
                return;
            }

            if (file.size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logoPreview').src = e.target.result;
                document.getElementById('logoPreview').style.display = 'block';
                document.getElementById('noLogoText').style.display = 'none';
                document.getElementById('removeLogoBtn').style.display = 'inline-block';
            };
            reader.readAsDataURL(file);

            const formData = new FormData();
            formData.append('logo', file);

            try {
                const response = await fetch(`${basePath}/api/settings/upload-logo.php`, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) showSuccess('Logo uploaded successfully!');
                else alert('Error uploading logo: ' + result.error);
            } catch (error) {
                alert('Error uploading logo: ' + error.message);
            }
        }

        async function removeLogo() {
            if (!confirm('Are you sure you want to remove the hospital logo?')) return;

            try {
                const response = await fetch(`${basePath}/api/settings/upload-logo.php`, { method: 'DELETE' });
                const result = await response.json();

                if (result.success) {
                    document.getElementById('logoPreview').style.display = 'none';
                    document.getElementById('logoPreview').src = '';
                    document.getElementById('noLogoText').style.display = 'block';
                    document.getElementById('removeLogoBtn').style.display = 'none';
                    document.getElementById('hospitalLogoInput').value = '';
                    showSuccess('Logo removed successfully!');
                } else {
                    alert('Error removing logo: ' + result.error);
                }
            } catch (error) {
                alert('Error removing logo: ' + error.message);
            }
        }

        async function loadCurrentLogo() {
            try {
                const response = await fetch(`${basePath}/api/settings/upload-logo.php`);
                const result = await response.json();

                if (result.success && result.logo_path) {
                    const logoPreview = document.getElementById('logoPreview');
                    const fullLogoPath = `${basePath}/${result.logo_path}`;

                    logoPreview.src = fullLogoPath + '?t=' + Date.now();
                    logoPreview.onload = function() {
                        logoPreview.style.display = 'block';
                        document.getElementById('noLogoText').style.display = 'none';
                        document.getElementById('removeLogoBtn').style.display = 'inline-block';
                    };
                    logoPreview.onerror = function() {
                        logoPreview.style.display = 'none';
                        document.getElementById('noLogoText').style.display = 'block';
                        document.getElementById('removeLogoBtn').style.display = 'none';
                    };
                }
            } catch (error) {
                console.error('Error loading logo:', error);
            }
        }

        function showSuccess(message) {
            const indicator = document.getElementById('saveIndicator');
            indicator.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${message}`;
            indicator.style.display = 'block';
            setTimeout(() => { indicator.style.display = 'none'; }, 3000);
        }

        // ============================================
        // Hospital Printers Management
        // ============================================

        let systemPrinters = [];
        let hospitalPrinters = [];
        let addPrinterModal = null;

        // Initialize printer section
        async function initPrinterSection() {
            addPrinterModal = new bootstrap.Modal(document.getElementById('addPrinterModal'));

            // Check if running in Electron
            if (window.electronAPI && window.electronAPI.isElectron) {
                document.getElementById('electronPrinterSection').style.display = 'block';
                await loadSystemPrinters();
            }

            await loadHospitalPrinters();

            // Event listeners
            document.getElementById('addPrinterBtn').addEventListener('click', openAddPrinterModal);
            document.getElementById('savePrinterBtn').addEventListener('click', saveNewPrinter);
            document.getElementById('printerSelect').addEventListener('change', onPrinterSelectChange);
        }

        // Load system printers (Electron only)
        async function loadSystemPrinters() {
            const listEl = document.getElementById('systemPrintersList');

            if (!window.electronAPI || !window.electronAPI.getSystemPrinters) {
                listEl.innerHTML = '<div class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Printer detection not available (requires Electron)</div>';
                return;
            }

            try {
                const result = await window.electronAPI.getSystemPrinters();
                if (result.success && result.printers) {
                    systemPrinters = result.printers;
                    renderSystemPrinters();
                } else {
                    listEl.innerHTML = `<div class="text-danger"><i class="bi bi-x-circle me-2"></i>Error: ${result.error || 'Failed to detect printers'}</div>`;
                }
            } catch (error) {
                console.error('Error detecting printers:', error);
                listEl.innerHTML = `<div class="text-danger"><i class="bi bi-x-circle me-2"></i>Error: ${error.message}</div>`;
            }
        }

        // Render system printers
        function renderSystemPrinters() {
            const listEl = document.getElementById('systemPrintersList');

            if (systemPrinters.length === 0) {
                listEl.innerHTML = '<div class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>No printers detected on this system</div>';
                return;
            }

            const html = systemPrinters.map(p => `
                <div class="d-flex align-items-center p-2 border border-secondary rounded mb-2">
                    <i class="bi bi-printer-fill ${p.isDefault ? 'text-primary' : 'text-muted'} me-3 fs-5"></i>
                    <div class="flex-grow-1">
                        <strong class="text-light">${p.displayName || p.name}</strong>
                        ${p.isDefault ? '<span class="badge bg-primary ms-2">System Default</span>' : ''}
                        <div class="text-muted small">${p.name}</div>
                    </div>
                </div>
            `).join('');

            listEl.innerHTML = html;
        }

        // Load hospital printers from API
        async function loadHospitalPrinters() {
            try {
                const response = await fetch(`${basePath}/api/settings/hospital-printers.php?include_inactive=true`);
                const data = await response.json();

                if (data.success) {
                    hospitalPrinters = data.printers || [];
                    renderHospitalPrinters();
                }
            } catch (error) {
                console.error('Error loading hospital printers:', error);
            }
        }

        // Render hospital printers list
        function renderHospitalPrinters() {
            const listEl = document.getElementById('hospitalPrintersList');

            if (hospitalPrinters.length === 0) {
                listEl.innerHTML = `
                    <div class="text-muted text-center py-4">
                        <i class="bi bi-printer fs-1 d-block mb-2 text-secondary"></i>
                        No printers configured. Click "Add Printer" to get started.
                    </div>
                `;
                return;
            }

            const html = hospitalPrinters.map(p => {
                const isOnSystem = systemPrinters.some(sp =>
                    sp.name.toLowerCase() === p.printer_name.toLowerCase()
                );

                return `
                    <div class="d-flex align-items-center p-3 border ${p.is_active ? 'border-secondary' : 'border-warning'} rounded mb-2" style="background: rgba(255,255,255,0.03);">
                        <i class="bi bi-printer-fill ${isOnSystem ? 'text-success' : 'text-warning'} me-3 fs-4"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center">
                                <strong class="text-light">${p.display_name || p.printer_name}</strong>
                                ${p.is_default == 1 ? '<span class="badge bg-primary ms-2">Default</span>' : ''}
                                ${!p.is_active ? '<span class="badge bg-warning ms-2">Inactive</span>' : ''}
                                ${!isOnSystem ? '<span class="badge bg-warning ms-2">Not Found</span>' : ''}
                            </div>
                            <div class="text-muted small">
                                ${p.printer_name}
                                ${p.description ? ` - ${p.description}` : ''}
                                ${p.location_name ? ` <i class="bi bi-geo-alt"></i> ${p.location_name}` : ''}
                            </div>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-sm ${p.is_default ? 'btn-primary' : 'btn-outline-primary'}" onclick="setDefaultPrinter(${p.id})" title="Set as default">
                                <i class="bi bi-star${p.is_default == 1 ? '-fill' : ''}"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="removePrinter(${p.id})" title="Remove">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');

            listEl.innerHTML = html;
        }

        // Open add printer modal
        function openAddPrinterModal() {
            const selectEl = document.getElementById('printerSelect');

            // Populate options with system printers (if available) and option to type manually
            let options = '<option value="">-- Select a printer --</option>';

            if (systemPrinters.length > 0) {
                options += '<optgroup label="Detected System Printers">';
                systemPrinters.forEach(p => {
                    const alreadyAdded = hospitalPrinters.some(hp => hp.printer_name.toLowerCase() === p.name.toLowerCase());
                    options += `<option value="${p.name}" ${alreadyAdded ? 'disabled' : ''}>${p.displayName || p.name}${alreadyAdded ? ' (already added)' : ''}</option>`;
                });
                options += '</optgroup>';
            }

            options += '<option value="__custom__">Enter printer name manually...</option>';

            selectEl.innerHTML = options;

            // Clear form
            document.getElementById('printerDisplayName').value = '';
            document.getElementById('printerDescription').value = '';
            document.getElementById('printerIsDefault').checked = false;

            addPrinterModal.show();
        }

        // Handle printer selection change
        function onPrinterSelectChange(e) {
            const value = e.target.value;

            if (value === '__custom__') {
                // Replace select with text input
                const container = e.target.parentElement;
                e.target.style.display = 'none';
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control bg-dark text-light border-secondary';
                input.id = 'printerNameInput';
                input.placeholder = 'Enter exact printer name';
                container.appendChild(input);
            } else if (value) {
                // Auto-fill display name
                const printer = systemPrinters.find(p => p.name === value);
                if (printer) {
                    document.getElementById('printerDisplayName').value = printer.displayName || printer.name;
                }
            }
        }

        // Save new printer
        async function saveNewPrinter() {
            const selectEl = document.getElementById('printerSelect');
            const customInput = document.getElementById('printerNameInput');
            const printerName = customInput ? customInput.value : selectEl.value;
            const displayName = document.getElementById('printerDisplayName').value;
            const description = document.getElementById('printerDescription').value;
            const isDefault = document.getElementById('printerIsDefault').checked;

            if (!printerName || printerName === '__custom__') {
                alert('Please select or enter a printer name');
                return;
            }

            try {
                const response = await fetch(`${basePath}/api/settings/hospital-printers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        printer_name: printerName,
                        display_name: displayName || printerName,
                        description: description,
                        is_default: isDefault,
                        is_active: true
                    })
                });

                const data = await response.json();

                if (data.success) {
                    addPrinterModal.hide();
                    await loadHospitalPrinters();
                    showSuccess('Printer added successfully!');
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error saving printer:', error);
                alert('Error: ' + error.message);
            }
        }

        // Set printer as default
        async function setDefaultPrinter(printerId) {
            try {
                const printer = hospitalPrinters.find(p => p.id == printerId);
                if (!printer) return;

                const response = await fetch(`${basePath}/api/settings/hospital-printers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: printerId,
                        printer_name: printer.printer_name,
                        display_name: printer.display_name,
                        description: printer.description,
                        is_default: true,
                        is_active: printer.is_active
                    })
                });

                const data = await response.json();

                if (data.success) {
                    await loadHospitalPrinters();
                    showSuccess('Default printer updated!');
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error setting default printer:', error);
            }
        }

        // Remove printer
        async function removePrinter(printerId) {
            if (!confirm('Remove this printer from the authorized list?')) return;

            try {
                const response = await fetch(`${basePath}/api/settings/hospital-printers.php`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: printerId })
                });

                const data = await response.json();

                if (data.success) {
                    await loadHospitalPrinters();
                    showSuccess('Printer removed successfully!');
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error removing printer:', error);
            }
        }

        // Initialize printer section on page load
        document.addEventListener('DOMContentLoaded', initPrinterSection);
    </script>
</body>
</html>
<?php
// Start session and check authentication
define('DICOM_VIEWER', true);
require_once __DIR__ . '/auth/session.php';

// Redirect to login if not authenticated
requireLogin();

// Redirect to dashboard if no study selected (optional, but good UX)
if (empty($_GET['study_id']) && empty($_GET['series_id']) && empty($_GET['studyUID']) && empty($_GET['orthancId'])) {
    // Check if we are just landing here or if we want to show empty viewer
    // For now, let's redirect to dashboard to pick a study
    header('Location: dashboard.php');
    exit;
}

// BASE_PATH and BASE_URL are now defined in config.php (loaded via session.php)

// Get user info
$userName = $_SESSION['username'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'viewer';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title>Accurate Viewer</title>

    <link href="<?= BASE_PATH ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/ai-styles.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/medical-report-styles.css">
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/drag-drop-styles.css">
    <style>
        /* Mobile-First Responsive Enhancements */
        body {
            overscroll-behavior: none;
            -webkit-overflow-scrolling: touch;
        }

        /* Hide complex controls on mobile */
        @media (max-width: 767px) {
            .navbar-brand span {
                display: none;
            }
            .navbar-brand::after {
                content: "Accurate";
                font-size: 1.1rem;
                font-weight: bold;
            }
            #uploadForm, #exportBtn, #settingsBtn {
                display: none !important;
            }
            .sidebar:last-child {
                display: none !important;
            }
            .mpr-controls {
                display: none !important;
            }
        }

        /* Mobile header optimization */
        @media (max-width: 767px) {
            header {
                height: 48px !important;
                padding: 0.25rem !important;
            }
            header .container-fluid {
                padding: 0 0.5rem;
            }
            .main-layout {
                height: calc(100vh - 48px) !important;
            }
        }

        /* Mobile sidebar - collapsible */
        @media (max-width: 767px) {
            .sidebar:first-child {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 1000;
                max-height: 50vh;
                background: #1c2128;
                border-top: 2px solid #0d6efd;
                transition: transform 0.3s ease;
            }
            .sidebar:first-child.collapsed {
                transform: translateY(calc(100% - 50px));
            }
            .sidebar-toggle-btn {
                display: block;
                width: 100%;
                background: #0d6efd;
                border: none;
                color: white;
                padding: 0.75rem;
                font-weight: bold;
                text-align: center;
            }
            .series-list-container {
                max-height: calc(50vh - 100px);
            }
        }

        /* Mobile viewport */
        @media (max-width: 767px) {
            .viewport-container {
                height: calc(100vh - 48px) !important;
                grid-template-columns: 1fr !important;
                grid-template-rows: 1fr !important;
                padding: 0 !important;
                gap: 0 !important;
            }
            .viewport-container.layout-2x2,
            .viewport-container.layout-2x1 {
                grid-template-columns: 1fr !important;
                grid-template-rows: 1fr !important;
            }
        }

        /* Image Thumbnail Selector */
        .image-thumbnails {
            display: none;
            position: fixed;
            bottom: 60px;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.9);
            padding: 10px;
            z-index: 999;
            overflow-x: auto;
            white-space: nowrap;
            border-top: 2px solid #0d6efd;
        }
        .image-thumbnails.show {
            display: block;
        }
        .thumbnail-item {
            display: inline-block;
            width: 80px;
            height: 80px;
            margin: 0 5px;
            border: 2px solid #444;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            background: #000;
        }
        .thumbnail-item.active {
            border-color: #0d6efd;
            box-shadow: 0 0 10px #0d6efd;
        }
        .thumbnail-item img,
        .thumbnail-item canvas {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .thumbnail-number {
            position: absolute;
            top: 2px;
            left: 2px;
            background: rgba(13,110,253,0.8);
            color: white;
            padding: 2px 6px;
            font-size: 0.7rem;
            border-radius: 3px;
        }

        /* Mobile Tools - Bottom Bar */
        @media (max-width: 767px) {
            .mobile-tools-bar {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(28,33,40,0.95);
                backdrop-filter: blur(10px);
                display: flex;
                justify-content: space-around;
                align-items: center;
                padding: 0.5rem;
                border-top: 1px solid #0d6efd;
                z-index: 1001;
            }
            .mobile-tools-bar button {
                flex: 1;
                margin: 0 3px;
                padding: 0.5rem;
                border: none;
                background: #1c2128;
                color: white;
                border-radius: 8px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                font-size: 0.75rem;
            }
            .mobile-tools-bar button.active {
                background: #0d6efd;
                box-shadow: 0 0 10px rgba(13,110,253,0.5);
            }
            .mobile-tools-bar button i {
                font-size: 1.2rem;
                margin-bottom: 2px;
            }
        }

        /* Ordered Selection Styles */
        .selection-badge {
            position: absolute;
            top: 4px;
            right: 4px;
            background: #ffc107; /* Warning yellow/orange for high visibility on dark x-rays */
            color: #000;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-family: sans-serif;
            font-size: 14px;
            border: 2px solid #fff;
            z-index: 100;
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
            pointer-events: none; /* Let clicks pass through if needed */
        }
        
        .series-item.selection-mode {
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .series-item.selected-order {
            border: 2px solid #ffc107 !important; /* Match badge color */
            background-color: rgba(255, 193, 7, 0.1) !important;
        }
        
        .selection-mode-active #selectModeBtn {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #000;
        }

        /* Fullscreen mode */
        .fullscreen-mode {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            background: #000;
        }
        .fullscreen-mode .viewport-container {
            height: 100vh !important;
        }
        .fullscreen-exit-btn {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 10000;
            background: rgba(13,110,253,0.8);
            border: none;
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 1.2rem;
        }

        /* Viewport touch improvements */
        .viewport {
            touch-action: none;
            -webkit-user-select: none;
            user-select: none;
        }

        /* Loading indicator */
        .loading-progress {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 10001;
            background: rgba(0,0,0,0.9);
            padding: 20px 30px;
            border-radius: 10px;
            border: 2px solid #0d6efd;
        }

        /* Desktop - keep sidebar visible */
        @media (min-width: 768px) {
            .mobile-tools-bar {
                display: none;
            }
            .image-thumbnails {
                display: none;
            }
            .sidebar-toggle-btn {
                display: none;
            }
        }
        
        /* Sidebar collapse styles (#15) - Fixed */
        .sidebar {
            position: relative;
            width: 250px;
            min-width: 250px;
            max-width: 250px;
            transition: width 0.3s ease, min-width 0.3s ease, max-width 0.3s ease, opacity 0.3s ease;
            flex-shrink: 0;
            overflow-x: hidden;
        }
        .sidebar.sidebar-hidden {
            width: 0 !important;
            min-width: 0 !important;
            max-width: 0 !important;
            padding: 0 !important;
            overflow: hidden;
            border: none !important;
            opacity: 0;
        }
        .sidebar.sidebar-hidden > * {
            display: none !important;
        }
        
        /* Float toggle buttons - positioned on sidebar borders */
        .sidebar-toggle-float {
            position: fixed;
            z-index: 1100;
            width: 20px;
            height: 50px;
            background: #1e2530;
            border: 1px solid #3a4556;
            color: #8892a0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
            font-size: 12px;
        }
        .sidebar-toggle-float:hover {
            background: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }
        .sidebar-toggle-float i {
            transition: transform 0.3s ease;
        }
        #toggleLeftSidebar {
            top: 50%;
            transform: translateY(-50%);
            border-radius: 0 6px 6px 0;
            border-left: none;
        }
        #toggleRightSidebar {
            top: 50%;
            transform: translateY(-50%);
            border-radius: 6px 0 0 6px;
            border-right: none;
        }
        
        /* Ensure main content fills space when sidebars hidden */
        .main-layout {
            display: flex;
            transition: all 0.3s ease;
        }
        
        /* Main content should expand when sidebars are hidden */
        #main-content {
            flex: 1;
            min-width: 0;
            transition: all 0.3s ease;
            position: relative; /* Added to anchor absolute positioned children like report panel */
        }
    </style>

    <!-- PDF Export Libraries (Local) -->
    <script src="<?= BASE_PATH ?>/assets/vendor/pdf/html2canvas.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/vendor/pdf/jspdf.umd.min.js"></script>

    <!-- DICOM Libraries (Local) -->
    <script src="<?= BASE_PATH ?>/assets/vendor/dicom/dicomParser.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/vendor/cornerstone/cornerstone.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/vendor/cornerstone/cornerstoneMath.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/vendor/cornerstone/hammer.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/vendor/cornerstone/cornerstoneWADOImageLoader.min.js"></script>
    <script src="<?= BASE_PATH ?>/assets/vendor/cornerstone/cornerstoneTools.min.js"></script>
    <script>
        // CRITICAL FIX: Image loading for remote storage with BASE_PATH support
        window.DICOM_VIEWER = window.DICOM_VIEWER || {};
        window.DICOM_VIEWER.getImageUrl = function (image) {
            if (!image) return null;

            const basePath = '<?= BASE_PATH ?>';
            const baseUrl = '<?= BASE_URL ?>';

            if (image.isOrthancImage && image.orthancInstanceId) {
                const instanceId = image.orthancInstanceId;
                // Use direct Orthanc proxy endpoint
                return `wadouri:${basePath}/api/get_dicom_from_orthanc.php?instanceId=${instanceId}`;
            }

            if (image.instanceId) {
                return `wadouri:${basePath}/api/get_dicom_from_orthanc.php?instanceId=${image.instanceId}`;
            }

            if (image.id) {
                return `wadouri:${basePath}/api/get_dicom_from_orthanc.php?instanceId=${image.id}`;
            }

            return null;
        };
        console.log('Image URL helper loaded with BASE_PATH:', '<?= BASE_PATH ?>');
    </script>
</head>

<body>
    <div id="loadingProgress" class="loading-progress" style="display: none;">
        <div class="d-flex align-items-center">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
            <span>Loading images...</span>
        </div>
    </div>

    <header class="navbar navbar-expand-lg bg-body-tertiary border-bottom" style="height: 58px;">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="<?= BASE_PATH ?>/">
                <i class="bi bi-heart-pulse-fill text-primary fs-4 me-2"></i>
                <span class="fw-semibold">Accurate Viewer</span>
            </a>
            
            <!-- Patient Info in Navbar -->
            <div id="navbar-patient-info" class="d-flex align-items-center gap-2 ms-3" style="display: none;">
                <span class="badge bg-dark border border-secondary d-flex align-items-center gap-1">
                    <i class="bi bi-person-fill text-primary"></i>
                    <span id="nav-patient-name">-</span>
                </span>
                <span class="badge bg-dark border border-secondary" id="nav-age-badge">
                    <i class="bi bi-calendar3 text-info me-1"></i>
                    <span id="nav-patient-age">-</span>
                </span>
                <span class="badge bg-dark border border-secondary" id="nav-sex-badge">
                    <i class="bi bi-gender-ambiguous text-warning me-1" id="nav-sex-icon"></i>
                    <span id="nav-patient-sex">-</span>
                </span>
                <span class="badge bg-dark border border-secondary">
                    <small>ID:</small> <span id="nav-patient-id">-</span>
                </span>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <form id="uploadForm" enctype="multipart/form-data" class="m-0" style="display: none;">
                    <input type="file" id="dicomFileInput" name="dicomFile" class="d-none" accept=".dcm,.dicom"
                        multiple>
                    <input type="file" id="dicomFolderInput" name="dicomFolder" class="d-none" webkitdirectory directory
                        multiple>
                </form>
                <div class="btn-group">
                    <button class="btn btn-primary" id="exportBtn"><i class="bi bi-download me-2"></i>Export</button>
                    <button class="btn btn-primary dropdown-toggle dropdown-toggle-split"
                        data-bs-toggle="dropdown"></button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" id="exportImage"><i class="bi bi-file-image me-2"></i>Export as Image</a></li>
                        <li><a class="dropdown-item" href="#" id="exportPDF"><i class="bi bi-file-pdf me-2"></i>Export as PDF</a></li>
                        <li><a class="dropdown-item" href="#" id="exportDicom"><i class="bi bi-file-earmark-medical me-2"></i>Export DICOM</a></li>
                        <li><a class="dropdown-item" href="#" id="exportMPR"><i class="bi bi-grid-3x3 me-2"></i>Export MPR Views</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="#" id="createMedicalReport">
                                <i class="bi bi-file-medical me-2"></i>Create Medical Report
                            </a></li>
                        <li><a class="dropdown-item" href="#" id="exportReport"><i class="bi bi-file-text me-2"></i>Export Report</a></li>
                    </ul>
                </div>
                <button class="btn btn-info" id="medicalReportBtn" title="Medical Report"><i class="bi bi-file-medical me-1"></i>Report</button>

                <button class="btn btn-secondary" id="printBtn" title="Print DICOM Image"><i class="bi bi-printer me-1"></i>Print</button>
                <button class="btn btn-secondary" id="settingsBtn" title="Settings"><i class="bi bi-gear me-1"></i>Settings</button>
                <button class="btn btn-secondary" id="fullscreenBtn" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
            </div>
        </div>
    </header>

    <div class="main-layout">
        <aside class="sidebar bg-body-tertiary border-end" id="leftSidebar">
            <button class="sidebar-toggle-btn" onclick="document.getElementById('leftSidebar').classList.toggle('collapsed')">
                <i class="bi bi-list"></i> Series & Controls
            </button>
            <div class="sidebar-section"
                style="padding: 0.75rem; flex-shrink: 0; border-bottom: 1px solid var(--bs-border-color);">
                <h6 class="text-light mb-0">Series Navigation</h6>
            </div>

            <div class="series-list-container large-thumbnails" id="series-list">
                <div class="text-center text-muted small p-4">
                    No DICOM files uploaded
                </div>
            </div>

            <div class="sidebar-section fixed-section navigation-section">
                <h6 class="text-light mb-2">Image Navigation</h6>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <button class="btn btn-sm btn-secondary" id="prevImage">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span class="small text-muted flex-fill text-center" id="imageCounter">- / -</span>
                    <button class="btn btn-sm btn-secondary" id="nextImage">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <input type="range" class="form-range" id="imageSlider" min="0" max="0" value="0">
            </div>

            <div class="sidebar-section fixed-section cine-section">
                <h6 class="text-light mb-2">Cine Controls</h6>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-secondary" id="playPause">
                        <i class="bi bi-play-fill"></i>
                    </button>
                    <button class="btn btn-sm btn-secondary" id="stopCine">
                        <i class="bi bi-stop-fill"></i>
                    </button>
                    <small class="text-muted">FPS:</small>
                    <input type="range" class="form-range flex-fill" id="fpsSlider" min="1" max="30" value="10">
                    <small class="text-muted" id="fpsDisplay">10</small>
                </div>
            </div>
        </aside>

        <main id="main-content" class="d-flex flex-column" style="background-color: #000; overflow: hidden;">
            <div class="mpr-controls" style="overflow: visible; z-index: 1000;">
                <div class="top-controls-bar">
                    <div class="d-flex justify-content-center align-items-center w-100" style="gap: 6px; flex-wrap: nowrap; overflow-x: auto; padding: 4px 8px;">
                        <!-- Layout Button - Opens Modal -->
                        <button class="btn btn-sm btn-primary layout-modal-btn" type="button" data-bs-toggle="modal" data-bs-target="#layoutSelectorModal">
                            <i class="bi bi-grid-fill me-1"></i><span id="layoutDropdownText">4 Spots</span>
                        </button>

                        <!-- MPR buttons - Compact -->
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-success btn-mpr" id="mprAxial" title="Axial View">Ax</button>
                            <button type="button" class="btn btn-outline-success btn-mpr" id="mprSagittal" title="Sagittal View">Sag</button>
                            <button type="button" class="btn btn-outline-success btn-mpr" id="mprCoronal" title="Coronal View">Cor</button>
                        </div>

                        <!-- Insert/Clear/Delete buttons -->
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-success" id="insertAllBtn" title="Insert All Images"><i class="bi bi-grid-fill"></i></button>
                            <button class="btn btn-danger" id="clearAllBtn" title="Delete Selected (or All if none selected)"><i class="bi bi-trash"></i></button>
                        </div>

                        <!-- Select/Arrange buttons -->
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-info" id="selectAllBtn" title="Select All (Ctrl+A)"><i class="bi bi-check2-square"></i></button>
                            <button class="btn btn-outline-primary" id="selectModeBtn" title="Selection Mode"><i class="bi bi-list-check"></i></button>
                            <button class="btn btn-primary" id="arrangeBtn" title="Arrange Selected" style="display:none;"><i class="bi bi-sort-numeric-down"></i></button>
                        </div>

                        <!-- Reset button -->
                        <button class="btn btn-sm btn-outline-warning" id="resetViewportBtn" title="Reset Viewport">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div id="viewport-container" class="viewport-container layout-spots-4">
                <div class="card bg-dark text-light text-center">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h5 class="card-title text-muted">No DICOM file selected</h5>
                        <p class="card-text text-muted small">Upload and select a DICOM file to begin viewing with
                            automatic MPR reconstruction</p>
                    </div>
                </div>
            </div>
        </main>

        <aside class="sidebar bg-body-tertiary border-start" id="rightSidebar">
            <div class="p-3 border-bottom">
                <h6 class="text-light mb-2">Tools</h6>
                <div class="row row-cols-3 g-1" id="tools-panel">
                    <div class="col"><button data-tool="Pan"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-arrows-move"></i><span class="small">Pan</span></button></div>
                    <div class="col"><button data-tool="Zoom"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-zoom-in"></i><span class="small">Zoom</span></button></div>
                    <div class="col"><button data-tool="Wwwc"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-sliders"></i><span class="small">W/L</span></button></div>
                    <div class="col"><button data-tool="Length"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-rulers"></i><span class="small">Length</span></button></div>
                    <div class="col"><button data-tool="Angle"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-triangle"></i><span class="small">Angle</span></button></div>
                    <div class="col"><button data-tool="FreehandRoi"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-pencil"></i><span class="small">Draw</span></button></div>
                    <div class="col"><button data-tool="EllipticalRoi"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-circle"></i><span class="small">Circle</span></button></div>
                    <div class="col"><button data-tool="RectangleRoi"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-square"></i><span class="small">Rectangle</span></button></div>
                    <div class="col"><button data-tool="Probe"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-eyedropper"></i><span class="small">Probe</span></button></div>
                    <div class="col"><button id="textAnnotationBtn" data-tool="TextAnnotation"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center" title="Add Text Annotation (T)"><i
                                class="bi bi-fonts"></i><span class="small">Text</span></button></div>

                    <div class="col"><button id="invertBtn"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-circle-half"></i><span class="small">Invert</span></button></div>
                    <div class="col"><button id="flipHBtn"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-arrow-left-right"></i><span class="small">Flip H</span></button></div>
                    <div class="col"><button id="flipVBtn"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-arrow-down-up"></i><span class="small">Flip V</span></button></div>
                    <div class="col"><button id="rotateLeftBtn"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-arrow-counterclockwise"></i><span class="small">Rotate L</span></button></div>
                    <div class="col"><button id="rotateRightBtn"
                            class="btn btn-secondary w-100 tool-btn d-flex flex-column justify-content-center align-items-center"><i
                                class="bi bi-arrow-clockwise"></i><span class="small">Rotate R</span></button></div>
                </div>
            </div>

            <div class="sidebar-scrollable">
                <div class="p-3 border-bottom">
                    <h6 class="text-light mb-2">Window/Level Presets</h6>
                    <div class="d-grid gap-1">
                        <button class="btn btn-sm btn-outline-secondary preset-btn"
                            data-preset="default">Default</button>
                        <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="lung">Lung
                            (-600/1500)</button>
                        <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="abdomen">Abdomen
                            (50/400)</button>
                        <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="brain">Brain
                            (40/80)</button>
                        <button class="btn btn-sm btn-outline-secondary preset-btn" data-preset="bone">Bone
                            (400/1000)</button>
                    </div>

                    <div class="mt-3">
                        <label class="form-label small text-light mb-1">Window Width</label>
                        <input type="range" class="form-range" id="windowSlider" min="1" max="4000" value="400">
                        <small class="text-muted" id="windowValue">400</small>
                    </div>
                    <div class="mt-2">
                        <label class="form-label small text-light mb-1">Window Level</label>
                        <input type="range" class="form-range" id="levelSlider" min="-1000" max="1000" value="40">
                        <small class="text-muted" id="levelValue">40</small>
                    </div>
                </div>

            </div>
        </aside>
    </div>
    
    <!-- Sidebar Toggle Buttons (Fixed Position) -->
    <button class="sidebar-toggle-float" id="toggleLeftSidebar" title="Toggle left sidebar (H)">
        <i class="bi bi-chevron-left" id="leftSidebarIcon"></i>
    </button>
    <button class="sidebar-toggle-float" id="toggleRightSidebar" title="Toggle right sidebar">
        <i class="bi bi-chevron-right" id="rightSidebarIcon"></i>
    </button>

    <!-- Mobile Tools Bar -->
    <div class="mobile-tools-bar">
        <button id="mobilePanTool" data-tool="Pan">
            <i class="bi bi-arrows-move"></i>
            <span>Pan</span>
        </button>
        <button id="mobileZoomTool" data-tool="Zoom">
            <i class="bi bi-zoom-in"></i>
            <span>Zoom</span>
        </button>
        <button id="mobileWLTool" data-tool="Wwwc" class="active">
            <i class="bi bi-sliders"></i>
            <span>W/L</span>
        </button>
        <button id="mobileImagesList">
            <i class="bi bi-grid-3x3"></i>
            <span>Images</span>
        </button>
        <button id="mobileFullscreen">
            <i class="bi bi-arrows-fullscreen"></i>
            <span>Full</span>
        </button>
    </div>

    <!-- Image Thumbnails Selector -->
    <div class="image-thumbnails" id="imageThumbnails">
        <!-- Thumbnails will be populated here -->
    </div>

    <script src="<?= BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Load utilities first -->
    <script src="<?= BASE_PATH ?>/js/utils/constants.js"></script>
    <script src="<?= BASE_PATH ?>/js/utils/cornerstone-init.js"></script>

    <!-- Load managers -->
    <script src="<?= BASE_PATH ?>/js/managers/enhancement-manager.js"></script>
    <script src="<?= BASE_PATH ?>/js/managers/crosshair-manager.js"></script>
    <script src="<?= BASE_PATH ?>/js/managers/viewport-manager.js"></script>
    <script src="<?= BASE_PATH ?>/js/managers/custom-grid-layout-manager.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/managers/advanced-layout-manager.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/managers/viewport-actions-manager.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/managers/mpr-manager.js"></script>
    <script src="<?= BASE_PATH ?>/js/managers/reference-lines-manager.js"></script>


    <script src="<?= BASE_PATH ?>/js/components/upload-handler.js"></script>
    <script src="<?= BASE_PATH ?>/js/components/ui-controls.js"></script>
    <script src="<?= BASE_PATH ?>/js/components/event-handlers.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/reporting-system.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/mouse-controls.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/export-manager.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/print-manager-v3.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/image-overview-modal.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/viewer-page-navigator.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/text-annotation-tool.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/settings-manager.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/mobile-controls.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/mpr-button-handlers.js?v=<?= time() ?>"></script>


    <!-- DICOM Parser already loaded above -->


    <!-- Load main application -->
    <script src="<?= BASE_PATH ?>/js/main.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/viewport-badge-updater.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/orthanc-autoload.js"></script>
    <script src="<?= BASE_PATH ?>/assets/js/ai-integration.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/assets/js/medical-report-generator.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/ocr-measurement-extractor.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_PATH ?>/js/components/advanced-reporting-system.js?v=<?= time() ?>"></script>

    <script>
        // Fix sidebar visibility on load
        document.addEventListener('DOMContentLoaded', function() {
            const leftSidebar = document.getElementById('leftSidebar');
            const isMobile = window.innerWidth < 768;

            if (isMobile && leftSidebar) {
                // Collapse sidebar on mobile by default
                leftSidebar.classList.add('collapsed');
            } else if (leftSidebar) {
                // Ensure sidebar is visible on desktop
                leftSidebar.classList.remove('collapsed');
            }

            // Check for report existence and show indicator
            checkReportExistence();
        });

        // Function to check if a report exists for the current study
        async function checkReportExistence() {
            const urlParams = new URLSearchParams(window.location.search);
            const studyUID = urlParams.get('studyUID');

            if (!studyUID) {
                console.log('No studyUID in URL, skipping report check');
                return;
            }

            try {
                const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
                const response = await fetch(`${basePath}/api/reports/by-study.php?studyUID=${encodeURIComponent(studyUID)}`);
                const data = await response.json();

                const reportIndicator = document.getElementById('report-indicator');
                if (data.success && data.data && data.data.count > 0) {
                    // Report exists - show indicator
                    if (reportIndicator) {
                        reportIndicator.style.display = 'block';
                        const report = data.data.reports[0];
                        const statusBadge = reportIndicator.querySelector('.badge');

                        // Update badge based on status
                        if (report.status === 'final') {
                            statusBadge.className = 'badge bg-success d-flex align-items-center gap-1';
                            statusBadge.innerHTML = '<i class="bi bi-file-earmark-check-fill"></i> Report (Final)';
                        } else if (report.status === 'printed') {
                            statusBadge.className = 'badge bg-info d-flex align-items-center gap-1';
                            statusBadge.innerHTML = '<i class="bi bi-printer-fill"></i> Report (Printed)';
                        } else {
                            statusBadge.className = 'badge bg-warning d-flex align-items-center gap-1';
                            statusBadge.innerHTML = '<i class="bi bi-file-earmark-text-fill"></i> Report (Draft)';
                        }
                    }
                } else {
                    // No report - hide indicator
                    if (reportIndicator) {
                        reportIndicator.style.display = 'none';
                    }
                }
            } catch (error) {
                console.error('Error checking report existence:', error);
                // Hide indicator on error
                const reportIndicator = document.getElementById('report-indicator');
                if (reportIndicator) {
                    reportIndicator.style.display = 'none';
                }
            }
        }

        // Make checkReportExistence globally available so it can be called after report save
        window.checkReportExistence = checkReportExistence;
        
        // Sidebar toggle functionality (#15) - Fixed positioning
        initializeSidebarToggles();
        
        function initializeSidebarToggles() {
            const leftSidebar = document.getElementById('leftSidebar');
            const rightSidebar = document.getElementById('rightSidebar');
            const toggleLeft = document.getElementById('toggleLeftSidebar');
            const toggleRight = document.getElementById('toggleRightSidebar');
            const leftIcon = document.getElementById('leftSidebarIcon');
            const rightIcon = document.getElementById('rightSidebarIcon');
            
            const SIDEBAR_WIDTH = 250; // Fixed sidebar width
            
            // Load saved preferences
            const leftHidden = localStorage.getItem('leftSidebarHidden') === 'true';
            const rightHidden = localStorage.getItem('rightSidebarHidden') === 'true';
            
            // Apply saved state
            if (leftHidden && leftSidebar) {
                leftSidebar.classList.add('sidebar-hidden');
            }
            if (rightHidden && rightSidebar) {
                rightSidebar.classList.add('sidebar-hidden');
            }
            
            // Initial button positioning
            updateAllButtonPositions();
            
            function updateAllButtonPositions() {
                const leftIsHidden = leftSidebar?.classList.contains('sidebar-hidden');
                const rightIsHidden = rightSidebar?.classList.contains('sidebar-hidden');
                
                if (toggleLeft) {
                    toggleLeft.style.left = leftIsHidden ? '0px' : SIDEBAR_WIDTH + 'px';
                }
                if (leftIcon) {
                    leftIcon.style.transform = leftIsHidden ? 'rotate(180deg)' : 'rotate(0deg)';
                }
                if (toggleRight) {
                    toggleRight.style.right = rightIsHidden ? '0px' : SIDEBAR_WIDTH + 'px';
                }
                if (rightIcon) {
                    rightIcon.style.transform = rightIsHidden ? 'rotate(180deg)' : 'rotate(0deg)';
                }
            }
            
            function resizeViewports() {
                setTimeout(() => {
                    document.querySelectorAll('.viewport').forEach(vp => {
                        try { cornerstone.resize(vp); } catch(e) {}
                    });
                }, 350);
            }
            
            // Toggle left sidebar
            if (toggleLeft && leftSidebar) {
                toggleLeft.addEventListener('click', function(e) {
                    e.stopPropagation();
                    leftSidebar.classList.toggle('sidebar-hidden');
                    const isHidden = leftSidebar.classList.contains('sidebar-hidden');
                    localStorage.setItem('leftSidebarHidden', isHidden);
                    updateAllButtonPositions();
                    resizeViewports();
                });
            }
            
            // Toggle right sidebar
            if (toggleRight && rightSidebar) {
                toggleRight.addEventListener('click', function(e) {
                    e.stopPropagation();
                    rightSidebar.classList.toggle('sidebar-hidden');
                    const isHidden = rightSidebar.classList.contains('sidebar-hidden');
                    localStorage.setItem('rightSidebarHidden', isHidden);
                    updateAllButtonPositions();
                    resizeViewports();
                });
            }
            
            // Keyboard shortcut: 'H' to toggle both sidebars
            document.addEventListener('keydown', function(e) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                
                if (e.key.toLowerCase() === 'h') {
                    e.preventDefault();
                    
                    const leftIsHidden = leftSidebar?.classList.contains('sidebar-hidden');
                    const rightIsHidden = rightSidebar?.classList.contains('sidebar-hidden');
                    
                    if (leftIsHidden && rightIsHidden) {
                        // Both hidden, show both
                        leftSidebar?.classList.remove('sidebar-hidden');
                        rightSidebar?.classList.remove('sidebar-hidden');
                        localStorage.setItem('leftSidebarHidden', 'false');
                        localStorage.setItem('rightSidebarHidden', 'false');
                    } else {
                        // Hide both
                        leftSidebar?.classList.add('sidebar-hidden');
                        rightSidebar?.classList.add('sidebar-hidden');
                        localStorage.setItem('leftSidebarHidden', 'true');
                        localStorage.setItem('rightSidebarHidden', 'true');
                    }
                    
                    updateAllButtonPositions();
                    resizeViewports();
                }
            });
        }

        // Initialize layout dropdown (Spots selector)
        initializeLayoutDropdown();

        function initializeLayoutDropdown() {
            const dropdownItems = document.querySelectorAll('.layout-dropdown-menu .dropdown-item');
            const dropdownText = document.getElementById('layoutDropdownText');
            const viewportContainer = document.getElementById('viewport-container');

            // Layout configurations: spots -> { rows, cols }
            const layoutConfigs = {
                1: { rows: 1, cols: 1 },   // 1x1 - Single
                2: { rows: 1, cols: 2 },   // 1x2 - Landscape
                4: { rows: 2, cols: 2 },   // 2x2 - Landscape
                6: { rows: 3, cols: 2 },   // 3x2 - Portrait
                8: { rows: 4, cols: 2 },   // 4x2 - Portrait
                9: { rows: 3, cols: 3 },   // 3x3 - Landscape
                12: { rows: 4, cols: 3 },  // 4x3 - Portrait
                15: { rows: 5, cols: 3 },  // 5x3 - Portrait
                16: { rows: 4, cols: 4 }   // 4x4 - Landscape
            };

            // Load saved layout preference
            const savedSpots = localStorage.getItem('layoutSpots') || '4';
            applyLayout(parseInt(savedSpots), false);

            dropdownItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const spots = parseInt(this.dataset.spots);
                    applyLayout(spots, true);
                });
            });

            function applyLayout(spots, createViewports = true) {
                const config = layoutConfigs[spots];
                if (!config) return;

                // Update dropdown text
                if (dropdownText) {
                    dropdownText.textContent = spots + (spots === 1 ? ' Spot' : ' Spots');
                }

                // Update active state in dropdown
                dropdownItems.forEach(item => {
                    item.classList.toggle('active', parseInt(item.dataset.spots) === spots);
                });

                // Remove all layout classes
                if (viewportContainer) {
                    viewportContainer.className = viewportContainer.className
                        .split(' ')
                        .filter(c => !c.startsWith('layout-'))
                        .join(' ');

                    // Add new layout class
                    viewportContainer.classList.add(`layout-spots-${spots}`);
                }

                // Save preference
                localStorage.setItem('layoutSpots', spots.toString());

                // Create viewports using the viewport manager
                if (createViewports && window.DICOM_VIEWER && window.DICOM_VIEWER.MANAGERS) {
                    const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
                    const customGridManager = window.DICOM_VIEWER.MANAGERS.customGridManager;

                    if (customGridManager) {
                        customGridManager.applyCustomGrid(config.rows, config.cols);
                    } else if (viewportManager) {
                        // Fallback: generate viewport names and create layout
                        const viewportNames = [];
                        for (let i = 0; i < spots; i++) {
                            viewportNames.push(`viewport-${i + 1}`);
                        }
                        const layoutKey = `spots-${spots}`;
                        viewportManager.layouts[layoutKey] = {
                            rows: config.rows,
                            cols: config.cols,
                            viewports: viewportNames
                        };
                        viewportManager.switchLayout(layoutKey);
                    }
                }

                console.log(`Applied layout: ${spots} spots (${config.rows}x${config.cols})`);
            }

            // Expose for external use
            window.DICOM_VIEWER.applyLayoutSpots = applyLayout;

            // Handle "Custom Layouts..." button click
            const customLayoutBtn = document.getElementById('openCustomGridModal');
            if (customLayoutBtn) {
                customLayoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Close the dropdown
                    const dropdown = document.getElementById('layoutDropdown');
                    if (dropdown) {
                        const bsDropdown = bootstrap.Dropdown.getInstance(dropdown);
                        if (bsDropdown) bsDropdown.hide();
                    }
                    // Open the custom grid modal
                    const modal = document.getElementById('customGridModal');
                    if (modal) {
                        const bsModal = new bootstrap.Modal(modal);
                        bsModal.show();
                    }
                });
            }
        }

        // Auto-generate high-res thumbnails on load (always use large thumbnails)
        setTimeout(() => {
            if (window.DICOM_VIEWER && window.DICOM_VIEWER.thumbnailManager) {
                window.DICOM_VIEWER.thumbnailManager.regenerateLargeThumbnails();
            }
        }, 1000);
    </script>

    <!-- Layout Selector Modal (Quick Layout Selection) -->
    <div id="layoutSelectorModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary py-2" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);">
                    <h6 class="modal-title mb-0"><i class="bi bi-grid-fill me-2 text-primary"></i>Select Layout</h6>
                    <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <!-- Landscape Layouts -->
                    <div class="mb-3">
                        <h6 class="text-muted small mb-2"><i class="bi bi-aspect-ratio me-1"></i>LANDSCAPE</h6>
                        <div class="d-flex flex-wrap gap-2" id="landscapeLayouts">
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="1" data-rows="1" data-cols="1">
                                <div class="layout-preview layout-1x1"></div>
                                <span>1</span>
                            </button>

                            <button class="btn btn-outline-light layout-quick-btn active" data-spots="4" data-rows="2" data-cols="2">
                                <div class="layout-preview layout-2x2"></div>
                                <span>4</span>
                            </button>
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="9" data-rows="3" data-cols="3">
                                <div class="layout-preview layout-3x3"></div>
                                <span>9</span>
                            </button>
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="12" data-rows="3" data-cols="4">
                                <div class="layout-preview layout-3x4"></div>
                                <span>12</span>
                            </button>
                        </div>
                    </div>
                    <!-- Portrait Layouts -->
                    <div class="mb-3">
                        <h6 class="text-muted small mb-2"><i class="bi bi-phone me-1"></i>PORTRAIT</h6>
                        <div class="d-flex flex-wrap gap-2" id="portraitLayouts">
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="2" data-rows="2" data-cols="1">
                                <div class="layout-preview layout-1x2"></div>
                                <span>2</span>
                            </button>
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="6" data-rows="3" data-cols="2">
                                <div class="layout-preview layout-3x2"></div>
                                <span>6</span>
                            </button>
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="8" data-rows="4" data-cols="2">
                                <div class="layout-preview layout-4x2"></div>
                                <span>8</span>
                            </button>
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="15" data-rows="5" data-cols="3">
                                <div class="layout-preview layout-5x3"></div>
                                <span>15</span>
                            </button>
                            <button class="btn btn-outline-light layout-quick-btn" data-spots="18" data-rows="6" data-cols="3">
                                <div class="layout-preview layout-6x3"></div>
                                <span>18</span>
                            </button>
                        </div>
                    </div>
                    <!-- Advanced Layouts Link -->
                    <div class="border-top border-secondary pt-3">
                        <button class="btn btn-outline-info btn-sm w-100" id="openAdvancedLayoutsBtn" data-bs-dismiss="modal">
                            <i class="bi bi-grid-3x3-gap me-2"></i>Advanced Custom Layouts...
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Layout Quick Select Modal Styles */
        .layout-modal-btn {
            min-width: 85px;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .layout-quick-btn {
            width: 60px;
            height: 70px;
            padding: 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .layout-quick-btn:hover {
            background: rgba(13, 110, 253, 0.2);
            border-color: #0d6efd;
        }

        .layout-quick-btn.active {
            background: #0d6efd;
            border-color: #0d6efd;
            color: white;
        }

        .layout-quick-btn span {
            font-size: 0.75rem;
            font-weight: 600;
        }

        .layout-preview {
            width: 40px;
            height: 35px;
            display: grid;
            gap: 1px;
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
            padding: 2px;
        }

        .layout-preview > div {
            background: rgba(255,255,255,0.3);
            border-radius: 1px;
        }

        /* Layout Preview Grids */
        .layout-preview.layout-1x1 { grid-template: 1fr / 1fr; }
        .layout-preview.layout-1x1::before { content: ''; background: rgba(255,255,255,0.3); border-radius: 1px; }

        .layout-preview.layout-1x2 { grid-template: 1fr / 1fr 1fr; }
        .layout-preview.layout-1x2::before, .layout-preview.layout-1x2::after { content: ''; background: rgba(255,255,255,0.3); border-radius: 1px; }

        .layout-preview.layout-2x2 { grid-template: 1fr 1fr / 1fr 1fr; }
        .layout-preview.layout-3x3 { grid-template: 1fr 1fr 1fr / 1fr 1fr 1fr; }
        .layout-preview.layout-4x4 { grid-template: 1fr 1fr 1fr 1fr / 1fr 1fr 1fr 1fr; }
        .layout-preview.layout-3x2 { grid-template: 1fr 1fr 1fr / 1fr 1fr; }
        .layout-preview.layout-4x2 { grid-template: 1fr 1fr 1fr 1fr / 1fr 1fr; }
        .layout-preview.layout-3x4 { grid-template: 1fr 1fr 1fr / 1fr 1fr 1fr 1fr; }
        .layout-preview.layout-4x3 { grid-template: 1fr 1fr 1fr 1fr / 1fr 1fr 1fr; }
        .layout-preview.layout-5x3 { grid-template: 1fr 1fr 1fr 1fr 1fr / 1fr 1fr 1fr; }
        .layout-preview.layout-6x3 { grid-template: 1fr 1fr 1fr 1fr 1fr 1fr / 1fr 1fr 1fr; }
    </style>

    <script>
        // Initialize Layout Selector Modal
        document.addEventListener('DOMContentLoaded', function() {
            // Generate grid cells for each preview
            document.querySelectorAll('.layout-quick-btn').forEach(btn => {
                const rows = parseInt(btn.dataset.rows);
                const cols = parseInt(btn.dataset.cols);
                const preview = btn.querySelector('.layout-preview');
                if (preview) {
                    preview.innerHTML = '';
                    for (let i = 0; i < rows * cols; i++) {
                        const cell = document.createElement('div');
                        preview.appendChild(cell);
                    }
                }
            });

            // Handle layout button clicks
            document.querySelectorAll('.layout-quick-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const spots = parseInt(this.dataset.spots);
                    const rows = parseInt(this.dataset.rows);
                    const cols = parseInt(this.dataset.cols);

                    // Update active state
                    document.querySelectorAll('.layout-quick-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');

                    // Apply layout
                    if (window.DICOM_VIEWER && window.DICOM_VIEWER.MANAGERS && window.DICOM_VIEWER.MANAGERS.customGridManager) {
                        window.DICOM_VIEWER.MANAGERS.customGridManager.applyCustomGrid(rows, cols);
                    }

                    // Update button text
                    const dropdownText = document.getElementById('layoutDropdownText');
                    if (dropdownText) {
                        dropdownText.textContent = spots + (spots === 1 ? ' Spot' : ' Spots');
                    }

                    // Dispatch layout change event so page navigator reloads images
                    document.dispatchEvent(new CustomEvent('layoutChanged', { 
                        detail: { rows, cols, spots, source: 'quickButton' }
                    }));

                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('layoutSelectorModal'));
                    if (modal) modal.hide();
                });
            });

            // Handle advanced layouts button
            document.getElementById('openAdvancedLayoutsBtn')?.addEventListener('click', function() {
                setTimeout(() => {
                    const advancedModal = new bootstrap.Modal(document.getElementById('customGridModal'));
                    advancedModal.show();
                }, 300);
            });
        });
    </script>

    <!-- Custom Grid Selector Modal with Advanced Layouts -->
    <div id="customGridModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-grid-1x2-fill fs-4 text-info me-3"></i>
                        <div>
                            <h5 class="modal-title mb-0">Select Grid Layout</h5>
                            <small class="text-muted">Choose a preset layout or create custom</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- Custom Grid Row/Col Inputs -->
                    <div class="p-3 border-bottom border-secondary bg-dark">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="gridRows" class="form-label small">Rows:</label>
                                <input type="number" class="form-control form-control-sm bg-secondary text-light border-0" id="gridRows" min="1" max="5" value="2">
                            </div>
                            <div class="col-md-3">
                                <label for="gridCols" class="form-label small">Columns:</label>
                                <input type="number" class="form-control form-control-sm bg-secondary text-light border-0" id="gridCols" min="1" max="5" value="2">
                            </div>
                            <div class="col-md-3">
                                <p id="gridPreview" class="fw-bold text-info mb-0 small">Grid: 2 × 2 (4 viewports)</p>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-primary btn-sm w-100" id="applyCustomGrid">
                                    <i class="bi bi-check-lg me-1"></i>Apply Custom
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Preset Layouts Tabs -->
                    <div class="layout-tabs-container">
                        <ul class="nav nav-tabs layout-category-tabs" role="tablist" style="background: rgba(0,0,0,0.3); border-bottom: 1px solid rgba(255,255,255,0.1); padding: 0 10px;">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#layout-1-2" type="button" role="tab" style="color: #adb5bd; border: none; border-bottom: 3px solid transparent; padding: 12px 15px; font-size: 12px;">1 & 2 Spots</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#layout-3" type="button" role="tab" style="color: #adb5bd; border: none; border-bottom: 3px solid transparent; padding: 12px 15px; font-size: 12px;">3 Spots</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#layout-4" type="button" role="tab" style="color: #adb5bd; border: none; border-bottom: 3px solid transparent; padding: 12px 15px; font-size: 12px;">4 Spots</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#layout-5-7" type="button" role="tab" style="color: #adb5bd; border: none; border-bottom: 3px solid transparent; padding: 12px 15px; font-size: 12px;">5 & 7 Spots</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#layout-6-12" type="button" role="tab" style="color: #adb5bd; border: none; border-bottom: 3px solid transparent; padding: 12px 15px; font-size: 12px;">6 to 12 Spots</button>
                            </li>
                        </ul>
                        <div class="tab-content p-4">
                            <!-- 1 & 2 Spots -->
                            <div class="tab-pane fade show active" id="layout-1-2" role="tabpanel">
                                <fieldset class="layout-fieldset" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 15px;">
                                    <legend style="color: #dc3545; font-size: 13px; padding: 0 10px; width: auto;">1 and 2 Spots</legend>
                                    <div class="preset-layouts-grid d-flex flex-wrap gap-3">
                                        <div class="layout-preset-card" data-rows="1" data-cols="1" title="1×1">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                            </div>
                                            <span class="preset-label">1</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="1" data-cols="2" title="1×2 Horizontal">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                            </div>
                                            <span class="preset-label">2</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="2" data-cols="1" title="2×1 Vertical">
                                            <div class="preset-preview" style="display: grid; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 25px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 25px;"></div>
                                            </div>
                                            <span class="preset-label">2</span>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>

                            <!-- 3 Spots -->
                            <div class="tab-pane fade" id="layout-3" role="tabpanel">
                                <fieldset class="layout-fieldset" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 15px;">
                                    <legend style="color: #dc3545; font-size: 13px; padding: 0 10px; width: auto;">3 Spots</legend>
                                    <div class="preset-layouts-grid d-flex flex-wrap gap-3">
                                        <div class="layout-preset-card" data-rows="1" data-cols="3" title="3 Horizontal">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                            </div>
                                            <span class="preset-label">3</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="3" data-cols="1" title="3 Vertical">
                                            <div class="preset-preview" style="display: grid; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 16px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 16px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 16px;"></div>
                                            </div>
                                            <span class="preset-label">3</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="2+1-top" data-custom="true" title="2+1 Top">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                            </div>
                                            <span class="preset-label">3</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+2-left" data-custom="true" title="1+2 Left Big">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-row: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">3</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+2-right" data-custom="true" title="1+2 Right Big">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-row: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">3</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="2+1-bottom" data-custom="true" title="1+2 Bottom">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">3</span>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>

                            <!-- 4 Spots -->
                            <div class="tab-pane fade" id="layout-4" role="tabpanel">
                                <fieldset class="layout-fieldset" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 15px;">
                                    <legend style="color: #dc3545; font-size: 13px; padding: 0 10px; width: auto;">4 Spots</legend>
                                    <div class="preset-layouts-grid d-flex flex-wrap gap-3">
                                        <div class="layout-preset-card" data-rows="2" data-cols="2" title="2×2">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="1" data-cols="4" title="4 Horizontal">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="4" data-cols="1" title="4 Vertical">
                                            <div class="preset-preview" style="display: grid; grid-template-rows: 1fr 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 12px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 12px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 12px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 12px;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+3-left" data-custom="true" title="1+3 Left Big">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-row: span 3;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+3-right" data-custom="true" title="1+3 Right Big">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-row: span 3;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="3+1-top" data-custom="true" title="3+1 Top">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 3;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="3+1-bottom" data-custom="true" title="1+3 Bottom">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 3;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+2+1" data-custom="true" title="1+2+1">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                            </div>
                                            <span class="preset-label">4</span>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>

                            <!-- 5 & 7 Spots -->
                            <div class="tab-pane fade" id="layout-5-7" role="tabpanel">
                                <fieldset class="layout-fieldset" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 15px;">
                                    <legend style="color: #dc3545; font-size: 13px; padding: 0 10px; width: auto;">5 and 7 Spots</legend>
                                    <div class="preset-layouts-grid d-flex flex-wrap gap-3">
                                        <div class="layout-preset-card" data-layout="2+3" data-custom="true" title="2+3">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-row: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">5</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+4-big" data-custom="true" title="1+4 Big Left">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-row: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">5</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="2+2+1" data-custom="true" title="2+2+1">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                            </div>
                                            <span class="preset-label">5</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+2+2" data-custom="true" title="1+2+2">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">5</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="1" data-cols="5" title="1x5">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                                <div style="background: #333; border: 1px solid #555; height: 50px;"></div>
                                            </div>
                                            <span class="preset-label">5</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="3+2+2" data-custom="true" title="3+2+2">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 2;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">7</span>
                                        </div>
                                        <div class="layout-preset-card" data-layout="1+3+3" data-custom="true" title="1+3+3">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555; grid-column: span 3;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">7</span>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>

                            <!-- 6 to 12 Spots -->
                            <div class="tab-pane fade" id="layout-6-12" role="tabpanel">
                                <fieldset class="layout-fieldset" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 15px;">
                                    <legend style="color: #dc3545; font-size: 13px; padding: 0 10px; width: auto;">6 to 12 Spots</legend>
                                    <div class="preset-layouts-grid d-flex flex-wrap gap-3">
                                        <div class="layout-preset-card" data-rows="2" data-cols="3" title="2×3">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">6</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="3" data-cols="2" title="3×2">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">6</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="2" data-cols="4" title="2×4">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">8</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="4" data-cols="2" title="4×2">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">8</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="3" data-cols="3" title="3×3">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">9</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="3" data-cols="4" title="3×4">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">12</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="4" data-cols="3" title="4×3">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">12</span>
                                        </div>
                                    </div>
                                </fieldset>
                                
                                <!-- 15 to 18 Spots -->
                                <fieldset class="layout-fieldset" style="border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 15px;">
                                    <legend style="color: #dc3545; font-size: 13px; padding: 0 10px; width: auto;">15 to 18 Spots</legend>
                                    <div class="preset-layouts-grid d-flex flex-wrap gap-3">
                                        <div class="layout-preset-card" data-rows="5" data-cols="3" title="5×3">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">15</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="3" data-cols="5" title="3×5">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: repeat(5, 1fr); grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">15</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="4" data-cols="4" title="4×4">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">16</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="6" data-cols="3" title="6×3">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: repeat(6, 1fr); gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">18</span>
                                        </div>
                                        <div class="layout-preset-card" data-rows="3" data-cols="6" title="3×6">
                                            <div class="preset-preview" style="display: grid; grid-template-columns: repeat(6, 1fr); grid-template-rows: 1fr 1fr 1fr; gap: 2px;">
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                                <div style="background: #333; border: 1px solid #555;"></div>
                                            </div>
                                            <span class="preset-label">18</span>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary" style="background: linear-gradient(135deg, #16213e 0%, #1a1a2e 100%);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-2"></i>Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Professional Layout Preset Styles -->
    <style>
        /* Modal Container Enhancement */
        #customGridModal .modal-content {
            border: 1px solid rgba(0, 180, 216, 0.3);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.7);
        }
        
        #customGridModal .modal-header {
            background: linear-gradient(135deg, #0a0a14 0%, #101020 100%);
            border-bottom: 1px solid rgba(0, 180, 216, 0.2);
            padding: 16px 24px;
        }
        
        #customGridModal .modal-body {
            background: #0d0d17;
        }
        
        #customGridModal .modal-footer {
            background: linear-gradient(135deg, #101020 0%, #0a0a14 100%);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        /* Tab Navigation - Clean minimalist style */
        .layout-category-tabs {
            background: rgba(0, 0, 0, 0.4) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
            padding: 0 16px !important;
            gap: 4px;
        }
        
        .layout-category-tabs .nav-link {
            color: rgba(255, 255, 255, 0.5) !important;
            background: transparent !important;
            border: none !important;
            border-bottom: 2px solid transparent !important;
            padding: 14px 18px !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            letter-spacing: 0.3px;
            transition: all 0.25s ease !important;
            border-radius: 0 !important;
        }
        
        .layout-category-tabs .nav-link:hover {
            color: rgba(255, 255, 255, 0.9) !important;
            background: rgba(255, 255, 255, 0.03) !important;
        }
        
        .layout-category-tabs .nav-link.active {
            color: #00b4d8 !important;
            background: transparent !important;
            border-bottom: 2px solid #00b4d8 !important;
        }

        /* Fieldset - Subtle container */
        .layout-fieldset {
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 10px !important;
            padding: 20px !important;
            background: rgba(255, 255, 255, 0.02);
        }
        
        .layout-fieldset legend {
            color: rgba(255, 255, 255, 0.6) !important;
            font-size: 12px !important;
            font-weight: 500 !important;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            padding: 0 12px !important;
        }

        /* Layout Preset Cards - Clean professional look */
        .preset-layouts-grid {
            gap: 16px !important;
        }
        
        .layout-preset-card {
            width: 90px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            padding: 8px;
            border-radius: 8px;
            background: transparent;
        }
        
        .layout-preset-card:hover {
            transform: translateY(-4px);
            background: rgba(0, 180, 216, 0.08);
        }
        
        .layout-preset-card:hover .preset-preview {
            border-color: #00b4d8;
            box-shadow: 0 4px 20px rgba(0, 180, 216, 0.25);
        }
        
        .layout-preset-card:hover .preset-label {
            color: #00b4d8;
        }

        /* Layout Preview Box - Clean grid visualization */
        .preset-preview {
            width: 70px;
            height: 52px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            padding: 4px;
            background: #0a0a12;
            margin: 0 auto;
            transition: all 0.25s ease;
        }
        
        .preset-preview > div {
            background: linear-gradient(135deg, #1a1a2e 0%, #0d0d17 100%) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            border-radius: 2px !important;
        }

        /* Spots Count Label */
        .preset-label {
            display: block;
            margin-top: 8px;
            color: rgba(255, 255, 255, 0.5);
            font-size: 13px;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        /* Custom Grid Input Section */
        #customGridModal .border-bottom {
            background: rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
        }
        
        #customGridModal .form-control {
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #fff !important;
            border-radius: 6px;
        }
        
        #customGridModal .form-control:focus {
            background: rgba(255, 255, 255, 0.12) !important;
            border-color: #00b4d8 !important;
            box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.15);
        }
        
        #customGridModal .form-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        /* Apply Button */
        #applyCustomGrid {
            background: linear-gradient(135deg, #00b4d8 0%, #0096c7 100%);
            border: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        #applyCustomGrid:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0, 180, 216, 0.4);
        }

        /* Tab Content */
        #customGridModal .tab-content {
            padding: 24px !important;
        }
    </style>

    <!-- Custom Grid Functionality -->
    <script>
        (function() {
            // Wait for DOM to be fully loaded
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initCustomGrid);
            } else {
                initCustomGrid();
            }
            
            function initCustomGrid() {
                console.log('Initializing Custom Grid Layout...');
                
                const customGridBtn = document.getElementById('customGridBtn');
                const customGridModalEl = document.getElementById('customGridModal');
                const gridRowsInput = document.getElementById('gridRows');
                const gridColsInput = document.getElementById('gridCols');
                const gridPreview = document.getElementById('gridPreview');
                const applyCustomGridBtn = document.getElementById('applyCustomGrid');
                
                if (!customGridModalEl) {
                    console.error('customGridModal not found!');
                    return;
                }
                
                const customGridModal = new bootstrap.Modal(customGridModalEl);
                
                // Update preview when inputs change
                function updatePreview() {
                    if (!gridRowsInput || !gridColsInput || !gridPreview) return;
                    const rows = parseInt(gridRowsInput.value) || 1;
                    const cols = parseInt(gridColsInput.value) || 1;
                    const total = rows * cols;
                    gridPreview.textContent = `Grid: ${rows} × ${cols} (${total} viewport${total > 1 ? 's' : ''})`;
                }
                
                if (gridRowsInput) gridRowsInput.addEventListener('input', updatePreview);
                if (gridColsInput) gridColsInput.addEventListener('input', updatePreview);

                // Show modal when button clicked
                if (customGridBtn) {
                    customGridBtn.addEventListener('click', function() {
                        customGridModal.show();
                    });
                }

                // Apply custom grid
                if (applyCustomGridBtn) {
                    applyCustomGridBtn.addEventListener('click', function() {
                        const rows = parseInt(gridRowsInput?.value) || 1;
                        const cols = parseInt(gridColsInput?.value) || 1;

                        // Validate
                        if (rows < 1 || rows > 5 || cols < 1 || cols > 5) {
                            alert('Please enter valid rows and columns (1-5)');
                            return;
                        }

                        const total = rows * cols;
                        if (total > 25) {
                            alert('Maximum 25 viewports allowed (5×5)');
                            return;
                        }

                        console.log(`Creating custom grid: ${rows} rows × ${cols} columns (${total} viewports)`);

                        // Close modal
                        customGridModal.hide();

                        // Create the custom grid layout
                        createCustomGridLayout(rows, cols);
                    });
                }

            function createCustomGridLayout(rows, cols) {
                const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
                if (!viewportManager) {
                    console.error('Viewport manager not initialized');
                    return;
                }

                const layoutKey = `custom-${rows}x${cols}`;
                const total = rows * cols;

                // Generate viewport configuration
                const viewports = [];
                for (let i = 0; i < total; i++) {
                    const row = Math.floor(i / cols);
                    const col = i % cols;
                    viewports.push({
                        name: `viewport-${i + 1}`,
                        gridArea: `${row + 1} / ${col + 1} / ${row + 2} / ${col + 2}`
                    });
                }

                // Register the custom layout
                viewportManager.layouts[layoutKey] = {
                    rows: rows,
                    cols: cols,
                    viewports: viewports.map(v => v.name)
                };

                // Create viewports with custom layout
                viewportManager.createViewports(layoutKey);

                // Apply CSS Grid styling
                const container = document.getElementById('viewport-container');
                if (container) {
                    container.style.display = 'grid';
                    container.style.gridTemplateRows = `repeat(${rows}, 1fr)`;
                    container.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
                    container.style.gap = '2px';
                    container.style.width = '100%';
                    container.style.height = '100%';
                }

                console.log(`Custom grid layout created: ${layoutKey}`);
            }

            // Initialize preview
            updatePreview();

            // Handle preset layout card clicks using event delegation
            customGridModalEl.addEventListener('click', function(e) {
                const card = e.target.closest('.layout-preset-card');
                if (!card) return;
                
                const rows = parseInt(card.dataset.rows);
                const cols = parseInt(card.dataset.cols);
                const customLayout = card.dataset.layout;
                const isCustom = card.dataset.custom === 'true';
                
                console.log('Layout card clicked:', { rows, cols, customLayout, isCustom });

                // Close the modal first
                customGridModal.hide();

                // Use timeout to allow modal to close
                setTimeout(function() {
                    if (!isCustom && rows && cols) {
                        // Standard grid layout
                        console.log(`Applying preset grid: ${rows}x${cols}`);
                        createCustomGridLayout(rows, cols);
                    } else if (isCustom && customLayout) {
                        // Advanced asymmetric layout
                        console.log(`Applying custom layout: ${customLayout}`);
                        applyAsymmetricLayout(customLayout);
                    }

                    // Show confirmation
                    if (window.DICOM_VIEWER && window.DICOM_VIEWER.showAISuggestion) {
                        const labelEl = card.querySelector('.preset-label');
                        const spots = labelEl ? labelEl.textContent : (rows * cols);
                        window.DICOM_VIEWER.showAISuggestion(`Applied ${spots}-viewport layout`);
                    }
                }, 100);
            });

            // Apply asymmetric layouts using CSS Grid template areas
            function applyAsymmetricLayout(layoutId) {
                const container = document.getElementById('viewport-container');
                if (!container) return;

                // Define asymmetric layouts with CSS Grid areas
                const asymmetricLayouts = {
                    '2+1-top': { 
                        areas: '"a b" "c c"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 3 
                    },
                    '1+2-left': { 
                        areas: '"a b" "a c"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 3 
                    },
                    '1+2-right': { 
                        areas: '"a b" "c b"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 3 
                    },
                    '2+1-bottom': { 
                        areas: '"a a" "b c"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 3 
                    },
                    '1+3-left': { 
                        areas: '"a b" "a c" "a d"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr 1fr',
                        viewports: 4 
                    },
                    '1+3-right': { 
                        areas: '"a b" "c b" "d b"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr 1fr',
                        viewports: 4 
                    },
                    '3+1-top': { 
                        areas: '"a b c" "d d d"', 
                        cols: '1fr 1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 4 
                    },
                    '3+1-bottom': { 
                        areas: '"a a a" "b c d"', 
                        cols: '1fr 1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 4 
                    },
                    '1+2+1': { 
                        areas: '"a a" "b c" "d d"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr 1fr',
                        viewports: 4 
                    },
                    '2+3': { 
                        areas: '"a b c" "a d e"', 
                        cols: '1fr 1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 5 
                    },
                    '1+4-big': { 
                        areas: '"a b c" "a d e"', 
                        cols: '2fr 1fr 1fr', 
                        rows: '1fr 1fr',
                        viewports: 5 
                    },
                    '2+2+1': { 
                        areas: '"a b" "c d" "e e"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr 1fr',
                        viewports: 5 
                    },
                    '1+2+2': { 
                        areas: '"a a" "b c" "d e"', 
                        cols: '1fr 1fr', 
                        rows: '1fr 1fr 1fr',
                        viewports: 5 
                    },
                    '3+2+2': { 
                        areas: '"a b c" "d d e" "f f g"', 
                        cols: '1fr 1fr 1fr', 
                        rows: '1fr 1fr 1fr',
                        viewports: 7 
                    },
                    '1+3+3': { 
                        areas: '"a a a" "b c d" "e f g"', 
                        cols: '1fr 1fr 1fr', 
                        rows: '1fr 1fr 1fr',
                        viewports: 7 
                    }
                };

                const layout = asymmetricLayouts[layoutId];
                if (!layout) {
                    console.error('Unknown layout:', layoutId);
                    return;
                }

                // Create viewport divs with grid area assignments
                container.innerHTML = '';
                container.style.display = 'grid';
                container.style.gridTemplateColumns = layout.cols;
                container.style.gridTemplateRows = layout.rows;
                container.style.gridTemplateAreas = layout.areas;
                container.style.gap = '4px';
                container.style.height = '100%';

                // Generate viewport names from areas
                const areaLetters = layout.areas.replace(/['" ]/g, '').split('');
                const uniqueAreas = [...new Set(areaLetters)];

                uniqueAreas.forEach((area, index) => {
                    const viewport = document.createElement('div');
                    viewport.className = 'viewport';
                    viewport.id = `dicomViewport-${index + 1}`;
                    viewport.setAttribute('data-viewport-name', `viewport-${index + 1}`);
                    viewport.style.gridArea = area;
                    viewport.style.background = '#000';
                    viewport.style.position = 'relative';
                    viewport.style.overflow = 'hidden';

                    // Add viewport number overlay
                    const overlay = document.createElement('div');
                    overlay.className = 'viewport-number-overlay';
                    overlay.textContent = index + 1;
                    overlay.style.cssText = `
                        position: absolute;
                        top: 8px;
                        left: 8px;
                        background: rgba(0, 0, 0, 0.7);
                        color: #0d6efd;
                        padding: 4px 10px;
                        border-radius: 4px;
                        font-size: 12px;
                        font-weight: 600;
                        z-index: 10;
                    `;
                    viewport.appendChild(overlay);

                    container.appendChild(viewport);
                });

                // Initialize cornerstone on viewports
                container.querySelectorAll('.viewport').forEach(vp => {
                    try {
                        cornerstone.enable(vp);
                    } catch (e) {
                        console.log('Viewport ready:', vp.id);
                    }
                });

                // Dispatch layout change event
                document.dispatchEvent(new CustomEvent('layoutChanged', { 
                    detail: { type: 'asymmetric', layout: layoutId, spots: layout.viewports } 
                }));
            }
            
            console.log('Custom Grid Layout initialized successfully');
            } // Close initCustomGrid function
        })();
    </script>

    <!-- Insert All and Clear All Functionality -->
    <script>
        (function() {
            const insertAllBtn = document.getElementById('insertAllBtn');
            const clearAllBtn = document.getElementById('clearAllBtn');

            // Calculate optimal grid based on number of images
            function calculateOptimalGrid(imageCount) {
                if (imageCount === 0) return { rows: 1, cols: 1 };

                const isPortrait = window.innerHeight > window.innerWidth;
                const isLandscape = window.innerWidth > window.innerHeight;

                // Portrait optimized layouts (more rows than columns)
                const portraitLayouts = {
                    2: { rows: 2, cols: 1 },
                    6: { rows: 3, cols: 2 },
                    8: { rows: 4, cols: 2 },
                    15: { rows: 5, cols: 3 },
                    18: { rows: 6, cols: 3 }
                };

                // Landscape optimized layouts (more columns than rows)
                const landscapeLayouts = {
                    4: { rows: 2, cols: 2 },
                    9: { rows: 3, cols: 3 },
                    12: { rows: 3, cols: 4 }
                };

                // Check for exact matches first
                if (isPortrait && portraitLayouts[imageCount]) {
                    return portraitLayouts[imageCount];
                }
                if (isLandscape && landscapeLayouts[imageCount]) {
                    return landscapeLayouts[imageCount];
                }

                // For other numbers, calculate optimal grid
                let cols = Math.ceil(Math.sqrt(imageCount));
                let rows = Math.ceil(imageCount / cols);

                // Adjust for orientation
                if (isLandscape && rows > cols) {
                    [rows, cols] = [cols, rows]; // Swap to prefer landscape
                }
                if (isPortrait && cols > rows) {
                    [rows, cols] = [cols, rows]; // Swap to prefer portrait
                }

                // Limit to max 5x5
                if (rows > 5) rows = 5;
                if (cols > 5) cols = 5;

                return { rows, cols };
            }

            // Insert All Images - respects current layout, uses page navigator for overflow
            insertAllBtn.addEventListener('click', async function() {
                console.log('Insert All clicked - respecting current layout');

                // Get images from STATE
                const images = window.DICOM_VIEWER.STATE.currentSeriesImages;
                if (!images || images.length === 0) {
                    alert('No images available. Please load a study first.');
                    return;
                }

                const imageCount = images.length;
                console.log(`Found ${imageCount} images in STATE`);

                // Use CURRENT viewports (don't recalculate layout)
                const viewportElements = document.querySelectorAll('.viewport');
                const viewportCount = viewportElements.length;
                console.log(`Current layout has ${viewportCount} viewports`);

                if (viewportCount === 0) {
                    alert('No viewports available. Please select a layout first.');
                    return;
                }

                // Enable cornerstone on all viewports first
                viewportElements.forEach(viewport => {
                    try {
                        cornerstone.getEnabledElement(viewport);
                    } catch (e) {
                        try {
                            cornerstone.enable(viewport);
                        } catch (e2) {
                            console.warn(`Error enabling viewport:`, e2);
                        }
                    }
                });

                // Wait a bit for cornerstone to initialize
                await new Promise(resolve => setTimeout(resolve, 100));

                // Load only the first page of images into viewports
                const imagesToLoad = Math.min(imageCount, viewportCount);
                
                for (let i = 0; i < imagesToLoad; i++) {
                    const viewport = viewportElements[i];
                    const image = images[i];

                    if (viewport && image) {
                        const fileId = image.id || image.orthancInstanceId || image.instanceId;

                        if (fileId) {
                            console.log(`Loading image ${i + 1}/${imagesToLoad} into viewport ${viewport.id}`);
                            try {
                                await window.DICOM_VIEWER.loadImageInViewport(viewport, fileId);
                                cornerstone.fitToWindow(viewport);
                            } catch (error) {
                                console.error(`Error loading image ${fileId}:`, error);
                            }
                        }
                    }
                }

                // Trigger page navigator for remaining images
                setTimeout(() => {
                    if (window.DICOM_VIEWER.MANAGERS && window.DICOM_VIEWER.MANAGERS.pageNavigator) {
                        window.DICOM_VIEWER.MANAGERS.pageNavigator.refresh();
                    }
                    
                    if (imageCount > viewportCount) {
                        const totalPages = Math.ceil(imageCount / viewportCount);
                        window.DICOM_VIEWER.showAISuggestion(
                            `Page 1 of ${totalPages} (${imagesToLoad} images). Use Page Navigator or PageUp/PageDown for all ${imageCount} images.`
                        );
                    } else {
                        window.DICOM_VIEWER.showAISuggestion(`Loaded all ${imagesToLoad} images.`);
                    }
                }, 300);

                console.log(`Loaded ${imagesToLoad} of ${imageCount} images`);
            });

            // Clear All Viewports - REMOVED: Now handled by viewport-actions-manager.js
            // The clearAllBtn now uses unified deleteSelectedImage() function which:
            // - Deletes only selected viewports if any are selected
            // - Clears all viewports (with confirmation) if none selected
            console.log('Clear All button handler moved to viewport-actions-manager.js');
        })();
    </script>

    <!-- Reset Viewport Button Functionality -->
    <script>
        (function() {
            const resetViewportBtn = document.getElementById('resetViewportBtn');
            
            if (resetViewportBtn) {
                resetViewportBtn.addEventListener('click', function() {
                    // Try to use existing reset function if available
                    if (window.DICOM_VIEWER && window.DICOM_VIEWER.resetActiveViewport) {
                        window.DICOM_VIEWER.resetActiveViewport();
                        console.log('Viewport reset via DICOM_VIEWER.resetActiveViewport');
                        return;
                    }
                    
                    // Fallback: reset active viewport manually
                    const viewports = document.querySelectorAll('.viewport');
                    const activeViewport = document.querySelector('.viewport.active') || viewports[0];
                    
                    if (activeViewport) {
                        try {
                            const enabledElement = cornerstone.getEnabledElement(activeViewport);
                            if (enabledElement && enabledElement.image) {
                                // Reset viewport to initial values
                                const viewport = cornerstone.getDefaultViewportForImage(activeViewport, enabledElement.image);
                                cornerstone.setViewport(activeViewport, viewport);
                                cornerstone.updateImage(activeViewport);
                                
                                console.log('Viewport reset successfully');
                                
                                // Show feedback if available
                                if (window.DICOM_VIEWER && window.DICOM_VIEWER.showAISuggestion) {
                                    window.DICOM_VIEWER.showAISuggestion('Viewport reset to default');
                                }
                            }
                        } catch (e) {
                            console.warn('Could not reset viewport:', e);
                        }
                    }
                });
            }
        })();
    </script>
    <script src="js/fixes/dicom-viewport-fix-v5.js"></script>
</body>
    <!-- Auto-Backup Scheduler Trigger -->
    <!-- Custom Drawing Tool Manager (replaces FreehandRoi) -->
    <script src="<?= BASE_PATH ?>/js/components/drawing-manager.js"></script>

    <script>
        (function() {
            // Check for backup every 60 seconds
            const BACKUP_CHECK_INTERVAL = 60000;
            
            async function triggerBackupCheck() {
                try {
                    const basePath = '<?= BASE_PATH ?>';
                    const response = await fetch(`${basePath}/api/backup/auto-trigger.php`);
                    const data = await response.json();
                    
                    if (data.triggered) {
                        console.log('Scheduled backup triggered:', data.message);
                    }
                } catch (error) {
                    // Silent fail - don't disturb user
                    console.warn('Backup scheduler check failed:', error);
                }
            }
            
            // Initial check after 10 seconds
            setTimeout(triggerBackupCheck, 10000);
            
            // Interval check
            setInterval(triggerBackupCheck, BACKUP_CHECK_INTERVAL);
        })();
    </script>
</body>

</html>

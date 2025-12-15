/**
 * DICOM Print Manager v3.0
 * Professional medical imaging printing with EXACT viewport capture
 * - Captures current viewport state (layout, W/L, shapes, annotations)
 * - Printer selection from saved printers
 * - Medical report printing with professional templates
 * - Print settings stored in admin configuration
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.PrintManager = class {
    constructor() {
        this.printSettings = null; // Will be loaded from server
        this.availablePrinters = [];
        this.selectedPrinter = null;
        this.hospitalSettings = {}; // Hospital name, logo, etc.
        this.init();
    }

    async init() {
        // Load print settings from server
        await this.loadPrintSettings();
        await this.loadPrinters();
        await this.loadHospitalSettings();
        this.setupPrintButton();
        this.setupKeyboardShortcuts();
    }

    async loadHospitalSettings() {
        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const response = await fetch(`${basePath}/api/reports/hospital-config.php`);
            const data = await response.json();

            if (data.success && data.data) {
                this.hospitalSettings = data.data;
            }
        } catch (error) {
            console.error('Error loading hospital settings:', error);
            this.hospitalSettings = { hospital_name: 'Medical Imaging Center' };
        }
    }

    setupKeyboardShortcuts() {
        // Ctrl+P to open print dialog
        document.addEventListener('keydown', (e) => {
            // Check if Ctrl+P (or Cmd+P on Mac)
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault(); // Prevent browser's default print dialog
                this.showPrintDialog();
            }
        });
    }

    async loadPrintSettings() {
        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const response = await fetch(`${basePath}/api/settings/print-settings.php`);
            const data = await response.json();

            if (data.success && data.settings) {
                this.printSettings = data.settings;
            } else {
                // Default settings
                this.printSettings = {
                    includePatientInfo: true,
                    includeStudyInfo: true,
                    includeInstitutionInfo: true,
                    includeAnnotations: true,
                    includeWindowLevel: true,
                    includeMeasurements: true,
                    includeTimestamp: true,
                    paperSize: 'A4',
                    orientation: 'landscape',
                    quality: 'high',
                    colorMode: 'grayscale',
                    // Border settings - defaults
                    borderEnabled: true,
                    borderColor: '#000000',
                    borderWidth: 2,
                    borderStyle: 'solid'
                };
            }

            // Load border settings from localStorage (saved from admin settings page)
            const savedBorderSettings = localStorage.getItem('dicomPrintBorderSettings');
            if (savedBorderSettings) {
                try {
                    const parsed = JSON.parse(savedBorderSettings);
                    this.printSettings.borderEnabled = parsed.printBorderEnabled ?? true;
                    this.printSettings.borderColor = parsed.printBorderColor || '#000000';
                    this.printSettings.borderWidth = parsed.printBorderWidth || 2;
                    this.printSettings.borderStyle = parsed.printBorderStyle || 'solid';
                    console.log('Loaded border settings from localStorage:', parsed);
                } catch (e) {
                    console.warn('Error parsing border settings from localStorage:', e);
                }
            }
            // Fallback: Also check SettingsManager if available
            else if (window.DICOM_VIEWER && window.DICOM_VIEWER.SettingsManager) {
                const borderSettings = window.DICOM_VIEWER.SettingsManager.getPrintBorderSettings();
                if (borderSettings) {
                    this.printSettings.borderEnabled = borderSettings.enabled;
                    this.printSettings.borderColor = borderSettings.color;
                    this.printSettings.borderWidth = borderSettings.width;
                    this.printSettings.borderStyle = borderSettings.style;
                }
            }
        } catch (error) {
            console.error('Error loading print settings:', error);
            this.printSettings = this.getDefaultSettings();
        }
    }

    async loadPrinters() {
        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const response = await fetch(`${basePath}/api/settings/printers.php`);
            const data = await response.json();

            if (data.success && data.printers) {
                this.availablePrinters = data.printers.filter(p => p.is_active);

                // Find default printer
                const defaultPrinter = this.availablePrinters.find(p => p.is_default == 1);
                if (defaultPrinter) {
                    this.selectedPrinter = defaultPrinter.id;
                }
            }
        } catch (error) {
            console.error('Error loading printers:', error);
            this.availablePrinters = [];
        }
    }

    getDefaultSettings() {
        return {
            includePatientInfo: true,
            includeStudyInfo: true,
            includeInstitutionInfo: true,
            includeAnnotations: true,
            includeWindowLevel: true,
            includeMeasurements: true,
            includeTimestamp: true,
            paperSize: 'A4',
            orientation: 'landscape',
            quality: 'high',
            colorMode: 'grayscale',
            // Border settings
            borderEnabled: true,
            borderColor: '#000000',
            borderWidth: 2,
            borderStyle: 'solid'
        };
    }

    setupPrintButton() {
        const printBtn = document.getElementById('printBtn');
        if (printBtn) {
            printBtn.addEventListener('click', () => this.showPrintDialog());
        }
    }

    /**
     * Capture EXACT viewport state including layout, images, W/L, shapes, and text annotations
     */
    async captureCurrentViewportState() {
        const viewportContainer = document.getElementById('viewport-container');
        const viewports = document.querySelectorAll('.viewport');

        if (!viewports || viewports.length === 0) {
            throw new Error('No viewports found to print');
        }

        // Detect current layout
        const containerStyles = window.getComputedStyle(viewportContainer);
        const gridColumns = containerStyles.gridTemplateColumns.split(' ').length;
        const gridRows = containerStyles.gridTemplateRows.split(' ').length;

        const capturedViewports = [];

        for (const viewport of viewports) {
            try {
                const canvas = viewport.querySelector('canvas');
                if (!canvas) continue;

                // Check if viewport has an image loaded
                let hasImage = false;
                try {
                    const enabledElement = cornerstone.getEnabledElement(viewport);
                    hasImage = !!enabledElement.image;
                } catch (e) {
                    // Viewport not enabled or no image
                    continue;
                }

                if (!hasImage) continue;

                // Get the DICOM canvas
                let dataUrl;
                const textAnnotations = viewport.querySelectorAll('.text-annotation');

                if (textAnnotations.length > 0) {
                    // Create a composite canvas with annotations drawn on it
                    try {
                        const compositeCanvas = document.createElement('canvas');
                        const viewportRect = viewport.getBoundingClientRect();
                        const scale = this.printSettings.quality === 'high' ? 2 : 1;

                        compositeCanvas.width = canvas.width * scale;
                        compositeCanvas.height = canvas.height * scale;

                        const ctx = compositeCanvas.getContext('2d');
                        ctx.scale(scale, scale);

                        // Draw the DICOM image first
                        ctx.drawImage(canvas, 0, 0, canvas.width, canvas.height);

                        // Calculate scale factor between canvas and viewport
                        const scaleX = canvas.width / viewportRect.width;
                        const scaleY = canvas.height / viewportRect.height;

                        // Draw each text annotation
                        textAnnotations.forEach(ann => {
                            const contentEl = ann.querySelector('.text-annotation-content');
                            if (!contentEl) return;

                            const text = contentEl.textContent;
                            const styles = window.getComputedStyle(contentEl);

                            // Get position (already in percentage)
                            const leftPercent = parseFloat(ann.style.left) || 0;
                            const topPercent = parseFloat(ann.style.top) || 0;

                            const x = (leftPercent / 100) * canvas.width;
                            const y = (topPercent / 100) * canvas.height;

                            // Get font properties
                            const fontSize = parseInt(styles.fontSize) || 16;
                            const fontColor = styles.color || '#FFFF00';
                            const bgColor = styles.backgroundColor || 'rgba(0, 0, 0, 0.6)';
                            const padding = parseInt(styles.padding) || 6;

                            // Set font
                            ctx.font = `${fontSize * scaleX}px Arial, sans-serif`;

                            // Measure text
                            const textMetrics = ctx.measureText(text);
                            const textWidth = textMetrics.width;
                            const textHeight = fontSize * scaleX;

                            // Draw background
                            ctx.fillStyle = bgColor;
                            ctx.fillRect(
                                x,
                                y,
                                textWidth + (padding * 2 * scaleX),
                                textHeight + (padding * 2 * scaleY)
                            );

                            // Draw text
                            ctx.fillStyle = fontColor;
                            ctx.textBaseline = 'top';
                            ctx.fillText(text, x + (padding * scaleX), y + (padding * scaleY));
                        });

                        dataUrl = compositeCanvas.toDataURL('image/png', this.printSettings.quality === 'high' ? 1.0 : 0.8);
                        console.log('Captured viewport with', textAnnotations.length, 'text annotations');
                    } catch (compositeError) {
                        console.warn('Composite canvas failed, falling back to standard capture:', compositeError);
                        dataUrl = canvas.toDataURL('image/png', this.printSettings.quality === 'high' ? 1.0 : 0.8);
                    }
                } else {
                    // Standard canvas capture (no annotations)
                    dataUrl = canvas.toDataURL('image/png', this.printSettings.quality === 'high' ? 1.0 : 0.8);
                }

                // Get viewport state
                const viewportState = cornerstone.getViewport(viewport);
                const viewportName = viewport.getAttribute('data-viewport-name') || 'View';

                // Get image metadata
                const enabledElement = cornerstone.getEnabledElement(viewport);
                const imageId = enabledElement.image?.imageId || '';

                capturedViewports.push({
                    dataUrl,
                    name: viewportName,
                    windowWidth: viewportState?.voi?.windowWidth || 0,
                    windowCenter: viewportState?.voi?.windowCenter || 0,
                    zoom: viewportState?.scale || 1,
                    pan: viewportState?.translation || { x: 0, y: 0 },
                    rotation: viewportState?.rotation || 0,
                    invert: viewportState?.invert || false,
                    hflip: viewportState?.hflip || false,
                    vflip: viewportState?.vflip || false
                });
            } catch (error) {
                console.error('Error capturing viewport:', error);
            }
        }

        if (capturedViewports.length === 0) {
            throw new Error('No images loaded in viewports');
        }

        return {
            layout: `${gridRows}x${gridColumns}`,
            viewports: capturedViewports,
            totalViewports: viewports.length
        };
    }

    async showPrintDialog() {
        const state = window.DICOM_VIEWER.STATE;

        if (!state.currentSeriesImages || state.currentSeriesImages.length === 0) {
            this.showToast('No images loaded to print', 'warning');
            return;
        }

        // Remove existing modal if present
        const existingModal = document.getElementById('printDialogV3');
        if (existingModal) existingModal.remove();

        // Get current patient/study info
        const currentImage = state.currentSeriesImages?.[state.currentImageIndex] || {};
        const patientName = currentImage.patient_name || currentImage.patientName || 'Unknown Patient';
        const patientId = currentImage.patient_id || currentImage.patientId || '';
        const studyDate = currentImage.study_date || currentImage.studyDate || new Date().toLocaleDateString();

        // Create modal HTML
        const modalHTML = `
            <div class="modal fade" id="printDialogV3" tabindex="-1" data-bs-backdrop="static">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content bg-dark text-light border-secondary">
                        <div class="modal-header border-secondary bg-gradient" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);">
                            <div class="d-flex align-items-center">
                                <div class="print-icon-container me-3">
                                    <i class="bi bi-printer-fill fs-3 text-primary"></i>
                                </div>
                                <div>
                                    <h5 class="modal-title mb-0">Print Current View</h5>
                                    <small class="text-muted">Print exactly what you see on screen</small>
                                </div>
                            </div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body p-4">
                            <!-- Patient Info -->
                            <div class="alert alert-info d-flex align-items-center mb-4">
                                <i class="bi bi-person-fill fs-4 me-3"></i>
                                <div>
                                    <strong>${patientName}</strong><br>
                                    <small>ID: ${patientId} | Study Date: ${studyDate}</small>
                                </div>
                            </div>

                            <!-- Print Type Selection -->
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-file-earmark me-2"></i>What would you like to print?
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <div class="print-type-card active" data-type="currentLayout">
                                        <input type="radio" name="printType" value="currentLayout" checked hidden>
                                        <i class="bi bi-grid-3x3 fs-3 text-primary mb-2"></i>
                                        <h6 class="mb-1">Current View</h6>
                                        <small class="text-muted">Print exactly what you see<br>on screen</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="print-type-card" data-type="allImages">
                                        <input type="radio" name="printType" value="allImages" hidden>
                                        <i class="bi bi-images fs-3 text-warning mb-2"></i>
                                        <h6 class="mb-1">All Images</h6>
                                        <small class="text-muted">Print all ${state.currentSeriesImages?.length || 0} images<br>on multiple pages</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="print-type-card" data-type="report">
                                        <input type="radio" name="printType" value="report" hidden>
                                        <i class="bi bi-file-medical fs-3 text-success mb-2"></i>
                                        <h6 class="mb-1">Medical Report</h6>
                                        <small class="text-muted" id="reportStatus">Checking for report...</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Layout Selector removed - auto-detects current viewport layout -->
                            <div id="allImagesOptions" style="display: none;">
                                <div class="alert alert-success small mb-3">
                                    <i class="bi bi-check-circle me-2"></i>
                                    <span id="pageCalculation">Will use your current viewport layout automatically</span>
                                </div>
                            </div>

                            <!-- Printer Selection -->
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-printer me-2"></i>Select Printer
                            </h6>
                            <div id="printerSelection">
                                ${this.availablePrinters.length > 0 ? this.renderPrintersList() : `
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        No printers configured. Using system default printer.
                                    </div>
                                `}
                            </div>

                            <!-- Print Preview Info -->
                            <div class="mt-4 p-3 bg-secondary bg-opacity-10 rounded">
                                <h6 class="text-info mb-2"><i class="bi bi-info-circle me-2"></i>Print Settings</h6>
                                <small class="text-muted">
                                    Paper: <strong>${this.printSettings.paperSize}</strong> |
                                    Orientation: <strong>${this.printSettings.orientation}</strong> |
                                    Quality: <strong>${this.printSettings.quality}</strong>
                                </small>
                            </div>

                            <!-- Border Settings moved to App Settings -->
                            <div class="mt-3 p-2 bg-secondary bg-opacity-10 rounded small">
                                <i class="bi bi-info-circle me-1 text-info"></i>
                                <span class="text-muted">Border settings are configured in </span>
                                <a href="#" id="openBorderSettingsLink" class="text-primary">
                                    <i class="bi bi-gear me-1"></i>App Settings → Export
                                </a>
                            </div>
                        </div>

                        <div class="modal-footer border-secondary bg-gradient" style="background: linear-gradient(135deg, #16213e 0%, #1a1a2e 100%);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-lg me-2"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-outline-info" id="printPreviewBtnV3">
                                <i class="bi bi-eye me-2"></i>Preview
                            </button>
                            <button type="button" class="btn btn-primary" id="confirmPrintV3">
                                <i class="bi bi-printer me-2"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                .print-type-card {
                    background: rgba(255, 255, 255, 0.05);
                    border: 2px solid rgba(255, 255, 255, 0.1);
                    border-radius: 12px;
                    padding: 15px;
                    text-align: center;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    height: 100%;
                }

                .print-type-card:hover {
                    background: rgba(13, 110, 253, 0.1);
                    border-color: rgba(13, 110, 253, 0.3);
                    transform: translateY(-2px);
                }

                .print-type-card.active {
                    background: rgba(13, 110, 253, 0.15);
                    border-color: #0d6efd;
                    box-shadow: 0 0 15px rgba(13, 110, 253, 0.3);
                }

                .print-type-card.disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }

                .layout-option {
                    background: rgba(255, 255, 255, 0.05);
                    border: 2px solid rgba(255, 255, 255, 0.15);
                    border-radius: 10px;
                    padding: 12px 8px;
                    text-align: center;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }

                .layout-option:hover {
                    background: rgba(255, 193, 7, 0.1);
                    border-color: rgba(255, 193, 7, 0.4);
                }

                .layout-option.selected {
                    background: rgba(255, 193, 7, 0.15);
                    border-color: #ffc107;
                    box-shadow: 0 0 10px rgba(255, 193, 7, 0.3);
                }

                .printer-option {
                    background: rgba(255, 255, 255, 0.05);
                    border: 2px solid rgba(255, 255, 255, 0.1);
                    border-radius: 8px;
                    padding: 12px 15px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    margin-bottom: 10px;
                }

                .printer-option:hover {
                    background: rgba(13, 110, 253, 0.1);
                    border-color: rgba(13, 110, 253, 0.3);
                }

                .printer-option.selected {
                    background: rgba(13, 110, 253, 0.15);
                    border-color: #0d6efd;
                }

                .print-icon-container {
                    width: 50px;
                    height: 50px;
                    background: linear-gradient(135deg, rgba(13, 110, 253, 0.2) 0%, rgba(13, 110, 253, 0.1) 100%);
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
            </style>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modal = new bootstrap.Modal(document.getElementById('printDialogV3'));
        modal.show();

        // Setup event listeners
        this.setupDialogEventListeners();

        // Check for report
        this.checkForReport();

        // Add Enter key listener for quick print
        const modalElement = document.getElementById('printDialogV3');
        const enterKeyHandler = (e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey) {
                e.preventDefault();
                const printBtn = document.getElementById('confirmPrintV3');
                if (printBtn && !printBtn.disabled) {
                    printBtn.click();
                }
            }
        };

        modalElement.addEventListener('keydown', enterKeyHandler);

        // Clean up listener when modal is hidden
        modalElement.addEventListener('hidden.bs.modal', () => {
            modalElement.removeEventListener('keydown', enterKeyHandler);
        }, { once: true });
    }

    renderPrintersList() {
        if (this.availablePrinters.length === 0) return '';

        let html = '<div class="printers-list">';

        // Add default system printer option
        html += `
            <div class="printer-option selected" data-printer="default">
                <div class="d-flex align-items-center">
                    <i class="bi bi-printer fs-4 me-3 text-primary"></i>
                    <div class="flex-grow-1">
                        <strong>System Default Printer</strong><br>
                        <small class="text-muted">Use default printer configured in your system</small>
                    </div>
                    <i class="bi bi-check-circle-fill text-primary fs-5"></i>
                </div>
            </div>
        `;

        // Add configured printers
        for (const printer of this.availablePrinters) {
            html += `
                <div class="printer-option" data-printer="${printer.id}">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-hdd-network fs-4 me-3 text-info"></i>
                        <div class="flex-grow-1">
                            <strong>${printer.name}</strong><br>
                            <small class="text-muted">${printer.ae_title} @ ${printer.host_name}:${printer.port}</small>
                            ${printer.description ? `<br><small class="text-muted">${printer.description}</small>` : ''}
                        </div>
                    </div>
                </div>
            `;
        }

        html += '</div>';
        return html;
    }

    setupDialogEventListeners() {
        const state = window.DICOM_VIEWER.STATE;
        const totalImages = state.currentSeriesImages?.length || 0;

        // Handle page count calculation based on layout
        const updatePageCalculation = (layout) => {
            const [rows, cols] = layout.split('x').map(Number);
            const imagesPerPage = rows * cols;
            const totalPages = Math.ceil(totalImages / imagesPerPage);
            const pageCalc = document.getElementById('pageCalculation');
            if (pageCalc) {
                pageCalc.innerHTML = `<strong>${totalImages}</strong> images → <strong>${totalPages}</strong> page${totalPages > 1 ? 's' : ''} (${imagesPerPage} images per page)`;
            }
        };

        // Print type selection
        document.querySelectorAll('.print-type-card').forEach(card => {
            card.addEventListener('click', function () {
                if (this.classList.contains('disabled')) return;

                document.querySelectorAll('.print-type-card').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                this.querySelector('input[type="radio"]').checked = true;

                // Toggle layout options visibility
                const allImagesOptions = document.getElementById('allImagesOptions');
                if (this.dataset.type === 'allImages') {
                    allImagesOptions.style.display = 'block';
                    const selectedLayout = document.querySelector('.layout-option.selected')?.dataset.layout || '2x2';
                    updatePageCalculation(selectedLayout);
                } else {
                    allImagesOptions.style.display = 'none';
                }
            });
        });

        // Layout option selection
        document.querySelectorAll('.layout-option').forEach(option => {
            option.addEventListener('click', function () {
                document.querySelectorAll('.layout-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                updatePageCalculation(this.dataset.layout);
            });
        });

        // Printer selection
        document.querySelectorAll('.printer-option').forEach(option => {
            option.addEventListener('click', function () {
                document.querySelectorAll('.printer-option').forEach(o => {
                    o.classList.remove('selected');
                    const checkIcon = o.querySelector('.bi-check-circle-fill');
                    if (checkIcon) checkIcon.remove();
                });

                this.classList.add('selected');
                this.querySelector('div').insertAdjacentHTML('beforeend',
                    '<i class="bi bi-check-circle-fill text-primary fs-5"></i>');
            });
        });

        // Border settings event listeners
        const updateBorderPreview = () => {
            const preview = document.getElementById('borderPreview');
            if (preview) {
                const enabled = document.getElementById('borderEnabled')?.checked ?? true;
                const color = document.getElementById('borderColor')?.value || '#000000';
                const width = document.getElementById('borderWidth')?.value || 2;
                const style = document.getElementById('borderStyle')?.value || 'solid';

                if (enabled) {
                    preview.style.border = `${width}px ${style} ${color}`;
                } else {
                    preview.style.border = 'none';
                }
            }
        };

        // Border enable toggle
        document.getElementById('borderEnabled')?.addEventListener('change', (e) => {
            this.printSettings.borderEnabled = e.target.checked;
            updateBorderPreview();
        });

        // Border color picker
        document.getElementById('borderColor')?.addEventListener('input', (e) => {
            this.printSettings.borderColor = e.target.value;
            updateBorderPreview();
        });

        // Border color presets
        document.querySelectorAll('.border-preset').forEach(btn => {
            btn.addEventListener('click', () => {
                const color = btn.dataset.color;
                const colorPicker = document.getElementById('borderColor');
                if (colorPicker) {
                    colorPicker.value = color;
                    this.printSettings.borderColor = color;
                    updateBorderPreview();
                }
            });
        });

        // Border width slider
        document.getElementById('borderWidth')?.addEventListener('input', (e) => {
            this.printSettings.borderWidth = parseInt(e.target.value);
            document.getElementById('borderWidthValue').textContent = `${e.target.value}px`;
            updateBorderPreview();
        });

        // Border style dropdown
        document.getElementById('borderStyle')?.addEventListener('change', (e) => {
            this.printSettings.borderStyle = e.target.value;
            updateBorderPreview();
        });

        // Preview button
        document.getElementById('printPreviewBtnV3')?.addEventListener('click', () => {
            this.executePrint(true);
        });

        // Print button
        document.getElementById('confirmPrintV3')?.addEventListener('click', () => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('printDialogV3'));
            modal.hide();
            this.executePrint(false);
        });
    }

    async checkForReport() {
        const urlParams = new URLSearchParams(window.location.search);
        const studyUID = urlParams.get('studyUID');
        const reportStatus = document.getElementById('reportStatus');
        const reportCard = document.querySelector('[data-type="report"]');

        if (!studyUID || !reportStatus) return;

        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const response = await fetch(`${basePath}/api/reports/by-study.php?studyUID=${encodeURIComponent(studyUID)}`);
            const data = await response.json();

            if (data.success && data.data && data.data.count > 0) {
                const report = data.data.reports[0];
                reportStatus.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Report available</span>`;
                reportCard.classList.remove('disabled');
                reportCard.dataset.reportId = report.id;
            } else {
                reportStatus.innerHTML = '<span class="text-warning">No report available</span>';
                reportCard.classList.add('disabled');
            }
        } catch (error) {
            console.error('Error checking report:', error);
            reportStatus.innerHTML = '<span class="text-muted">Unable to check</span>';
            reportCard.classList.add('disabled');
        }
    }

    async executePrint(previewOnly = false) {
        const printType = document.querySelector('input[name="printType"]:checked')?.value || 'viewport';
        const selectedPrinterEl = document.querySelector('.printer-option.selected');
        const selectedPrinter = selectedPrinterEl?.dataset.printer || 'default';

        this.selectedPrinter = selectedPrinter;

        try {
            if (printType === 'viewport' || printType === 'currentLayout') {
                // Use html2canvas to capture exact screen layout
                await this.printCurrentLayout(previewOnly);
            } else if (printType === 'allImages') {
                // Auto-detect current layout from viewport-container CSS grid
                const viewportContainer = document.getElementById('viewport-container');
                let detectedLayout = '2x2'; // Default

                if (viewportContainer) {
                    const containerStyles = window.getComputedStyle(viewportContainer);
                    const gridCols = containerStyles.gridTemplateColumns.split(' ').filter(s => s.trim()).length || 2;
                    const gridRows = containerStyles.gridTemplateRows.split(' ').filter(s => s.trim()).length || 2;
                    detectedLayout = `${gridRows}x${gridCols}`;
                    console.log(`Auto-detected layout: ${detectedLayout}`);
                }

                await this.printAllImages(detectedLayout, previewOnly);
            } else if (printType === 'report') {
                const reportId = document.querySelector('[data-type="report"]')?.dataset.reportId;
                if (reportId) {
                    await this.printReport(reportId, previewOnly);
                } else {
                    this.showToast('No report available to print', 'warning');
                }
            }
        } catch (error) {
            console.error('Print error:', error);
            this.showToast('Print failed: ' + error.message, 'error');
        }
    }

    async printViewport(previewOnly = false) {
        this.showLoadingModal('Capturing viewport state...', 0);

        try {
            // Capture EXACT viewport state
            const viewportState = await this.captureCurrentViewportState();

            this.updateLoadingProgress('Generating print preview...', 50);

            // Get patient/study info
            const state = window.DICOM_VIEWER.STATE;
            const currentImage = state.currentSeriesImages?.[state.currentImageIndex] || {};

            const patientInfo = {
                name: currentImage.patient_name || currentImage.patientName || 'Unknown Patient',
                id: currentImage.patient_id || currentImage.patientId || '',
                age: currentImage.patient_age || currentImage.patientAge || '',
                sex: currentImage.patient_sex || currentImage.patientSex || '',
                studyDate: currentImage.study_date || currentImage.studyDate || new Date().toLocaleDateString(),
                studyDescription: currentImage.study_description || currentImage.studyDescription || '',
                institution: this.hospitalSettings?.hospital_name || currentImage.institution_name || 'Medical Imaging Center'
            };

            this.updateLoadingProgress('Preparing print document...', 80);

            // Generate print HTML
            const printHTML = this.generateViewportPrintHTML(viewportState, patientInfo, previewOnly);

            // Open print window
            const printWindow = window.open('', '_blank', 'width=1200,height=900');
            if (!printWindow) {
                throw new Error('Please allow popups to print');
            }

            printWindow.document.write(printHTML);
            printWindow.document.close();

            this.updateLoadingProgress('Complete!', 100);

            // Auto-print if not preview
            if (!previewOnly) {
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            }

        } catch (error) {
            console.error('Viewport print error:', error);
            throw error;
        } finally {
            this.hideLoadingModal();
        }
    }

    /**
     * Print Current Layout - Captures the EXACT layout as seen in viewer using html2canvas
     * This ensures asymmetric layouts, custom grids, and all configurations print correctly
     */
    async printCurrentLayout(previewOnly = false) {
        this.showLoadingModal('Capturing current layout...', 0);

        try {
            const viewportContainer = document.getElementById('viewport-container');
            if (!viewportContainer) {
                throw new Error('Viewport container not found');
            }

            // Check if any images are loaded
            const viewports = viewportContainer.querySelectorAll('.viewport');
            let hasImages = false;
            for (const vp of viewports) {
                try {
                    const enabledElement = cornerstone.getEnabledElement(vp);
                    if (enabledElement && enabledElement.image) {
                        hasImages = true;
                        break;
                    }
                } catch (e) { }
            }

            if (!hasImages) {
                this.showToast('No images loaded to print', 'warning');
                this.hideLoadingModal();
                return;
            }

            this.updateLoadingProgress('Capturing viewport layout...', 30);

            // Use html2canvas to capture the exact layout
            const canvas = await html2canvas(viewportContainer, {
                backgroundColor: '#000000',
                scale: 2, // High resolution
                logging: false,
                useCORS: true,
                allowTaint: true,
                onclone: (clonedDoc) => {
                    // Fix cloned canvas elements by copying image data
                    const clonedContainer = clonedDoc.getElementById('viewport-container');
                    const originalViewports = viewportContainer.querySelectorAll('.viewport');
                    const clonedViewports = clonedContainer.querySelectorAll('.viewport');

                    originalViewports.forEach((origVp, idx) => {
                        const origCanvas = origVp.querySelector('canvas');
                        const clonedCanvas = clonedViewports[idx]?.querySelector('canvas');
                        if (origCanvas && clonedCanvas) {
                            const ctx = clonedCanvas.getContext('2d');
                            clonedCanvas.width = origCanvas.width;
                            clonedCanvas.height = origCanvas.height;
                            ctx.drawImage(origCanvas, 0, 0);
                        }
                    });

                    // HIDE ALL VIEWPORT OVERLAYS for clean print output
                    // Hide viewport-info (W/L, Zoom info)
                    clonedContainer.querySelectorAll('.viewport-info').forEach(el => {
                        el.style.display = 'none';
                    });
                    // Hide slice indicators
                    clonedContainer.querySelectorAll('.slice-indicator').forEach(el => {
                        el.style.display = 'none';
                    });
                    // Hide drawing overlays
                    clonedContainer.querySelectorAll('.drawing-overlay').forEach(el => {
                        el.style.display = 'none';
                    });
                    // Hide crosshairs
                    clonedContainer.querySelectorAll('.crosshair-overlay, .crosshair-line').forEach(el => {
                        el.style.display = 'none';
                    });
                    // Hide any other overlays
                    clonedContainer.querySelectorAll('.viewport-overlay').forEach(el => {
                        el.style.display = 'none';
                    });
                    // Remove viewport name pseudo-element labels by clearing data attribute
                    clonedViewports.forEach(vp => {
                        vp.removeAttribute('data-viewport-name');
                    });
                }
            });

            this.updateLoadingProgress('Generating print document...', 60);

            const imageDataUrl = canvas.toDataURL('image/png', 1.0);

            // Get patient info
            const state = window.DICOM_VIEWER.STATE;
            const currentImage = state.currentSeriesImages?.[state.currentImageIndex] || {};
            const patientInfo = {
                name: currentImage.patient_name || currentImage.patientName || 'Unknown Patient',
                id: currentImage.patient_id || currentImage.patientId || '',
                studyDate: currentImage.study_date || currentImage.studyDate || new Date().toLocaleDateString(),
                institution: this.hospitalSettings?.hospital_name || 'Medical Imaging Center'
            };

            // Count loaded viewports
            let loadedCount = 0;
            for (const vp of viewports) {
                try {
                    const ee = cornerstone.getEnabledElement(vp);
                    if (ee && ee.image) loadedCount++;
                } catch (e) { }
            }

            this.updateLoadingProgress('Opening print preview...', 80);

            // Generate simple single-image print HTML
            const printHTML = this.generateLayoutPrintHTML(imageDataUrl, patientInfo, loadedCount, previewOnly);

            const printWindow = window.open('', '_blank', 'width=1200,height=900');
            if (!printWindow) {
                throw new Error('Please allow popups to print');
            }

            printWindow.document.write(printHTML);
            printWindow.document.close();

            this.updateLoadingProgress('Complete!', 100);

            if (!previewOnly) {
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            }

        } catch (error) {
            console.error('Layout print error:', error);
            this.showToast('Print failed: ' + error.message, 'error');
        } finally {
            this.hideLoadingModal();
        }
    }

    /**
     * Generate HTML for layout screenshot print
     */
    generateLayoutPrintHTML(imageDataUrl, patientInfo, viewportCount, previewOnly) {
        const settings = this.printSettings;
        const marginValues = { none: 0, narrow: 5, normal: 10, wide: 20 };
        const margin = marginValues[settings.margins] || 10;

        return `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DICOM Print - ${patientInfo.name} - ${new Date().toLocaleDateString()}</title>
    <style>
        @page {
            size: ${settings.paperSize} ${settings.orientation};
            margin: ${margin}mm;
        }

        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: ${previewOnly ? '#2d2d2d' : '#fff'};
            color: ${previewOnly ? '#fff' : '#000'};
        }

        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .print-toolbar h4 {
            color: #fff;
            margin: 0;
            font-size: 16px;
        }

        .btn-print {
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-right: 10px;
        }

        .btn-close {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }

        .print-page {
            width: ${settings.paperSize === 'A4' ? (settings.orientation === 'landscape' ? '297mm' : '210mm') : (settings.orientation === 'landscape' ? '279mm' : '216mm')};
            min-height: ${settings.paperSize === 'A4' ? (settings.orientation === 'landscape' ? '210mm' : '297mm') : (settings.orientation === 'landscape' ? '216mm' : '279mm')};
            padding: 10mm;
            margin: ${previewOnly ? '60px auto 20px' : '0 auto'};
            background: #fff;
            color: #000;
            box-shadow: ${previewOnly ? '0 4px 20px rgba(0,0,0,0.4)' : 'none'};
        }

        .page-header {
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .hospital-info h2 { color: #0d6efd; font-size: 18px; margin-bottom: 3px; }
        .hospital-info p { font-size: 11px; color: #666; }
        .patient-info { text-align: right; font-size: 11px; }
        .patient-info strong { font-size: 13px; color: #333; }

        .layout-image {
            width: 100%;
            height: auto;
            max-height: ${settings.orientation === 'landscape' ? '150mm' : '220mm'};
            object-fit: contain;
            border: ${settings.borderEnabled ? `${settings.borderWidth || 2}px ${settings.borderStyle || 'solid'} ${settings.borderColor || '#000000'}` : 'none'};
            border-radius: 4px;
        }

        .page-footer {
            border-top: 1px solid #ddd;
            padding-top: 8px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>📄 Print Preview - Current Layout (${viewportCount} viewports)</h4>
        <div>
            <button class="btn-print" onclick="window.print()">🖨️ Print Now</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>
    ` : ''}

    <div class="print-page">
        <div class="page-header">
            <div class="header-content">
                <div class="hospital-info">
                    <h2>${patientInfo.institution}</h2>
                    <p>Medical Imaging Department</p>
                </div>
                <div class="patient-info">
                    <strong>${patientInfo.name}</strong><br>
                    ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                    Study: ${patientInfo.studyDate}
                </div>
            </div>
        </div>

        <img class="layout-image" src="${imageDataUrl}" alt="Current Layout">

        <div class="page-footer">
            <div>${viewportCount} viewport layout</div>
            <div>Printed: ${new Date().toLocaleString()}</div>
        </div>
    </div>
</body>
</html>`;
    }

    /**
     * Print All Images - Creates multi-page print preserving current layout
     * Uses html2canvas to capture each page-worth of images in the exact viewer layout
     */
    async printAllImages(layout, previewOnly = false) {
        const state = window.DICOM_VIEWER.STATE;
        const images = state.currentSeriesImages || [];

        if (images.length === 0) {
            this.showToast('No images to print', 'warning');
            return;
        }

        const viewportContainer = document.getElementById('viewport-container');
        const viewports = viewportContainer.querySelectorAll('.viewport');
        const viewportCount = viewports.length;

        if (viewportCount === 0) {
            this.showToast('No viewports found', 'warning');
            return;
        }

        const totalPages = Math.ceil(images.length / viewportCount);

        this.showLoadingModal(`Preparing ${images.length} images for printing (${totalPages} pages)...`, 0);

        try {
            // Get patient info
            const currentImage = images[0] || {};
            const patientInfo = {
                name: currentImage.patient_name || currentImage.patientName || 'Unknown Patient',
                id: currentImage.patient_id || currentImage.patientId || '',
                studyDate: currentImage.study_date || currentImage.studyDate || new Date().toLocaleDateString(),
                institution: this.hospitalSettings?.hospital_name || 'Medical Imaging Center'
            };

            // Capture screenshots of each page-worth of images
            const pageScreenshots = [];

            // Store original viewport images to restore later
            const originalImages = [];
            for (let i = 0; i < viewports.length; i++) {
                try {
                    const ee = cornerstone.getEnabledElement(viewports[i]);
                    if (ee && ee.image) {
                        originalImages[i] = ee.image.imageId;
                    }
                } catch (e) {
                    originalImages[i] = null;
                }
            }

            for (let page = 0; page < totalPages; page++) {
                const startIdx = page * viewportCount;
                const pageImages = images.slice(startIdx, startIdx + viewportCount);

                this.updateLoadingProgress(`Capturing page ${page + 1} of ${totalPages}...`, Math.round(((page + 1) / totalPages) * 70));

                // Load images into viewports for this page
                for (let i = 0; i < viewports.length; i++) {
                    const viewport = viewports[i];
                    const img = pageImages[i];

                    if (img) {
                        try {
                            let imageId = img.imageId || img.image_id;

                            if (!imageId && img.orthancInstanceId) {
                                const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
                                imageId = `wadouri:${basePath}/api/get_dicom_from_orthanc.php?instanceId=${img.orthancInstanceId}`;
                            }

                            if (!imageId && img.instanceId) {
                                const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
                                imageId = `wadouri:${basePath}/api/get_dicom_from_orthanc.php?instanceId=${img.instanceId}`;
                            }

                            if (imageId) {
                                const loadedImage = await cornerstone.loadImage(imageId);
                                await cornerstone.displayImage(viewport, loadedImage);
                            }
                        } catch (err) {
                            console.warn(`Error loading image ${i} for page ${page}:`, err);
                        }
                    } else {
                        // Clear viewport if no image for this slot
                        try {
                            cornerstone.reset(viewport);
                        } catch (e) { }
                    }
                }

                // Wait for all images to render
                await new Promise(resolve => setTimeout(resolve, 100));

                // Capture the viewport container using html2canvas
                try {
                    const canvas = await html2canvas(viewportContainer, {
                        backgroundColor: '#000000',
                        scale: 2,
                        logging: false,
                        useCORS: true,
                        allowTaint: true,
                        onclone: (clonedDoc) => {
                            const clonedContainer = clonedDoc.getElementById('viewport-container');
                            const clonedViewports = clonedContainer.querySelectorAll('.viewport');

                            viewports.forEach((origVp, idx) => {
                                const origCanvas = origVp.querySelector('canvas');
                                const clonedCanvas = clonedViewports[idx]?.querySelector('canvas');
                                if (origCanvas && clonedCanvas) {
                                    const ctx = clonedCanvas.getContext('2d');
                                    clonedCanvas.width = origCanvas.width;
                                    clonedCanvas.height = origCanvas.height;
                                    ctx.drawImage(origCanvas, 0, 0);
                                }
                            });

                            // HIDE ALL VIEWPORT OVERLAYS for clean print output
                            clonedContainer.querySelectorAll('.viewport-info').forEach(el => el.style.display = 'none');
                            clonedContainer.querySelectorAll('.slice-indicator').forEach(el => el.style.display = 'none');
                            clonedContainer.querySelectorAll('.drawing-overlay').forEach(el => el.style.display = 'none');
                            clonedContainer.querySelectorAll('.crosshair-overlay, .crosshair-line').forEach(el => el.style.display = 'none');
                            clonedContainer.querySelectorAll('.viewport-overlay').forEach(el => el.style.display = 'none');
                            // Remove viewport name pseudo-element labels
                            clonedViewports.forEach(vp => vp.removeAttribute('data-viewport-name'));
                        }
                    });

                    pageScreenshots.push({
                        dataUrl: canvas.toDataURL('image/png', 1.0),
                        pageNum: page + 1,
                        imageCount: pageImages.length
                    });
                } catch (err) {
                    console.error(`Error capturing page ${page + 1}:`, err);
                    pageScreenshots.push({
                        dataUrl: null,
                        pageNum: page + 1,
                        error: true
                    });
                }
            }

            // Restore original images in viewports
            this.updateLoadingProgress('Restoring original state...', 80);
            for (let i = 0; i < viewports.length && i < originalImages.length; i++) {
                if (originalImages[i]) {
                    try {
                        const loadedImage = await cornerstone.loadImage(originalImages[i]);
                        await cornerstone.displayImage(viewports[i], loadedImage);
                    } catch (e) { }
                }
            }

            this.updateLoadingProgress('Generating print document...', 90);

            // Generate multi-page HTML from screenshots
            const printHTML = this.generateAllImagesScreenshotHTML(pageScreenshots, patientInfo, viewportCount, previewOnly);

            const printWindow = window.open('', '_blank', 'width=1200,height=900');
            if (!printWindow) {
                throw new Error('Please allow popups to print');
            }

            printWindow.document.write(printHTML);
            printWindow.document.close();

            this.updateLoadingProgress('Complete!', 100);

            if (!previewOnly) {
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            }

        } catch (error) {
            console.error('All images print error:', error);
            throw error;
        } finally {
            this.hideLoadingModal();
        }
    }

    /**
     * Generate HTML for all images print using page screenshots
     */
    generateAllImagesScreenshotHTML(pageScreenshots, patientInfo, viewportCount, previewOnly) {
        const settings = this.printSettings;
        const marginValues = { none: 0, narrow: 5, normal: 10, wide: 20 };
        const margin = marginValues[settings.margins] || 10;
        const totalPages = pageScreenshots.length;

        const pagesHTML = pageScreenshots.map((page, idx) => {
            if (page.error || !page.dataUrl) {
                return `
                    <div class="print-page" data-page="${page.pageNum}">
                        <div class="page-header">
                            <div class="header-content">
                                <div class="hospital-info">
                                    <h2>${patientInfo.institution}</h2>
                                    <p>Medical Imaging Department</p>
                                </div>
                                <div class="patient-info">
                                    <strong>${patientInfo.name}</strong><br>
                                    ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                                    Study: ${patientInfo.studyDate}
                                </div>
                            </div>
                        </div>
                        <div class="error-page">
                            <i class="bi bi-exclamation-triangle"></i>
                            <p>Error capturing page ${page.pageNum}</p>
                        </div>
                        <div class="page-footer">
                            <div>Page ${page.pageNum} of ${totalPages}</div>
                            <div>Printed: ${new Date().toLocaleString()}</div>
                        </div>
                    </div>
                `;
            }

            return `
                <div class="print-page" data-page="${page.pageNum}">
                    <div class="page-header">
                        <div class="header-content">
                            <div class="hospital-info">
                                <h2>${patientInfo.institution}</h2>
                                <p>Medical Imaging Department</p>
                            </div>
                            <div class="patient-info">
                                <strong>${patientInfo.name}</strong><br>
                                ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                                Study: ${patientInfo.studyDate}
                            </div>
                        </div>
                    </div>
                    <img class="page-image" src="${page.dataUrl}" alt="Page ${page.pageNum}">
                    <div class="page-footer">
                        <div>Page ${page.pageNum} of ${totalPages} (${page.imageCount} images)</div>
                        <div>Printed: ${new Date().toLocaleString()}</div>
                    </div>
                </div>
            `;
        }).join('');

        return `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DICOM Print - All Images - ${patientInfo.name}</title>
    <style>
        @page {
            size: ${settings.paperSize} ${settings.orientation};
            margin: ${margin}mm;
        }

        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .print-page { page-break-after: always; margin-bottom: 0 !important; box-shadow: none !important; }
            .print-page:last-child { page-break-after: avoid; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: ${previewOnly ? '#2d2d2d' : '#fff'};
            color: ${previewOnly ? '#fff' : '#000'};
        }

        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .print-toolbar h4 { color: #fff; margin: 0; font-size: 16px; }
        
        .btn-print {
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-right: 10px;
        }

        .btn-close {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }

        .print-page {
            width: ${settings.paperSize === 'A4' ? (settings.orientation === 'landscape' ? '297mm' : '210mm') : (settings.orientation === 'landscape' ? '279mm' : '216mm')};
            min-height: ${settings.paperSize === 'A4' ? (settings.orientation === 'landscape' ? '210mm' : '297mm') : (settings.orientation === 'landscape' ? '216mm' : '279mm')};
            padding: 10mm;
            margin: ${previewOnly ? '60px auto 20px' : '0 auto'};
            background: #fff;
            color: #000;
            box-shadow: ${previewOnly ? '0 4px 20px rgba(0,0,0,0.4)' : 'none'};
        }

        .page-header {
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .hospital-info h2 { color: #0d6efd; font-size: 18px; margin-bottom: 3px; }
        .hospital-info p { font-size: 11px; color: #666; }
        .patient-info { text-align: right; font-size: 11px; }
        .patient-info strong { font-size: 13px; color: #333; }

        .page-image {
            width: 100%;
            height: auto;
            max-height: ${settings.orientation === 'landscape' ? '150mm' : '220mm'};
            object-fit: contain;
            border: ${settings.borderEnabled ? `${settings.borderWidth || 2}px ${settings.borderStyle || 'solid'} ${settings.borderColor || '#000000'}` : 'none'};
            border-radius: 4px;
        }

        .error-page {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 150mm;
            color: #dc3545;
            font-size: 18px;
        }

        .error-page i { font-size: 48px; margin-bottom: 15px; }

        .page-footer {
            border-top: 1px solid #ddd;
            padding-top: 8px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #666;
        }

        .page-nav {
            position: fixed;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            flex-direction: column;
            gap: 5px;
            z-index: 1001;
        }

        .page-nav-item {
            width: 30px;
            height: 30px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .page-nav-item:hover { background: rgba(13, 110, 253, 0.5); border-color: #0d6efd; }
    </style>
</head>
<body>
    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>📄 Print Preview - All Images on ${totalPages} Pages (${viewportCount}-viewport layout)</h4>
        <div>
            <button class="btn-print" onclick="window.print()">🖨️ Print All Pages</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>
    <div class="page-nav no-print">
        ${pageScreenshots.map((_, i) => `<div class="page-nav-item" title="Page ${i + 1}" onclick="document.querySelector('[data-page=\\'${i + 1}\\']').scrollIntoView({behavior:'smooth'})">${i + 1}</div>`).join('')}
    </div>
    ` : ''}

    ${pagesHTML}
</body>
</html>`;
    }

    /**
     * Generate HTML for all images print with multiple pages
     */
    generateAllImagesPrintHTML(capturedImages, patientInfo, layout, totalPages, previewOnly) {
        const settings = this.printSettings;
        const [rows, cols] = layout.split('x').map(Number);
        const imagesPerPage = rows * cols;

        const marginValues = { none: 0, narrow: 5, normal: 10, wide: 20 };
        const margin = marginValues[settings.margins] || 10;

        // Split images into pages
        const pages = [];
        for (let i = 0; i < capturedImages.length; i += imagesPerPage) {
            pages.push(capturedImages.slice(i, i + imagesPerPage));
        }

        // Generate page HTML for each page
        const pagesHTML = pages.map((pageImages, pageIndex) => {
            const cellsHTML = pageImages.map(img => {
                if (img.error || !img.dataUrl) {
                    return `<div class="viewport-cell error"><span>Image ${img.index}<br>Failed to load</span></div>`;
                }
                return `
                    <div class="viewport-cell">
                        <img src="${img.dataUrl}" alt="Image ${img.index}">
                        <div class="image-number">${img.index}</div>
                    </div>
                `;
            }).join('');

            // Add empty cells to fill the grid
            const emptyCells = imagesPerPage - pageImages.length;
            const emptyCellsHTML = emptyCells > 0 ?
                Array(emptyCells).fill('<div class="viewport-cell empty"></div>').join('') : '';

            return `
                <div class="print-page" data-page="${pageIndex + 1}">
                    <div class="page-header">
                        <div class="header-content">
                            <div class="hospital-info">
                                <h2>${patientInfo.institution}</h2>
                                <p>Medical Imaging Department</p>
                            </div>
                            <div class="patient-info">
                                <strong>${patientInfo.name}</strong><br>
                                ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                                ${patientInfo.accessionNumber ? `Acc#: ${patientInfo.accessionNumber}<br>` : ''}
                                Study: ${patientInfo.studyDate}
                            </div>
                        </div>
                    </div>

                    <div class="viewport-grid" style="grid-template-columns: repeat(${cols}, 1fr); grid-template-rows: repeat(${rows}, 1fr);">
                        ${cellsHTML}${emptyCellsHTML}
                    </div>

                    <div class="page-footer">
                        <div>Page ${pageIndex + 1} of ${totalPages}</div>
                        <div>Printed: ${new Date().toLocaleString()}</div>
                    </div>
                </div>
            `;
        }).join('');

        return `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DICOM Print - All Images - ${patientInfo.name}</title>
    <style>
        @page {
            size: ${settings.paperSize} ${settings.orientation};
            margin: ${margin}mm;
        }

        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .print-page { page-break-after: always; margin-bottom: 0 !important; box-shadow: none !important; }
            .print-page:last-child { page-break-after: avoid; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: ${previewOnly ? '#2d2d2d' : '#fff'};
            color: ${previewOnly ? '#fff' : '#000'};
        }

        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .print-toolbar h4 { color: #fff; font-weight: 600; margin: 0; }

        .print-toolbar button {
            padding: 12px 28px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-print {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: #fff;
            margin-right: 10px;
        }

        .btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(13, 110, 253, 0.4); }
        .btn-close { background: #6c757d; color: #fff; }

        .print-content {
            padding: ${previewOnly ? '80px 20px 20px' : '0'};
            max-height: ${previewOnly ? 'calc(100vh - 80px)' : 'none'};
            overflow-y: ${previewOnly ? 'auto' : 'visible'};
        }

        .print-page {
            background: #fff;
            color: #000;
            max-width: ${settings.orientation === 'landscape' ? '297mm' : '210mm'};
            min-height: ${settings.orientation === 'landscape' ? '200mm' : '280mm'};
            margin: ${previewOnly ? '0 auto 30px' : '0'};
            padding: 15px;
            box-shadow: ${previewOnly ? '0 4px 30px rgba(0, 0, 0, 0.5)' : 'none'};
            border-radius: ${previewOnly ? '8px' : '0'};
        }

        .page-header {
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .hospital-info h2 { color: #0d6efd; font-size: 18px; margin-bottom: 3px; }
        .hospital-info p { font-size: 11px; color: #666; }
        .patient-info { text-align: right; font-size: 11px; }
        .patient-info strong { font-size: 13px; color: #333; }

        .viewport-grid {
            display: grid;
            gap: 6px;
            height: ${settings.orientation === 'landscape' ? '155mm' : '230mm'};
        }

        .viewport-cell {
            position: relative;
            background: #000;
            border: ${settings.borderEnabled ? `${settings.borderWidth || 2}px ${settings.borderStyle || 'solid'} ${settings.borderColor || '#000000'}` : 'none'};
            border-radius: 4px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .viewport-cell img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .viewport-cell.empty { background: #1a1a1a; }
        .viewport-cell.error { background: #2d1d1d; color: #ff6b6b; font-size: 11px; }

        .image-number {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(0, 0, 0, 0.7);
            color: #00ff00;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Consolas', monospace;
        }

        .page-footer {
            border-top: 1px solid #ddd;
            padding-top: 8px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #666;
        }

        /* Page navigation for preview */
        .page-nav {
            position: fixed;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            flex-direction: column;
            gap: 5px;
            z-index: 1001;
        }

        .page-nav-item {
            width: 30px;
            height: 30px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .page-nav-item:hover { background: rgba(13, 110, 253, 0.5); border-color: #0d6efd; }
    </style>
</head>
<body>
    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>📄 Print Preview - ${capturedImages.length} Images on ${totalPages} Pages (${layout} Grid)</h4>
        <div>
            <button class="btn-print" onclick="window.print()">🖨️ Print All Pages</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>
    <div class="page-nav no-print">
        ${pages.map((_, i) => `<div class="page-nav-item" title="Page ${i + 1}" onclick="document.querySelector('[data-page=\\'${i + 1}\\']').scrollIntoView({behavior:'smooth'})">${i + 1}</div>`).join('')}
    </div>
    ` : ''}

    <div class="print-content">
        ${pagesHTML}
    </div>
</body>
</html>
        `;
    }
    generateViewportPrintHTML(viewportState, patientInfo, previewOnly) {
        const settings = this.printSettings;
        const [rows, cols] = viewportState.layout.split('x').map(Number);

        const marginValues = { none: 0, narrow: 5, normal: 10, wide: 20 };
        const margin = marginValues[settings.margins] || 10;

        return `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DICOM Print - ${patientInfo.name} - ${new Date().toLocaleDateString()}</title>
    <style>
        @page {
            size: ${settings.paperSize} ${settings.orientation};
            margin: ${margin}mm;
        }

        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: ${previewOnly ? '#2d2d2d' : '#fff'};
            color: ${previewOnly ? '#fff' : '#000'};
        }

        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 15px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .print-toolbar h4 {
            color: #fff;
            font-weight: 600;
            margin: 0;
        }

        .print-toolbar button {
            padding: 12px 28px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-print {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: #fff;
            margin-right: 10px;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(13, 110, 253, 0.4);
        }

        .btn-close {
            background: #6c757d;
            color: #fff;
        }

        .print-content {
            padding-top: ${previewOnly ? '80px' : '0'};
            padding: ${previewOnly ? '80px 20px 20px' : '0'};
        }

        .print-page {
            background: #fff;
            color: #000;
            max-width: ${settings.orientation === 'landscape' ? '297mm' : '210mm'};
            min-height: ${settings.orientation === 'landscape' ? '210mm' : '297mm'};
            margin: ${previewOnly ? '0 auto 20px' : '0'};
            padding: 20px;
            box-shadow: ${previewOnly ? '0 4px 20px rgba(0, 0, 0, 0.3)' : 'none'};
        }

        .page-header {
            display: ${settings.includeInstitutionInfo ? 'block' : 'none'};
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .hospital-info h2 {
            color: #0d6efd;
            font-size: 22px;
            margin-bottom: 5px;
        }

        .hospital-info p {
            font-size: 12px;
            color: #666;
        }

        .patient-info {
            text-align: right;
            font-size: 12px;
        }

        .patient-info strong {
            font-size: 14px;
            color: #333;
        }

        .viewport-grid {
            display: grid;
            grid-template-columns: repeat(${cols}, 1fr);
            grid-template-rows: repeat(${rows}, 1fr);
            gap: 10px;
            min-height: ${settings.orientation === 'landscape' ? '160mm' : '240mm'};
        }

        .viewport-cell {
            position: relative;
            background: #000;
            border: ${settings.borderEnabled ? `${settings.borderWidth || 2}px ${settings.borderStyle || 'solid'} ${settings.borderColor || '#000000'}` : 'none'};
            border-radius: 6px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .viewport-cell img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .viewport-overlay {
            position: absolute;
            color: #00ff00;
            font-size: 10px;
            font-family: 'Consolas', monospace;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.9);
            padding: 4px 8px;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 3px;
        }

        .overlay-top-left { top: 8px; left: 8px; }
        .overlay-top-right { top: 8px; right: 8px; text-align: right; }
        .overlay-bottom-left { bottom: 8px; left: 8px; }
        .overlay-bottom-right { bottom: 8px; right: 8px; text-align: right; }

        .page-footer {
            border-top: 2px solid #ddd;
            padding-top: 10px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>Print Preview - ${viewportState.viewports.length} viewports (${viewportState.layout} layout)</h4>
        <div>
            <button class="btn-print" onclick="window.print()">Print Now</button>
            <button class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>
    ` : ''}

    <div class="print-content">
        <div class="print-page">
            ${settings.includeInstitutionInfo ? `
            <div class="page-header">
                <div class="header-content">
                    <div class="hospital-info">
                        <h2>${patientInfo.institution}</h2>
                        <p>Medical Imaging Department</p>
                    </div>
                    ${settings.includePatientInfo ? `
                    <div class="patient-info">
                        <strong>${patientInfo.name}</strong><br>
                        ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                        ${patientInfo.age ? `Age: ${patientInfo.age}${patientInfo.sex ? ` | Sex: ${patientInfo.sex}` : ''}<br>` : ''}
                        ${patientInfo.studyDescription ? `Study: ${patientInfo.studyDescription}<br>` : ''}
                        Date: ${patientInfo.studyDate}
                    </div>
                    ` : ''}
                </div>
            </div>
            ` : ''}

            <div class="viewport-grid">
                ${viewportState.viewports.map(vp => `
                    <div class="viewport-cell">
                        <img src="${vp.dataUrl}" alt="${vp.name}">
                        ${settings.includePatientInfo ? `
                        <div class="viewport-overlay overlay-top-left">
                            ${vp.name}
                        </div>
                        ` : ''}
                        ${settings.includeWindowLevel && vp.windowWidth && vp.windowCenter ? `
                        <div class="viewport-overlay overlay-bottom-right">
                            W: ${Math.round(vp.windowWidth)} L: ${Math.round(vp.windowCenter)}
                        </div>
                        ` : ''}
                    </div>
                `).join('')}
            </div>

            <div class="page-footer">
                <span>${settings.includeTimestamp ? `Generated: ${new Date().toLocaleString()}` : ''}</span>
                <span>DICOM Viewer - Accurate Diagnostics</span>
                <span>For Medical Use Only</span>
            </div>
        </div>
    </div>
</body>
</html>
        `;
    }

    async printReport(reportId, previewOnly = false) {
        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const response = await fetch(`${basePath}/api/reports/by-id.php?id=${reportId}`);
            const data = await response.json();

            if (!data.success || !data.data) {
                throw new Error('Report not found');
            }

            const report = data.data;
            const printHTML = this.generateReportPrintHTML(report, previewOnly);

            const printWindow = window.open('', '_blank', 'width=900,height=1100');
            if (!printWindow) {
                throw new Error('Please allow popups to print');
            }

            printWindow.document.write(printHTML);
            printWindow.document.close();

            if (!previewOnly) {
                setTimeout(() => printWindow.print(), 500);
            }

        } catch (error) {
            console.error('Report print error:', error);
            throw error;
        }
    }

    generateReportPrintHTML(report, previewOnly) {
        const settings = this.printSettings;

        return `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Medical Report - ${report.patient_name}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm;
        }

        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: ${previewOnly ? '#f5f5f5' : '#fff'};
            color: #000;
            line-height: 1.6;
        }

        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #1a1a2e;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .print-toolbar h4 {
            color: #fff;
            margin: 0;
        }

        .print-toolbar button {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-print {
            background: #0d6efd;
            color: #fff;
            margin-right: 10px;
        }

        .btn-close {
            background: #6c757d;
            color: #fff;
        }

        .report-content {
            padding-top: ${previewOnly ? '80px' : '0'};
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
            padding: ${previewOnly ? '80px 40px 40px' : '40px'};
        }

        .report-header {
            text-align: center;
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .hospital-logo {
            max-width: 120px;
            max-height: 80px;
            margin-bottom: 15px;
        }

        .report-header h1 {
            color: #0d6efd;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .report-header p {
            color: #666;
            font-size: 14px;
        }

        .patient-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .patient-section h3 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
        }

        .patient-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .patient-field {
            font-size: 13px;
        }

        .patient-field strong {
            color: #555;
            display: block;
            margin-bottom: 2px;
        }

        .report-section {
            margin-bottom: 25px;
        }

        .report-section h4 {
            color: #0d6efd;
            font-size: 16px;
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 1px solid #dee2e6;
        }

        .report-section p {
            font-size: 13px;
            color: #333;
            white-space: pre-wrap;
            line-height: 1.8;
        }

        .report-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #dee2e6;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
        }

        .signature-box {
            text-align: center;
        }

        .signature-line {
            border-top: 2px solid #333;
            margin: 60px auto 10px;
            width: 200px;
        }

        .signature-box p {
            font-size: 12px;
            color: #666;
        }

        .report-meta {
            margin-top: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 11px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>Medical Report Preview</h4>
        <div>
            <button class="btn-print" onclick="window.print()">Print Report</button>
            <button class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>
    ` : ''}

    <div class="report-content">
        <div class="report-header">
            ${report.hospital_logo ? `<img src="${report.hospital_logo}" alt="Hospital Logo" class="hospital-logo">` : ''}
            <h1>${report.institution_name || 'Medical Imaging Center'}</h1>
            <p>Department of Radiology & Medical Imaging</p>
            <p>Diagnostic Imaging Report</p>
        </div>

        <div class="patient-section">
            <h3>Patient Information</h3>
            <div class="patient-grid">
                <div class="patient-field">
                    <strong>Patient Name:</strong>
                    ${report.patient_name || 'N/A'}
                </div>
                <div class="patient-field">
                    <strong>Patient ID:</strong>
                    ${report.patient_id || 'N/A'}
                </div>
                <div class="patient-field">
                    <strong>Age:</strong>
                    ${report.patient_age || 'N/A'}
                </div>
                <div class="patient-field">
                    <strong>Sex:</strong>
                    ${report.patient_sex || 'N/A'}
                </div>
                <div class="patient-field">
                    <strong>Study Date:</strong>
                    ${report.study_date || 'N/A'}
                </div>
                <div class="patient-field">
                    <strong>Accession Number:</strong>
                    ${report.accession_number || 'N/A'}
                </div>
            </div>
        </div>

        <div class="report-section">
            <h4>Clinical Information</h4>
            <p>${report.clinical_info || report.indication || 'No clinical information provided.'}</p>
        </div>

        <div class="report-section">
            <h4>Imaging Findings</h4>
            <p>${report.findings || 'No findings recorded.'}</p>
        </div>

        <div class="report-section">
            <h4>Impression</h4>
            <p>${report.impression || 'No impression recorded.'}</p>
        </div>

        ${report.recommendations ? `
        <div class="report-section">
            <h4>Recommendations</h4>
            <p>${report.recommendations}</p>
        </div>
        ` : ''}

        <div class="report-footer">
            <div class="signature-box">
                <div class="signature-line"></div>
                <p><strong>${report.radiologist_name || 'Radiologist'}</strong></p>
                <p>${report.radiologist_license || ''}</p>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <p><strong>Verified By</strong></p>
                <p>Date: ${new Date(report.updated_at || report.created_at).toLocaleDateString()}</p>
            </div>
        </div>

        <div class="report-meta">
            <p>Report ID: ${report.id} | Status: ${(report.status || 'draft').toUpperCase()}</p>
            <p>Generated: ${new Date().toLocaleString()} | This is an electronically generated report</p>
            <p><strong>FOR MEDICAL USE ONLY - CONFIDENTIAL</strong></p>
        </div>
    </div>
</body>
</html>
        `;
    }

    // Loading modal helpers
    showLoadingModal(message, progress) {
        let modal = document.getElementById('printLoadingModalV3');
        if (!modal) {
            const html = `
                <div id="printLoadingModalV3" class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background: rgba(0,0,0,0.8); z-index: 9999;">
                    <div class="bg-dark text-light p-4 rounded-3 text-center border border-primary" style="min-width: 350px;">
                        <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
                        <div id="printLoadingMessageV3" class="mb-3 fs-5">${message}</div>
                        <div class="progress" style="height: 8px;">
                            <div id="printLoadingProgressV3" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: ${progress}%"></div>
                        </div>
                        <small class="text-muted mt-2 d-block" id="printLoadingPercentV3">${progress}%</small>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', html);
        } else {
            this.updateLoadingProgress(message, progress);
        }
    }

    updateLoadingProgress(message, progress) {
        const messageEl = document.getElementById('printLoadingMessageV3');
        const progressEl = document.getElementById('printLoadingProgressV3');
        const percentEl = document.getElementById('printLoadingPercentV3');

        if (messageEl) messageEl.textContent = message;
        if (progressEl) progressEl.style.width = `${progress}%`;
        if (percentEl) percentEl.textContent = `${progress}%`;
    }

    hideLoadingModal() {
        const modal = document.getElementById('printLoadingModalV3');
        if (modal) modal.remove();
    }

    showToast(message, type = 'info') {
        if (typeof window.DICOM_VIEWER.showToast === 'function') {
            window.DICOM_VIEWER.showToast(message, type);
        } else {
            alert(message);
        }
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    if (!window.DICOM_VIEWER.MANAGERS) {
        window.DICOM_VIEWER.MANAGERS = {};
    }
    window.DICOM_VIEWER.MANAGERS.printManager = new window.DICOM_VIEWER.PrintManager();
    console.log('✓ Print Manager v3.0 initialized (Exact viewport capture + Report printing)');
});

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
        this.currentPrintJobId = null; // Track current print job
        this.init();
    }

    async init() {
        // Load print settings from server
        await this.loadPrintSettings();
        await this.loadPrinters();
        await this.loadHospitalSettings();
        this.setupPrintButton();
        this.setupKeyboardShortcuts();

        // Initialize print tracker if available
        this.printTracker = window.DICOM_VIEWER.printTracker || null;

        console.log('[PrintManager] Electron mode at init:', this.isElectron);
        console.log('[PrintManager] Custom print dialog available at init:', !!window.DICOM_VIEWER.customPrintDialog);

        // Listen for print requests from preview windows
        this.setupPreviewPrintListener();
    }

    /**
     * Get custom print dialog instance (always check latest value)
     * This ensures we pick up the dialog even if it wasn't ready during init
     */
    get customPrintDialog() {
        return window.DICOM_VIEWER.customPrintDialog || null;
    }

    /**
     * Check if running in Electron (always evaluate current state)
     * This ensures we detect Electron even if preload script loaded after PrintManager init
     */
    get isElectron() {
        return !!(window.electronAPI && window.electronAPI.isElectron);
    }

    /**
     * Setup listener for print requests from preview popup windows
     */
    setupPreviewPrintListener() {
        window.addEventListener('message', async (event) => {
            // Verify the message is from our preview window
            if (event.data && event.data.type === 'DICOM_PRINT_REQUEST') {
                console.log('[PrintManager] Received print request from preview window');
                console.log('[PrintManager]   - isElectron:', this.isElectron);
                console.log('[PrintManager]   - customPrintDialog available:', !!this.customPrintDialog);
                console.log('[PrintManager]   - customPrintDialog.isAvailable():', this.customPrintDialog?.isAvailable());

                const { htmlContent, printSettings } = event.data;

                if (this.isElectron && this.customPrintDialog && this.customPrintDialog.isAvailable()) {
                    // Bring main window to front so the modal is visible
                    console.log('[PrintManager] Bringing main window to front using Electron API...');

                    // Use Electron API to properly bring window to front at OS level
                    if (window.electronAPI && window.electronAPI.focusMainWindow) {
                        window.electronAPI.focusMainWindow().then(() => {
                            console.log('[PrintManager] Main window focused successfully');
                        }).catch(err => {
                            console.warn('[PrintManager] Failed to focus window:', err);
                        });
                    }
                    window.focus(); // Fallback for non-Electron

                    // Small delay to ensure window is focused before showing modal
                    setTimeout(() => {
                        // Use custom print dialog
                        this.customPrintDialog.show({
                            printSettings: {
                                ...this.getEffectivePrintSettings(),
                                ...(printSettings || {})
                            },
                            onPrint: async (result) => {
                                try {
                                    const printResult = await this.customPrintDialog.printContent(
                                        htmlContent,
                                        result.printerName,
                                        result.printSettings
                                    );

                                    // Notify the preview window of the result
                                    if (event.source && typeof event.source.postMessage === 'function') {
                                        event.source.postMessage({
                                            type: 'DICOM_PRINT_RESULT',
                                            success: printResult.success,
                                            error: printResult.error
                                        }, '*');
                                    }

                                    if (printResult.success) {
                                        this.updatePrintStatus('completed');
                                    }
                                } catch (error) {
                                    console.error('[PrintManager] Preview print error:', error);
                                }
                            }
                        });
                    }, 100); // 100ms delay to ensure window is focused
                } else {
                    // Not in Electron - show configuration required message
                    console.warn('[PrintManager] Not in Electron or custom dialog not available');
                    if (event.source && typeof event.source.postMessage === 'function') {
                        event.source.postMessage({
                            type: 'DICOM_PRINT_BLOCKED',
                            message: 'Printing requires authorized printer configuration'
                        }, '*');
                    }
                }
            }
        });
    }

    /**
     * Execute print with custom printer selection dialog (Electron only)
     * BLOCKS system print dialog - only allows printing through authorized printers
     *
     * @param {Window} printWindow - The window containing content to print
     * @param {Object} printSettings - Print settings (paperSize, orientation, etc.)
     * @param {Function} onSuccess - Callback on successful print
     * @param {Function} onError - Callback on print error
     */
    async executePrintWithCustomDialog(printWindow, printSettings = {}, onSuccess = null, onError = null) {
        // Log current state for debugging
        console.log('[PrintManager] executePrintWithCustomDialog called');
        console.log('[PrintManager]   - isElectron:', this.isElectron);
        console.log('[PrintManager]   - customPrintDialog available:', !!this.customPrintDialog);
        console.log('[PrintManager]   - window.electronAPI:', !!window.electronAPI);

        // ALWAYS use custom print dialog in Electron - never show system dialog
        if (this.isElectron && this.customPrintDialog) {
            console.log('[PrintManager] Using custom print dialog (blocking system dialog)');

            // Get the HTML content from the print window
            const htmlContent = printWindow.document.documentElement.outerHTML;

            // IF printer is already selected (e.g. from Print Options Modal), skip the dialog and print directly!
            if (printSettings.printerName) {
                console.log('[PrintManager] Printer pre-selected, executing direct silent print:', printSettings.printerName);

                try {
                    // Use CustomPrintDialog's helper if available, or call electronAPI directly
                    // Since customPrintDialog.printContent handles the API result logging, we can reuse it
                    // But wait, the original code used this.customPrintDialog.printContent inside onPrint
                    // Let's verify printContent first. Assuming it wraps printToPrinter.

                    const result = await window.electronAPI.printToPrinter({
                        printerName: printSettings.printerName,
                        htmlContent: htmlContent,
                        printSettings: {
                            ...this.getEffectivePrintSettings(),
                            ...printSettings
                        }
                    });

                    if (result.success) {
                        this.showToast('Document sent to printer successfully', 'success');
                        if (onSuccess) onSuccess();
                        this.updatePrintStatus('completed');
                    } else {
                        throw new Error(result.error || 'Unknown print error');
                    }
                } catch (error) {
                    console.error('[PrintManager] Direct print error:', error);
                    this.showToast('Print failed: ' + error.message, 'error');
                    if (onError) onError(error);
                    this.updatePrintStatus('failed', error.message);
                }
                return;
            }

            // Show custom printer selection dialog - this will block if no printers configured
            this.customPrintDialog.show({
                printSettings: {
                    ...this.getEffectivePrintSettings(),
                    ...printSettings
                },
                onPrint: async (result) => {
                    console.log('[PrintManager] Custom dialog print confirmed:', result);

                    try {
                        // Use Electron's silent print with selected printer
                        const printResult = await this.customPrintDialog.printContent(
                            htmlContent,
                            result.printerName,
                            result.printSettings
                        );

                        if (printResult.success) {
                            if (onSuccess) onSuccess();
                            this.updatePrintStatus('completed');
                        } else {
                            // DO NOT fall back to window.print() - show error instead
                            console.error('[PrintManager] Print failed:', printResult.error);
                            this.showToast('Print failed: ' + (printResult.error || 'Unknown error'), 'error');
                            if (onError) onError(printResult.error);
                            this.updatePrintStatus('failed', printResult.error);
                        }
                    } catch (error) {
                        console.error('[PrintManager] Print error:', error);
                        this.showToast('Print error: ' + error.message, 'error');
                        if (onError) onError(error.message);
                        this.updatePrintStatus('failed', error.message);
                    }
                },
                onCancel: () => {
                    console.log('[PrintManager] Print cancelled by user');
                }
            });
        } else if (this.isElectron) {
            // Electron but custom dialog not ready - show warning
            console.warn('[PrintManager] Custom print dialog not initialized');
            this.showToast('Print system not ready. Please try again.', 'warning');
        } else {
            // Web browser - allow system print dialog (non-Electron environments)
            console.log('[PrintManager] Web browser - using system print dialog');
            printWindow.print();
            if (onSuccess) onSuccess();
        }
    }

    /**
     * Direct print using Electron (no preview window)
     * @param {string} htmlContent - Full HTML document to print
     * @param {Object} printSettings - Print settings
     */
    async directPrintWithDialog(htmlContent, printSettings = {}) {
        if (this.isElectron && this.customPrintDialog && this.customPrintDialog.isAvailable()) {
            console.log('[PrintManager] Direct print with custom dialog');

            this.customPrintDialog.show({
                printSettings: {
                    ...this.getEffectivePrintSettings(),
                    ...printSettings
                },
                onPrint: async (result) => {
                    try {
                        const printResult = await this.customPrintDialog.printContent(
                            htmlContent,
                            result.printerName,
                            result.printSettings
                        );

                        if (printResult.success) {
                            this.showToast('Print job sent successfully', 'success');
                            this.updatePrintStatus('completed');
                        } else {
                            this.showToast('Print failed: ' + printResult.error, 'error');
                            this.updatePrintStatus('failed', printResult.error);
                        }
                    } catch (error) {
                        console.error('[PrintManager] Direct print error:', error);
                        this.showToast('Print error: ' + error.message, 'error');
                    }
                },
                onCancel: () => {
                    console.log('[PrintManager] Print cancelled');
                }
            });
        } else {
            // Fallback: Open in new window and print
            const printWindow = window.open('', '_blank', 'width=1200,height=900');
            if (printWindow) {
                printWindow.document.write(htmlContent);
                printWindow.document.close();
                setTimeout(() => printWindow.print(), 500);
            }
        }
    }

    /**
     * Show print preview in an in-window modal (no popup)
     * Professional design inspired by Google Docs print preview
     * @param {string} previewContent - HTML content for the preview (just the pages, not full document)
     * @param {Object} options - Preview options
     * @param {string} options.title - Title for the preview modal
     * @param {number} options.totalPages - Number of pages in the preview
     * @param {Object} options.printSettings - Print settings to use when printing
     * @param {Function} options.onPrint - Optional callback before print
     */
    showPreviewModal(previewContent, options = {}) {
        const {
            title = 'Print Preview',
            totalPages = 1,
            printSettings = {},
            onPrint = null
        } = options;

        // Remove existing modal if present
        const existingModal = document.getElementById('printPreviewModal');
        if (existingModal) existingModal.remove();

        const settings = this.getEffectivePrintSettings();
        const mergedSettings = { ...settings, ...printSettings };
        const isLandscape = mergedSettings.orientation === 'landscape';

        // Paper aspect ratios (A4: 210x297mm)
        // Landscape: width > height (1.414:1)
        // Portrait: height > width (1:1.414)
        const paperAspectRatio = isLandscape ? (297 / 210) : (210 / 297);

        const modalHTML = `
            <div class="modal fade" id="printPreviewModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-fullscreen">
                    <div class="modal-content" style="background: #525659;">
                        <!-- Top Toolbar -->
                        <div class="preview-toolbar" style="
                            background: #333;
                            padding: 12px 24px;
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            border-bottom: 1px solid #444;
                            flex-shrink: 0;
                        ">
                            <!-- Left: Title and info -->
                            <div class="d-flex align-items-center gap-3">
                                <button type="button" class="btn btn-link text-white p-0" data-bs-dismiss="modal" style="font-size: 24px; line-height: 1;">
                                    <i class="bi bi-arrow-left"></i>
                                </button>
                                <div>
                                    <h6 class="mb-0 text-white fw-normal">${title}</h6>
                                    <small class="text-secondary">${mergedSettings.paperSize} • ${isLandscape ? 'Landscape' : 'Portrait'}</small>
                                </div>
                            </div>

                            <!-- Center: Page navigation -->
                            <div class="d-flex align-items-center gap-3" style="background: rgba(255,255,255,0.1); padding: 6px 16px; border-radius: 8px;">
                                <button class="btn btn-link text-white p-1" id="prevPageBtn" ${totalPages <= 1 ? 'disabled' : ''}>
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <span class="text-white" style="min-width: 80px; text-align: center;">
                                    <span id="currentPageNum">1</span> / ${totalPages}
                                </span>
                                <button class="btn btn-link text-white p-1" id="nextPageBtn" ${totalPages <= 1 ? 'disabled' : ''}>
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>

                            <!-- Right: Actions -->
                            <div class="d-flex align-items-center gap-2">
                                <div class="btn-group me-2" role="group">
                                    <button type="button" class="btn btn-outline-light btn-sm" id="zoomOutBtn" title="Zoom Out">
                                        <i class="bi bi-dash-lg"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-light btn-sm" id="zoomResetBtn" title="Reset Zoom">
                                        <span id="zoomLevel">100%</span>
                                    </button>
                                    <button type="button" class="btn btn-outline-light btn-sm" id="zoomInBtn" title="Zoom In">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-primary px-4" id="previewPrintBtn">
                                    <i class="bi bi-printer-fill me-2"></i>Print
                                </button>
                            </div>
                        </div>

                        <!-- Preview Area -->
                        <div class="modal-body p-0" style="overflow-y: auto; background: #525659;">
                            <div class="preview-scroll-container" style="
                                display: flex;
                                flex-direction: column;
                                align-items: center;
                                padding: 40px 20px;
                                gap: 40px;
                            " id="previewScrollContainer">
                                ${previewContent}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <style>
                /* Preview container styles */
                #printPreviewModal .preview-scroll-container {
                    min-height: 100%;
                }
                
                /* Paper page styles - proper aspect ratio */
                #printPreviewModal .print-page {
                    background: #fff;
                    color: #000;
                    position: relative;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.3), 0 10px 40px rgba(0,0,0,0.2);
                    transition: transform 0.2s ease;
                    
                    /* Proper paper dimensions based on orientation */
                    ${isLandscape ? `
                        /* Landscape: wider than tall */
                        width: min(90vw, 1100px);
                        aspect-ratio: 1.414 / 1;
                    ` : `
                        /* Portrait: taller than wide */
                        width: min(70vw, 700px);
                        aspect-ratio: 1 / 1.414;
                    `}
                }
                
                #printPreviewModal .print-page:hover {
                    box-shadow: 0 4px 15px rgba(0,0,0,0.4), 0 15px 50px rgba(0,0,0,0.3);
                }

                /* Inner page content wrapper */
                #printPreviewModal .page-inner {
                    padding: ${isLandscape ? '20px 30px' : '25px'};
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                }
                
                /* Page header */
                #printPreviewModal .page-header {
                    border-bottom: 2px solid #2563eb;
                    padding-bottom: 12px;
                    margin-bottom: 12px;
                    flex-shrink: 0;
                }
                
                #printPreviewModal .header-content {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    gap: 20px;
                }
                
                #printPreviewModal .hospital-info h2 { 
                    color: #1e40af; 
                    font-size: ${isLandscape ? '18px' : '20px'}; 
                    margin: 0 0 4px 0; 
                    font-weight: 700;
                    letter-spacing: -0.5px;
                }
                #printPreviewModal .hospital-info p { 
                    font-size: 11px; 
                    color: #64748b; 
                    margin: 0; 
                }
                #printPreviewModal .patient-info { 
                    text-align: right; 
                    font-size: 11px;
                    color: #475569;
                    line-height: 1.6;
                }
                #printPreviewModal .patient-info strong { 
                    font-size: 14px; 
                    color: #1e293b;
                    display: block;
                    margin-bottom: 2px;
                }

                /* Image content area */
                #printPreviewModal .page-content {
                    flex: 1;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    overflow: hidden;
                    background: #000;
                    border-radius: 4px;
                }
                
                #printPreviewModal .page-image {
                    max-width: 100%;
                    max-height: 100%;
                    width: auto;
                    height: auto;
                    object-fit: contain;
                }

                #printPreviewModal .viewport-grid {
                    display: grid;
                    gap: 2px;
                    width: 100%;
                    height: 100%;
                    background: #000;
                }
                
                #printPreviewModal .viewport-cell {
                    background: #000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    overflow: hidden;
                }
                
                #printPreviewModal .viewport-cell img {
                    max-width: 100%;
                    max-height: 100%;
                    object-fit: contain;
                }
                
                /* Page footer */
                #printPreviewModal .page-footer {
                    border-top: 1px solid #e2e8f0;
                    padding-top: 8px;
                    margin-top: 12px;
                    display: flex;
                    justify-content: space-between;
                    font-size: 10px;
                    color: #94a3b8;
                    flex-shrink: 0;
                }
                
                /* Page number badge */
                #printPreviewModal .page-number-badge {
                    position: absolute;
                    top: 0;
                    right: 0;
                    background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
                    color: #fff;
                    padding: 6px 16px;
                    font-size: 12px;
                    font-weight: 600;
                    border-radius: 0 0 0 8px;
                }

                /* Toolbar button styles */
                #printPreviewModal .btn-outline-light:disabled {
                    opacity: 0.3;
                }

                /* Zoom animation */
                #printPreviewModal .preview-scroll-container.zooming .print-page {
                    transition: transform 0.15s ease;
                }

                /* Responsive adjustments */
                @media (max-height: 700px) {
                    #printPreviewModal .print-page {
                        ${isLandscape ? 'width: min(95vw, 900px);' : 'width: min(80vw, 550px);'}
                    }
                }
            </style>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modal = new bootstrap.Modal(document.getElementById('printPreviewModal'));
        modal.show();

        // Store reference for cleanup
        this.currentPreviewModal = modal;
        this.pendingPrintContent = previewContent;
        this.pendingPrintSettings = mergedSettings;

        // Setup zoom functionality
        let currentZoom = 100;
        const zoomLevelEl = document.getElementById('zoomLevel');
        const scrollContainer = document.getElementById('previewScrollContainer');
        const pages = scrollContainer.querySelectorAll('.print-page');

        const updateZoom = (newZoom) => {
            currentZoom = Math.min(Math.max(newZoom, 50), 150);
            zoomLevelEl.textContent = `${currentZoom}%`;
            pages.forEach(page => {
                page.style.transform = `scale(${currentZoom / 100})`;
                page.style.transformOrigin = 'center top';
            });
        };

        document.getElementById('zoomInBtn').addEventListener('click', () => updateZoom(currentZoom + 10));
        document.getElementById('zoomOutBtn').addEventListener('click', () => updateZoom(currentZoom - 10));
        document.getElementById('zoomResetBtn').addEventListener('click', () => updateZoom(100));

        // Setup page navigation
        let currentPage = 1;
        const currentPageNumEl = document.getElementById('currentPageNum');
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');

        const scrollToPage = (pageNum) => {
            const targetPage = pages[pageNum - 1];
            if (targetPage) {
                targetPage.scrollIntoView({ behavior: 'smooth', block: 'center' });
                currentPage = pageNum;
                currentPageNumEl.textContent = pageNum;
                prevBtn.disabled = pageNum <= 1;
                nextBtn.disabled = pageNum >= totalPages;
            }
        };

        prevBtn.addEventListener('click', () => scrollToPage(currentPage - 1));
        nextBtn.addEventListener('click', () => scrollToPage(currentPage + 1));

        // Track scroll position to update current page indicator
        scrollContainer.addEventListener('scroll', () => {
            const containerRect = scrollContainer.getBoundingClientRect();
            const containerCenter = containerRect.top + containerRect.height / 2;

            let closestPage = 1;
            let closestDistance = Infinity;

            pages.forEach((page, index) => {
                const pageRect = page.getBoundingClientRect();
                const pageCenter = pageRect.top + pageRect.height / 2;
                const distance = Math.abs(pageCenter - containerCenter);

                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestPage = index + 1;
                }
            });

            if (closestPage !== currentPage) {
                currentPage = closestPage;
                currentPageNumEl.textContent = closestPage;
                prevBtn.disabled = closestPage <= 1;
                nextBtn.disabled = closestPage >= totalPages;
            }
        });

        // Setup print button handler
        document.getElementById('previewPrintBtn').addEventListener('click', async () => {
            // Execute optional callback
            if (onPrint && typeof onPrint === 'function') {
                await onPrint();
            }

            // Generate full HTML document for printing
            const fullPrintHTML = this.generatePrintableHTML(previewContent, mergedSettings);

            // Hide preview modal
            modal.hide();

            // Show printer selection dialog
            await this.directPrintWithDialog(fullPrintHTML, mergedSettings);
        });

        // Cleanup on modal hidden
        document.getElementById('printPreviewModal').addEventListener('hidden.bs.modal', () => {
            document.getElementById('printPreviewModal')?.remove();
            this.currentPreviewModal = null;
            this.pendingPrintContent = null;
            this.pendingPrintSettings = null;
        });
    }

    /**
     * Generate full printable HTML document from preview content
     * @param {string} previewContent - The preview pages HTML
     * @param {Object} settings - Print settings
     * @returns {string} Full HTML document
     */
    generatePrintableHTML(previewContent, settings) {
        const marginValues = { none: 0, narrow: 5, normal: 10, wide: 20 };
        const margin = marginValues[settings.margins] || 10;

        return `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DICOM Print</title>
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
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fff; color: #000; }
        
        .print-page {
            background: #fff;
            color: #000;
            max-width: ${settings.orientation === 'landscape' ? '297mm' : '210mm'};
            min-height: ${settings.orientation === 'landscape' ? '200mm' : '280mm'};
            margin: 0 auto;
            padding: 15px;
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
        .hospital-info p { font-size: 11px; color: #666; margin: 0; }
        .patient-info { text-align: right; font-size: 11px; }
        .patient-info strong { font-size: 13px; color: #333; }
        
        .page-image {
            width: 100%;
            max-height: ${settings.orientation === 'landscape' ? '160mm' : '220mm'};
            object-fit: contain;
        }
        
        .viewport-grid {
            display: grid;
            gap: 2px;
            height: ${settings.orientation === 'landscape' ? '155mm' : '230mm'};
            background: transparent;
        }
        
        .viewport-cell {
            position: relative;
            background: #000;
            border: ${settings.borderEnabled ? `${settings.borderWidth || 2}px ${settings.borderStyle || 'solid'} ${settings.borderColor || '#000000'}` : 'none'};
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
    ${previewContent}
</body>
</html>`;
    }


    /**
     * Track a print job for billing and analytics
     * @param {Object} printInfo Print job information
     * @returns {Promise<Object>} Tracking result
     */
    async trackPrint(printInfo) {
        console.log('[DEBUG] trackPrint called with:', printInfo);

        // Lazily get printTracker in case it wasn't ready during init()
        if (!this.printTracker) {
            this.printTracker = window.DICOM_VIEWER.printTracker;
            console.log('[DEBUG] Fetched printTracker from window:', this.printTracker);
        }

        if (!this.printTracker) {
            console.warn('[DEBUG] PrintTracker not available - window.DICOM_VIEWER.printTracker is:', window.DICOM_VIEWER.printTracker);
            return { success: false, error: 'Tracker not initialized' };
        }

        try {
            // Get current study/patient info
            const state = window.DICOM_VIEWER.STATE || {};
            const currentImage = state.currentSeriesImages?.[state.currentImageIndex] || {};
            console.log('[DEBUG] Current state:', { state, currentImage });

            const trackingData = {
                study_uid: currentImage.study_instance_uid || currentImage.studyInstanceUid || null,
                patient_id: currentImage.patient_id || currentImage.patientId || null,
                patient_name: currentImage.patient_name || currentImage.patientName || null,
                paper_size: printInfo.paperSize || this.printSettings?.paperSize || 'A4',
                orientation: printInfo.orientation || this.getEffectivePrintSettings()?.orientation || 'landscape',
                copies: printInfo.copies || 1,
                pages_per_copy: printInfo.pagesPerCopy || 1,
                total_pages: printInfo.totalPages || 1,
                color_mode: printInfo.colorMode || this.printSettings?.colorMode || 'grayscale',
                quality: printInfo.quality || this.printSettings?.quality || 'high',
                printer_name: printInfo.printerName || this.getSelectedPrinterName() || 'Default',
                printer_type: printInfo.printerType || 'local',
                layout_type: printInfo.layoutType || '1x1',
                print_type: printInfo.printType || 'image', // 'image' or 'report'
                include_patient_info: printInfo.includePatientInfo ?? (this.printSettings?.includePatientInfo ? 1 : 0),
                include_annotations: printInfo.includeAnnotations ?? (this.printSettings?.includeAnnotations ? 1 : 0),
                include_measurements: printInfo.includeMeasurements ?? (this.printSettings?.includeMeasurements ? 1 : 0)
            };

            console.log('[DEBUG] Calling printTracker.logPrint with:', trackingData);

            const result = await this.printTracker.logPrint(trackingData);
            console.log('[DEBUG] printTracker.logPrint returned:', result);

            if (result.success) {
                this.currentPrintJobId = result.print_job_id;
                console.log('[DEBUG] Print tracked successfully:', result.print_job_id, result.offline ? '(offline)' : '');

                // Dispatch event for real-time badge update
                document.dispatchEvent(new CustomEvent('printJobQueued', {
                    detail: {
                        print_job_id: result.print_job_id,
                        cost: result.cost
                    }
                }));
            } else {
                console.warn('[DEBUG] Print tracking failed:', result);
            }

            return result;
        } catch (error) {
            console.error('[DEBUG] Failed to track print:', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Update print job status
     * @param {string} status Print status (completed, failed, cancelled)
     * @param {string} errorMessage Optional error message
     */
    async updatePrintStatus(status, errorMessage = null) {
        if (!this.printTracker || !this.currentPrintJobId) return;

        try {
            await this.printTracker.updatePrintStatus(this.currentPrintJobId, status, errorMessage);

            // Dispatch event for real-time badge update
            document.dispatchEvent(new CustomEvent('printJobCompleted', {
                detail: {
                    print_job_id: this.currentPrintJobId,
                    status: status,
                    error: errorMessage
                }
            }));
        } catch (error) {
            console.error('Failed to update print status:', error);
        }
    }

    /**
     * Get selected printer name
     */
    getSelectedPrinterName() {
        if (this.selectedPrinter && this.availablePrinters.length > 0) {
            const printer = this.availablePrinters.find(p => p.id == this.selectedPrinter);
            return printer?.name || 'Default';
        }
        return 'Default';
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
                    orientation: 'auto', // Auto-detect based on layout
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
            orientation: 'auto', // Auto-detect based on layout
            quality: 'high',
            colorMode: 'grayscale',
            // Border settings
            borderEnabled: true,
            borderColor: '#000000',
            borderWidth: 2,
            borderStyle: 'solid'
        };
    }

    /**
     * Auto-detect optimal print orientation based on current layout
     * Portrait layouts: 6, 8, 15 spots (more rows than columns)
     * Landscape layouts: 1, 2, 4, 9, 12, 16 spots (more columns than rows or square)
     * @returns {string} 'portrait' or 'landscape'
     */
    detectOptimalOrientation() {
        const viewportContainer = document.getElementById('viewport-container');
        if (!viewportContainer) {
            return 'landscape'; // Default fallback
        }

        // Check layout class first
        const containerClasses = viewportContainer.className;

        // Portrait layouts: 6 (2x3), 8 (2x4), 15 (3x5), 18 (6x3) - more rows than columns
        // Also includes 2 (1x2) as requested by user ("2 layout must be in portrait")
        // Also includes any custom grid marked as 'advanced-grid-layout'
        const portraitLayouts = ['layout-spots-2', 'layout-spots-6', 'layout-spots-8', 'layout-spots-15', 'layout-spots-18', 'advanced-grid-layout'];
        for (const layoutClass of portraitLayouts) {
            if (containerClasses.includes(layoutClass)) {
                console.log(`Auto-detected portrait orientation for ${layoutClass}`);
                return 'portrait';
            }
        }

        // Landscape layouts: 1, 4, 9, 12, 16 - square or more columns than rows
        const landscapeLayouts = ['layout-spots-1', 'layout-spots-4', 'layout-spots-9', 'layout-spots-12', 'layout-spots-16'];
        for (const layoutClass of landscapeLayouts) {
            if (containerClasses.includes(layoutClass)) {
                console.log(`Auto-detected landscape orientation for ${layoutClass}`);
                return 'landscape';
            }
        }

        // Fallback: Analyze grid dimensions
        const containerStyles = window.getComputedStyle(viewportContainer);
        const gridCols = containerStyles.gridTemplateColumns.split(' ').filter(s => s.trim()).length || 2;
        const gridRows = containerStyles.gridTemplateRows.split(' ').filter(s => s.trim()).length || 2;

        // Special case for custom 2x1 or 1x2 grids if not caught by classes
        if (gridCols === 2 && gridRows === 1) return 'portrait'; // Force 2-up to portrait per user request

        // More rows than columns = portrait, otherwise landscape
        const orientation = gridRows > gridCols ? 'portrait' : 'landscape';
        console.log(`Auto-detected ${orientation} orientation from grid: ${gridCols}x${gridRows}`);
        return orientation;
    }

    /**
     * Get effective print settings with auto-detected orientation
     * @returns {Object} Print settings with resolved orientation
     */
    getEffectivePrintSettings() {
        const settings = { ...this.printSettings };

        // Auto-detect orientation if set to 'auto' or 'landscape' (legacy default)
        if (settings.orientation === 'auto' || !settings.orientation) {
            settings.orientation = this.detectOptimalOrientation();
        }

        return settings;
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

                            <!-- Print Info -->
                            <div class="alert alert-info mb-4">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-images fs-4 me-3"></i>
                                    <div>
                                        <strong>Print All ${state.currentSeriesImages?.length || 0} Images</strong><br>
                                        <small>Uses native print dialog - select pages, copies, printer like Word/Excel</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Special Print Options -->
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-file-earmark me-2"></i>Or choose special print type:
                            </h6>
                            <div class="row g-3 mb-4">
                                <input type="radio" name="printType" value="allImages" checked hidden id="printTypeImages">
                                <div class="col-md-6">
                                    <div class="print-type-card" data-type="report">
                                        <input type="radio" name="printType" value="report" hidden>
                                        <i class="bi bi-file-medical fs-3 text-success mb-2"></i>
                                        <h6 class="mb-1">Medical Report</h6>
                                        <small class="text-muted" id="reportStatus">Checking...</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="print-type-card" data-type="pcpndt">
                                        <input type="radio" name="printType" value="pcpndt" hidden>
                                        <i class="bi bi-file-earmark-medical fs-3 text-info mb-2"></i>
                                        <h6 class="mb-1">PCPNDT Form F</h6>
                                        <small class="text-muted">Compliance print form</small>
                                    </div>
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
                                    Orientation: <strong>${this.printSettings.orientation === 'auto' ? 'Auto (' + this.detectOptimalOrientation() + ')' : this.printSettings.orientation}</strong> |
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
                    if (allImagesOptions) allImagesOptions.style.display = 'block';
                    const selectedLayout = document.querySelector('.layout-option.selected')?.dataset.layout || '2x2';
                    updatePageCalculation(selectedLayout);
                } else {
                    if (allImagesOptions) allImagesOptions.style.display = 'none';
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

        // Preview button - hide print settings modal first, then show preview
        document.getElementById('printPreviewBtnV3')?.addEventListener('click', () => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('printDialogV3'));
            if (modal) modal.hide();
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
            } else if (printType === 'pcpndt') {
                // PCPNDT Form F printing
                await this.printPcpndtFormF(previewOnly);
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

            // Auto-print if not preview - use custom dialog for Electron
            if (!previewOnly) {
                setTimeout(() => {
                    this.executePrintWithCustomDialog(printWindow, this.getEffectivePrintSettings());
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
     * Print Current Layout - Entry point for printing the current view
     * Opens the options modal for the user to choose print type
     */
    async printCurrentLayout(previewOnly = false) {
        try {
            // 1. Get current state info
            const viewportContainer = document.getElementById('viewport-container');
            if (!viewportContainer) throw new Error('Viewport container not found');

            // Count loaded viewports
            const viewports = viewportContainer.querySelectorAll('.viewport');
            let loadedCount = 0;
            for (const vp of viewports) {
                try {
                    const ee = cornerstone.getEnabledElement(vp);
                    if (ee && ee.image) loadedCount++;
                } catch (e) { }
            }

            if (loadedCount === 0) {
                this.showToast('No images loaded to print', 'warning');
                return;
            }

            // 2. Get patient info
            const patientInfo = this.getCurrentPatientInfo();

            // 3. Get available printers (async)
            let printers = [];
            if (this.customPrintDialog) {
                // Match hospital printers with system printers to get status
                try {
                    const matched = await this.customPrintDialog.matchPrinters();
                    printers = matched;
                } catch (e) {
                    console.warn('Failed to load/match printers', e);
                    // Fallback to hospital printers if match fails
                    if (this.customPrintDialog.hospitalPrinters) {
                        printers = this.customPrintDialog.hospitalPrinters;
                    }
                }
            }

            // 4. Show the new Options Modal
            this.showPrintOptionsModal(patientInfo, loadedCount, printers);

        } catch (error) {
            console.error('Print Layout Error:', error);
            this.showToast('Failed to initialize print: ' + error.message, 'error');
        }
    }

    /**
     * Helper: Get current patient info from viewer state
     */
    getCurrentPatientInfo() {
        const state = window.DICOM_VIEWER.STATE || {};
        const currentImage = state.currentSeriesImages?.[state.currentImageIndex] || {};
        return {
            name: currentImage.patient_name || currentImage.patientName || 'Unknown Patient',
            id: currentImage.patient_id || currentImage.patientId || '',
            studyDate: currentImage.study_date || currentImage.studyDate || new Date().toLocaleDateString(),
            institution: this.hospitalSettings?.hospital_name || 'Medical Imaging Center'
        };
    }

    /**
     * Helper: Capture the current viewport container using html2canvas
     */
    async captureViewportSnapshot(viewportContainer) {
        return new Promise(async (resolve, reject) => {
            try {
                this.showLoadingModal('Capturing snapshot...', 30);

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

                        // HIDE ALL OVERLAYS for clean print
                        const selectorsToHide = [
                            '.viewport-info', '.slice-indicator', '.drawing-overlay',
                            '.crosshair-container', '.crosshair-h', '.crosshair-v', '.crosshair',
                            '.crosshair-overlay', '.crosshair-line', '.viewport-overlay',
                            '.empty-viewport-indicator', '.image-page-number', '.page-indicator'
                        ];

                        selectorsToHide.forEach(sel => {
                            clonedContainer.querySelectorAll(sel).forEach(el => el.style.display = 'none');
                        });

                        // Handle empty viewports
                        clonedViewports.forEach(vp => {
                            if (vp.querySelector('.empty-viewport-indicator') || vp.textContent.includes('Drop image here') || vp.dataset.isEmpty === 'true') {
                                vp.innerHTML = '';
                                vp.style.background = '#000000';
                                vp.style.backgroundImage = 'none';
                                vp.style.border = '1px solid #333';
                            }
                            // Remove selection styles
                            vp.classList.remove('active', 'selected');
                            vp.style.outline = 'none';
                            vp.style.boxShadow = 'none';
                            vp.style.border = 'none';
                            vp.removeAttribute('data-viewport-name');
                        });
                    }
                });

                this.updateLoadingProgress('Snapshot ready', 100);
                this.hideLoadingModal();
                resolve(canvas.toDataURL('image/png', 1.0));

            } catch (error) {
                this.hideLoadingModal();
                reject(error);
            }
        });
    }

    /**
     * Show the redesigned Print Options Modal
     */
    showPrintOptionsModal(patientInfo, loadedCount, printers = []) {
        // Remove existing modal if any
        const existingModal = document.getElementById('printOptionsModal');
        if (existingModal) existingModal.remove();

        const printerOptions = printers.map(p =>
            `<option value="${p.printer_name}">${p.display_name || p.printer_name}</option>`
        ).join('');

        const printerSelectHTML = printers.length > 0
            ? `<div class="card p-2 border-secondary bg-dark">
                 <div class="d-flex align-items-center">
                    <img src="assets/img/printer-icon-sm.png" onerror="this.src='assets/img/printer-generic.png'" style="height:32px; width:auto;" class="me-3">
                    <div class="flex-grow-1">
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="printerSelect">
                            ${printerOptions}
                        </select>
                        <div class="small text-success mt-1"><i class="fas fa-check-circle me-1"></i>Online: Ready to print</div>
                    </div>
                 </div>
               </div>`
            : `<div class="alert alert-warning d-flex align-items-center mb-0 p-2">
                 <i class="fas fa-exclamation-triangle me-2"></i>
                 <div class="small">No printers configured. Using system default.</div>
               </div>`;

        const modalHTML = `
        <div class="modal fade" id="printOptionsModal" tabindex="-1" data-bs-backdrop="static">
          <div class="modal-dialog modal-lg modal-dialog-centered">
             <div class="modal-content bg-dark text-light border-secondary shadow-lg">
                <div class="modal-header border-secondary bg-darker">
                   <h5 class="modal-title"><i class="fas fa-print me-2 text-primary"></i>Print Current View</h5>
                   <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" style="background: #2b3035;">
                   <!-- Patient/Study Info -->
                   <div class="card border-secondary bg-dark mb-4">
                      <div class="card-body py-2 px-3 d-flex align-items-center">
                         <div class="me-3 text-primary"><i class="fas fa-user-circle fa-2x"></i></div>
                         <div>
                            <h6 class="mb-0 text-info">${patientInfo.name}</h6>
                            <div class="small opacity-75">ID: <span class="text-light">${patientInfo.id}</span> | Study Date: <span class="text-light">${patientInfo.studyDate}</span></div>
                         </div>
                      </div>
                   </div>

                   <!-- Main Action: Print All Images -->
                   <div class="card print-option-card mb-4 border-info bg-dark" id="cardPrintAll" style="cursor: pointer; transition: all 0.2s;">
                      <div class="card-body d-flex align-items-center p-3">
                         <div class="me-3 text-info"><i class="fas fa-images fa-2x"></i></div>
                         <div>
                            <h5 class="mb-1 text-info">Print All ${loadedCount} Images</h5>
                            <div class="small text-muted">Combines current view grid into print layout</div>
                         </div>
                         <div class="ms-auto">
                            <i class="fas fa-chevron-right text-secondary"></i>
                         </div>
                      </div>
                   </div>

                   <h6 class="text-primary mb-3 small text-uppercase fw-bold"><i class="far fa-file-alt me-2"></i>Or choose special print type:</h6>
                   
                   <div class="row g-3 mb-4">
                      <!-- Medical Report -->
                      <div class="col-md-6">
                         <div class="card print-option-card h-100 bg-dark border-secondary" id="cardMedicalReport" style="cursor: pointer; transition: all 0.2s;">
                            <div class="card-body text-center p-3">
                               <i class="fas fa-file-medical-alt fa-2x mb-2 text-success"></i>
                               <h6>Medical Report</h6>
                               <div class="small text-success"><i class="fas fa-check-circle me-1"></i>Report available</div>
                            </div>
                         </div>
                      </div>
                      <!-- PCPNDT Form -->
                      <div class="col-md-6">
                         <div class="card print-option-card h-100 bg-dark border-secondary" id="cardPCPNDT" style="cursor: pointer; transition: all 0.2s;">
                            <div class="card-body text-center p-3">
                               <i class="fas fa-file-contract fa-2x mb-2 text-info"></i>
                               <h6>PCPNDT Form F</h6>
                               <div class="small text-muted">Compliance print form</div>
                            </div>
                         </div>
                      </div>
                   </div>
                   
                   <!-- Printer & Settings -->
                   <div class="mb-3">
                       <label class="form-label text-light small fw-bold mb-2">Select Printer</label>
                       ${printerSelectHTML}
                   </div>

                   <!-- Settings Summary -->
                   <div class="card bg-secondary border-0 text-light bg-opacity-10">
                       <div class="card-body p-2 d-flex align-items-center">
                           <i class="fas fa-info-circle me-2 text-info"></i>
                           <div class="small flex-grow-1">
                                <span>Paper: <strong>A4</strong></span>
                                <span class="mx-2">|</span>
                                <span>Orientation: <strong>Auto (Landscape)</strong></span>
                                <span class="mx-2">|</span>
                                <span>Quality: <strong>High</strong></span>
                           </div>
                           <a href="#" class="small text-muted text-decoration-none"><i class="fas fa-cog me-1"></i>App Settings</a>
                       </div>
                   </div>
                </div>
                <div class="modal-footer border-secondary bg-darker">
                   <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                   <button type="button" class="btn btn-outline-info" id="btnPreviewOption"><i class="fas fa-eye me-2"></i>Preview</button>
                   <button type="button" class="btn btn-light" id="btnPrintOption"><i class="fas fa-print me-2"></i>Print</button>
                </div>
             </div>
          </div>
        </div>
        <style>
            .print-option-card:hover {
                background: #3a3f45 !important;
                border-color: #0dcaf0 !important;
                transform: translateY(-2px);
            }
            .bg-darker { background-color: #212529 !important; }
        </style>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modalEl = document.getElementById('printOptionsModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        // Styles for hover effects
        const cards = modalEl.querySelectorAll('.print-option-card');
        cards.forEach(card => {
            card.addEventListener('mouseenter', () => card.classList.add('shadow'));
            card.addEventListener('mouseleave', () => card.classList.remove('shadow'));
        });

        // Event Handlers
        let selectedType = 'all'; // Default

        const selectType = (type) => {
            selectedType = type;
            // Visual feedback (optional)
        };

        // Click handlers for cards
        modalEl.querySelector('#cardPrintAll').onclick = () => {
            selectType('all');
            handlePrintAction(true); // Direct action
        };
        modalEl.querySelector('#cardMedicalReport').onclick = () => {
            selectType('report');
            // Check if report exists logic? Assuming yes for now or trigger report flow
            handlePrintAction(true);
        };
        modalEl.querySelector('#cardPCPNDT').onclick = () => {
            selectType('pcpndt');
            handlePrintAction(true);
        };

        const handlePrintAction = async (isPreview = false) => {
            modal.hide();

            // Wait for modal to close (animation)
            await new Promise(r => setTimeout(r, 200));

            // Get selected printer if any
            const printerSelect = document.getElementById('printerSelect');
            const printerName = printerSelect ? printerSelect.value : null;
            if (printerName) {
                // Update settings temporarily
                this.printSettings.printerName = printerName;
            }

            const settings = this.getEffectivePrintSettings();
            if (printerName) settings.printerName = printerName;

            if (selectedType === 'all') {
                // Capture and standard print/preview
                const viewportContainer = document.getElementById('viewport-container');

                // Detect layout type
                const containerClasses = viewportContainer.className;
                let layoutType = '1x1';
                const layoutMatch = containerClasses.match(/layout-spots-(\d+)/);
                if (layoutMatch) {
                    const spots = parseInt(layoutMatch[1]);
                    const layoutMap = { 1: '1x1', 2: '1x2', 4: '2x2', 6: '2x3', 8: '2x4', 9: '3x3', 12: '3x4', 15: '3x5', 16: '4x4' };
                    layoutType = layoutMap[spots] || `${spots}`;
                }

                const dataUrl = await this.captureViewportSnapshot(viewportContainer);
                const pageContent = this.generateLayoutPageContent(dataUrl, patientInfo, loadedCount);

                if (isPreview) {
                    // Show Google Docs Preview
                    this.showPreviewModal(pageContent, {
                        title: `Print Preview - All Images`,
                        totalPages: 1,
                        printSettings: settings,
                        onPrint: async () => {
                            await this.trackPrint({
                                paperSize: settings.paperSize,
                                orientation: settings.orientation,
                                colorMode: settings.colorMode,
                                quality: settings.quality,
                                layoutType: layoutType,
                                totalPages: 1,
                                includePatientInfo: settings.includePatientInfo,
                                includeAnnotations: settings.includeAnnotations,
                                includeMeasurements: settings.includeMeasurements
                            });
                        }
                    });
                } else {
                    // Direct print
                    await this.trackPrint({
                        paperSize: settings.paperSize,
                        orientation: settings.orientation,
                        colorMode: settings.colorMode,
                        quality: settings.quality,
                        layoutType: layoutType,
                        totalPages: 1,
                        includePatientInfo: settings.includePatientInfo,
                        includeAnnotations: settings.includeAnnotations,
                        includeMeasurements: settings.includeMeasurements
                    });

                    const fullPrintHTML = this.generatePrintableHTML(pageContent, settings);
                    await this.directPrintWithDialog(fullPrintHTML, settings);
                }
            } else if (selectedType === 'pcpndt') {
                this.printPCPNDT(patientInfo);
            } else if (selectedType === 'report') {
                this.showToast('Select a report to print from the Reports tab.', 'info');
            }

            setTimeout(() => modalEl.remove(), 500);
        };


        // Footer buttons
        modalEl.querySelector('#btnPreviewOption').onclick = () => handlePrintAction(true);
        modalEl.querySelector('#btnPrintOption').onclick = () => handlePrintAction(false);
    }

    /**
     * Print PCPNDT Form F - Single Page Composite
     */
    async printPCPNDT(patientInfo) {
        try {
            const viewportContainer = document.getElementById('viewport-container');
            const dataUrl = await this.captureViewportSnapshot(viewportContainer);

            // Build PCPNDT Page HTML
            // Single A4 page, filling maximum space with the grid image
            const html = `
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    @page { size: A4 portrait; margin: 10mm; }
                    body { font-family: sans-serif; margin: 0; padding: 20px; }
                    .header { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
                    .title { font-size: 18px; font-weight: bold; text-align: center; text-transform: uppercase; margin-bottom: 5px; }
                    .subtitle { font-size: 14px; text-align: center; color: #555; }
                    .patient-info { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 14px; }
                    .image-container { width: 100%; text-align: center; border: 1px solid #ccc; padding: 5px; }
                    .image-container img { max-width: 100%; height: auto; display: block; margin: 0 auto; }
                    .footer { margin-top: 20px; font-size: 12px; text-align: center; color: #777; border-top: 1px solid #eee; padding-top: 10px; }
                </style>
            </head>
            <body>
                <div class="header">
                    <div class="title">Ultrasound Sonography Report</div>
                    <div class="subtitle">PCPNDT Form F Compliance</div>
                </div>
                <div class="patient-info">
                    <div>
                        <div><strong>Patient Name:</strong> ${patientInfo.name}</div>
                        <div><strong>Patient ID:</strong> ${patientInfo.id}</div>
                    </div>
                    <div style="text-align: right;">
                        <div><strong>Date:</strong> ${patientInfo.studyDate}</div>
                        <div><strong>Hospital:</strong> ${patientInfo.institution}</div>
                    </div>
                </div>
                <div class="image-container">
                    <img src="${dataUrl}" alt="Ultrasound Scan">
                </div>
                <div class="footer">
                    Generated by DICOM Viewer Pro - PCPNDT Compliance Mode
                </div>
            </body>
            </html>
            `;

            // Print it
            await this.directPrintWithDialog(html, {
                paperSize: 'A4',
                orientation: 'portrait'
            });

        } catch (e) {
            console.error(e);
            this.showToast('PCPNDT Print failed', 'error');
        }
    }

    /*
     * Legacy/Internal - Captures layout page for standard preview
     * (We'll keep generateLayoutPageContent logic as is, or updated?)
     * NO CHANGE NEEDED for generateLayoutPageContent
     */

    // REPLACING printCurrentLayout implementation with valid one below

    /**
     * Generate just the page content for layout print preview (no full document wrapper)
     */
    generateLayoutPageContent(imageDataUrl, patientInfo, viewportCount) {
        const settings = this.getEffectivePrintSettings();

        return `
            <div class="print-page">
                <span class="page-number-badge">Page 1 of 1</span>
                <div class="page-inner">
                    ${settings.includeInstitutionInfo ? `
                    <div class="page-header">
                        <div class="header-content">
                            <div class="hospital-info">
                                <h2>${patientInfo.institution}</h2>
                                <p>Medical Imaging Department</p>
                            </div>
                            ${settings.includePatientInfo ? `
                            <div class="patient-info">
                                <strong>${patientInfo.name}</strong>
                                ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                                ${patientInfo.age ? `Age: ${patientInfo.age}${patientInfo.sex ? ` | Sex: ${patientInfo.sex}` : ''}<br>` : ''}
                                ${patientInfo.studyDescription ? `Study: ${patientInfo.studyDescription}<br>` : ''}
                                Date: ${patientInfo.studyDate}
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    ` : ''}
                    
                    <div class="page-content">
                        <img src="${imageDataUrl}" class="page-image" alt="Current Layout">
                    </div>
                    
                    <div class="page-footer">
                        <span>${settings.includeTimestamp ? `Generated: ${new Date().toLocaleString()}` : ''}</span>
                        <span>DICOM Viewer - Accurate Diagnostics</span>
                        <span>For Medical Use Only</span>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Generate HTML for layout screenshot print
     */
    generateLayoutPrintHTML(imageDataUrl, patientInfo, viewportCount, previewOnly) {
        const settings = this.getEffectivePrintSettings();
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
            <button class="btn-print" id="printNowBtn">🖨️ Print Now</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>
    <script>
        // Custom print handler for Electron
        document.getElementById('printNowBtn').addEventListener('click', async function() {
            const printManager = window.opener?.DICOM_VIEWER?.MANAGERS?.printManager;
            
            if (!printManager) {
                alert('Printing is only available through the main application window');
                return;
            }
            
            const printContent = document.documentElement.outerHTML;
            const printSettings = { orientation: 'landscape' };
            
            console.log('[DEBUG Preview] Calling parent directPrintWithDialog...');
            try {
                await printManager.directPrintWithDialog(printContent, printSettings);
                console.log('[DEBUG Preview] directPrintWithDialog returned');
            } catch (error) {
                console.error('[DEBUG Preview] directPrintWithDialog failed:', error);
                alert('Print failed: ' + error.message);
            }
        });
    </script>
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
                const pageNum = page + 1;

                this.updateLoadingProgress(`Capturing page ${pageNum} of ${totalPages}...`, Math.round(((page + 1) / totalPages) * 70));

                // Check if PageNavigator has saved state for this page
                const pageNavigator = window.DICOM_VIEWER.MANAGERS?.pageNavigator;
                const savedState = pageNavigator?.getPageState?.(pageNum);

                // Load images into viewports for this page
                for (let i = 0; i < viewports.length; i++) {
                    const viewport = viewports[i];
                    let imgIndex;
                    let img;

                    if (savedState && savedState[i] !== undefined && savedState[i] !== null) {
                        // Use saved state - this respects manual placements
                        imgIndex = savedState[i];
                        img = images[imgIndex];
                        console.log(`[PrintAllPages] Page ${pageNum}, Viewport ${i + 1}: Using SAVED state, image index ${imgIndex}`);
                    } else {
                        // Fall back to sequential order
                        const startIdx = page * viewportCount;
                        imgIndex = startIdx + i;
                        img = imgIndex < images.length ? images[imgIndex] : null;
                        console.log(`[PrintAllPages] Page ${pageNum}, Viewport ${i + 1}: Using DEFAULT order, image index ${imgIndex}`);
                    }

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
                            console.warn(`Error loading image ${imgIndex} for page ${page}:`, err);
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

                // BEFORE capture: Inject CSS with !important to override all viewport borders
                const printBorderSettings = this.printSettings || {};
                const borderCSS = printBorderSettings.borderEnabled
                    ? (printBorderSettings.borderWidth || 2) + 'px ' + (printBorderSettings.borderStyle || 'solid') + ' ' + (printBorderSettings.borderColor || '#ff0000')
                    : 'none';

                const printStyleEl = document.createElement('style');
                printStyleEl.id = 'print-capture-override';
                printStyleEl.textContent = `
                    .viewport, .viewport.active, .viewport.selected, 
                    .viewport.annotation-mode, .viewport.main-tool-mode,
                    .viewport:first-child, .viewport:hover, .viewport:focus {
                        border: ${borderCSS} !important;
                        outline: none !important;
                        box-shadow: none !important;
                    }
                    .viewport::before, .viewport::after,
                    .viewport.active::before, .viewport.active::after,
                    .viewport.selected::before, .viewport.selected::after {
                        display: none !important;
                        content: none !important;
                    }
                `;
                document.head.appendChild(printStyleEl);

                // Hide yellow/active borders on ORIGINAL viewports
                const originalBorders = [];
                const originalClasses = [];
                viewports.forEach(vp => {
                    originalBorders.push(vp.style.border);
                    originalClasses.push({
                        active: vp.classList.contains('active'),
                        selected: vp.classList.contains('selected'),
                        annotationMode: vp.classList.contains('annotation-mode'),
                        mainToolMode: vp.classList.contains('main-tool-mode')
                    });
                    // Remove classes that create borders
                    vp.classList.remove('active', 'selected', 'annotation-mode', 'main-tool-mode');
                });

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

                            // HANDLE EMPTY VIEWPORTS - Make them completely black
                            clonedViewports.forEach(vp => {
                                // Check if viewport is empty (has empty indicator or text)
                                if (vp.querySelector('.empty-viewport-indicator') || vp.textContent.includes('Drop image here') || vp.dataset.isEmpty === 'true') {
                                    vp.innerHTML = ''; // WIPE CONTENT
                                    vp.style.background = '#000000';
                                    vp.style.backgroundImage = 'none';
                                    vp.style.border = '1px solid #333'; // Minimal border to show grid structure
                                }
                            });

                            clonedContainer.querySelectorAll('.slice-indicator').forEach(el => el.style.display = 'none');
                            clonedContainer.querySelectorAll('.drawing-overlay').forEach(el => el.style.display = 'none');
                            // Hide crosshairs - CORRECT selectors
                            clonedContainer.querySelectorAll('.crosshair-container, .crosshair-h, .crosshair-v, .crosshair, .crosshair-overlay, .crosshair-line').forEach(el => el.style.display = 'none');
                            clonedContainer.querySelectorAll('.viewport-overlay').forEach(el => el.style.display = 'none');
                            // Hide empty viewport indicators
                            clonedContainer.querySelectorAll('.empty-viewport-indicator').forEach(el => el.style.display = 'none');
                            // Hide active viewport border/highlight (including yellow border)
                            clonedViewports.forEach(vp => {
                                vp.classList.remove('active');
                                vp.classList.remove('selected');
                                vp.classList.remove('annotation-mode');
                                vp.classList.remove('main-tool-mode');
                                vp.style.outline = 'none';
                                vp.style.boxShadow = 'none';
                                // Remove any yellow/selection borders completely
                                vp.style.border = 'none';

                                // Apply user's print border settings if enabled
                                const settings = window.DICOM_VIEWER?.MANAGERS?.printManager?.printSettings || {};
                                if (settings.borderEnabled) {
                                    vp.style.border = (settings.borderWidth || 2) + 'px ' + (settings.borderStyle || 'solid') + ' ' + (settings.borderColor || '#ff0000');
                                }
                            });

                            // Inject CSS to hide any viewport styling that might create borders
                            const styleEl = clonedDoc.createElement('style');
                            styleEl.textContent = '.viewport, .viewport.active, .viewport.selected, .viewport.annotation-mode, .viewport.main-tool-mode { outline: none !important; box-shadow: none !important; } .viewport::after, .viewport::before, .viewport.active::after, .viewport.selected::after { display: none !important; content: none !important; }';
                            clonedDoc.head.appendChild(styleEl);
                            // Remove viewport name pseudo-element labels
                            clonedViewports.forEach(vp => vp.removeAttribute('data-viewport-name'));
                            // Hide image number overlays
                            clonedContainer.querySelectorAll('.image-page-number').forEach(el => el.style.display = 'none');
                            // Hide page indicator
                            clonedContainer.querySelectorAll('.page-indicator').forEach(el => el.style.display = 'none');
                        }
                    });

                    pageScreenshots.push({
                        dataUrl: canvas.toDataURL('image/png', 1.0),
                        pageNum: page + 1,
                        imageCount: viewportCount
                    });
                } catch (err) {
                    console.error(`Error capturing page ${page + 1}: `, err);
                    pageScreenshots.push({
                        dataUrl: null,
                        pageNum: page + 1,
                        error: true
                    });
                }

                // AFTER capture: Restore original borders and classes on viewports
                viewports.forEach((vp, idx) => {
                    vp.style.border = originalBorders[idx] || '';
                    vp.style.outline = '';
                    vp.style.boxShadow = '';
                    if (originalClasses[idx]?.active) vp.classList.add('active');
                    if (originalClasses[idx]?.selected) vp.classList.add('selected');
                    if (originalClasses[idx]?.annotationMode) vp.classList.add('annotation-mode');
                    if (originalClasses[idx]?.mainToolMode) vp.classList.add('main-tool-mode');
                });

                // Remove the injected CSS override
                const printOverrideEl = document.getElementById('print-capture-override');
                if (printOverrideEl) printOverrideEl.remove();
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

            // Generate page content for preview/print
            const pagesContent = this.generatePreviewPagesContent(pageScreenshots, patientInfo, viewportCount);
            const settings = this.getEffectivePrintSettings();

            this.updateLoadingProgress('Complete!', 100);
            this.hideLoadingModal();

            if (previewOnly) {
                // Show in-window preview modal (no popup)
                this.showPreviewModal(pagesContent, {
                    title: `Print Preview - ${images.length} Images on ${totalPages} Pages`,
                    totalPages: totalPages,
                    printSettings: settings,
                    onPrint: async () => {
                        // Track print when user clicks Print
                        await this.trackPrint({
                            paperSize: settings.paperSize,
                            orientation: settings.orientation,
                            colorMode: settings.colorMode,
                            quality: settings.quality,
                            layoutType: `${viewportCount}`,
                            totalPages: totalPages,
                            pagesPerCopy: totalPages,
                            includePatientInfo: settings.includePatientInfo,
                            includeAnnotations: settings.includeAnnotations,
                            includeMeasurements: settings.includeMeasurements
                        });
                    }
                });
            } else {
                // Direct print - track and show printer dialog
                console.log('[DEBUG] printAllImages: Starting print tracking (previewOnly=false)');
                await this.trackPrint({
                    paperSize: settings.paperSize,
                    orientation: settings.orientation,
                    colorMode: settings.colorMode,
                    quality: settings.quality,
                    layoutType: `${viewportCount}`,
                    totalPages: totalPages,
                    pagesPerCopy: totalPages,
                    includePatientInfo: settings.includePatientInfo,
                    includeAnnotations: settings.includeAnnotations,
                    includeMeasurements: settings.includeMeasurements
                });
                console.log('[DEBUG] printAllImages: trackPrint completed');

                // Generate full printable HTML and show printer dialog
                const fullPrintHTML = this.generatePrintableHTML(pagesContent, settings);
                await this.directPrintWithDialog(fullPrintHTML, settings);

                // After image print, prompt for PCPNDT Form F
                this.showPcpndtPrompt();
            }

        } catch (error) {
            console.error('All images print error:', error);
            throw error;
        } finally {
            this.hideLoadingModal();
        }
    }

    /**
     * Generate just the page content HTML for preview (no full document wrapper)
     * Uses page-inner structure for proper aspect ratio display
     */
    generatePreviewPagesContent(pageScreenshots, patientInfo, viewportCount) {
        const totalPages = pageScreenshots.length;

        return pageScreenshots.map((page, idx) => {
            if (page.error || !page.dataUrl) {
                return `
                    <div class="print-page" data-page="${page.pageNum}">
                        <div class="page-inner">
                            <div class="page-header">
                                <div class="header-content">
                                    <div class="hospital-info">
                                        <h2>${patientInfo.institution}</h2>
                                        <p>Medical Imaging Department</p>
                                    </div>
                                    <div class="patient-info">
                                        <strong>${patientInfo.name}</strong>
                                        ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                                        Study: ${patientInfo.studyDate}
                                    </div>
                                </div>
                            </div>
                            <div class="page-content" style="background: #f8f9fa;">
                                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; color: #dc3545; padding: 40px;">
                                    <i class="bi bi-exclamation-triangle" style="font-size: 48px;"></i>
                                    <p style="margin-top: 10px;">Error capturing page ${page.pageNum}</p>
                                </div>
                            </div>
                            <div class="page-footer">
                                <div>Page ${page.pageNum} of ${totalPages}</div>
                                <div>Printed: ${new Date().toLocaleString()}</div>
                            </div>
                        </div>
                    </div>
                `;
            }

            return `
                <div class="print-page" data-page="${page.pageNum}">
                    <span class="page-number-badge">Page ${page.pageNum} of ${totalPages}</span>
                    <div class="page-inner">
                        <div class="page-header">
                            <div class="header-content">
                                <div class="hospital-info">
                                    <h2>${patientInfo.institution}</h2>
                                    <p>Medical Imaging Department</p>
                                </div>
                                <div class="patient-info">
                                    <strong>${patientInfo.name}</strong>
                                    ${patientInfo.id ? `ID: ${patientInfo.id}<br>` : ''}
                                    Study: ${patientInfo.studyDate}
                                </div>
                            </div>
                        </div>
                        <div class="page-content">
                            <img src="${page.dataUrl}" class="page-image" alt="Page ${page.pageNum}">
                        </div>
                        <div class="page-footer">
                            <div>Page ${page.pageNum} of ${totalPages}</div>
                            <div>Printed: ${new Date().toLocaleString()}</div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * Generate HTML for all images print using page screenshots
     */
    generateAllImagesScreenshotHTML(pageScreenshots, patientInfo, viewportCount, previewOnly) {
        const settings = this.getEffectivePrintSettings();
        const marginValues = { none: 0, narrow: 5, normal: 10, wide: 20 };
        const margin = marginValues[settings.margins] || 10;
        const totalPages = pageScreenshots.length;

        const pagesHTML = pageScreenshots.map((page, idx) => {
            if (page.error || !page.dataUrl) {
                return `
                                        < div class="print-page" data - page="${page.pageNum}" >
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
                    </div >
                        `;
            }

            return `
                        < div class="print-page" data - page="${page.pageNum}" >
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
                        < !DOCTYPE html >
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
                                                body {margin: 0; padding: 0; }
                                            .no-print {display: none !important; }
                                            .print-page {page -break-after: always; margin-bottom: 0 !important; box-shadow: none !important; }
                                            .print-page:last-child {page -break-after: avoid; }
                                            * {-webkit - print - color - adjust: exact !important; print-color-adjust: exact !important; }
        }

                                            * {margin: 0; padding: 0; box-sizing: border-box; }

                                            body {
                                                font - family: 'Segoe UI', Arial, sans-serif;
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

                                            .print-toolbar h4 {color: #fff; margin: 0; font-size: 16px; }

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
                                                border - bottom: 2px solid #0d6efd;
                                            padding-bottom: 8px;
                                            margin-bottom: 10px;
        }

                                            .header-content {
                                                display: flex;
                                            justify-content: space-between;
                                            align-items: flex-start;
        }

                                            .hospital-info h2 {color: #0d6efd; font-size: 18px; margin-bottom: 3px; }
                                            .hospital-info p {font - size: 11px; color: #666; }
                                            .patient-info {text - align: right; font-size: 11px; }
                                            .patient-info strong {font - size: 13px; color: #333; }

                                            .page-image {
                                                width: 100%;
                                            height: auto;
                                            max-height: ${settings.orientation === 'landscape' ? '150mm' : '220mm'};
                                            object-fit: contain;
                                            border: none;
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

                                            .error-page i {font - size: 48px; margin-bottom: 15px; }

                                            .page-footer {
                                                border - top: 1px solid #ddd;
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

                                            .page-nav-item:hover {background: rgba(13, 110, 253, 0.5); border-color: #0d6efd; }
                                        </style>
                                </head>
                                <body>
                                    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>📄 Print Preview - All Images on ${totalPages} Pages (${viewportCount}-viewport layout)</h4>
        <div>
            <button class="btn-print" onclick="trackAndPrint()">🖨️ Print All Pages</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>
    <div class="page-nav no-print">
        ${pageScreenshots.map((_, i) => `<div class="page-nav-item" title="Page ${i + 1}" onclick="document.querySelector('[data-page=\\'${i + 1}\\']').scrollIntoView({behavior:'smooth'})">${i + 1}</div>`).join('')}
    </div>
    <script>
        // Track print through parent window before printing
        async function trackAndPrint() {
            console.log('[DEBUG Preview] trackAndPrint called');
            
            // Get parent window's PrintManager
            const printManager = window.opener?.DICOM_VIEWER?.MANAGERS?.printManager;
            
            if (!printManager) {
                console.error('[DEBUG Preview] Parent PrintManager not available');
                alert('Printing is only available through the main application window');
                return;
            }
            
            try {
                // Track the print
                console.log('[DEBUG Preview] Found parent PrintManager instance, calling trackPrint...');
                await printManager.trackPrint({
                    paperSize: '${settings.paperSize}',
                    orientation: '${settings.orientation}',
                    colorMode: '${settings.colorMode || 'grayscale'}',
                    quality: '${settings.quality || 'high'}',
                    layoutType: '${viewportCount}',
                    totalPages: ${totalPages},
                    pagesPerCopy: ${totalPages},
                    includePatientInfo: ${settings.includePatientInfo ? 1 : 0},
                    includeAnnotations: ${settings.includeAnnotations ? 1 : 0},
                    includeMeasurements: ${settings.includeMeasurements ? 1 : 0}
                });
                console.log('[DEBUG Preview] Print tracked successfully from preview window');
            } catch (error) {
                console.error('[DEBUG Preview] Failed to track print:', error);
            }

            // Get the HTML content for printing (remove no-print elements)
            const printContent = document.documentElement.outerHTML;
            const printSettings = {
                paperSize: '${settings.paperSize}',
                orientation: '${settings.orientation}',
                colorMode: '${settings.colorMode || 'grayscale'}'
            };

            // Directly call parent's directPrintWithDialog method
            // This shows the printer selection modal in the parent window
            console.log('[DEBUG Preview] Calling parent directPrintWithDialog...');
            try {
                await printManager.directPrintWithDialog(printContent, printSettings);
                console.log('[DEBUG Preview] directPrintWithDialog returned');
                // Trigger PCPNDT prompt after print dialog closes
                triggerPcpndtPrompt();
            } catch (error) {
                console.error('[DEBUG Preview] directPrintWithDialog failed:', error);
                alert('Print failed: ' + error.message);
            }
        }

        function triggerPcpndtPrompt() {
            try {
                console.log('[DEBUG Preview] Triggering PCPNDT prompt in parent window...');
                if (window.opener && window.opener.DICOM_VIEWER && window.opener.DICOM_VIEWER.MANAGERS && window.opener.DICOM_VIEWER.MANAGERS.printManager) {
                    window.opener.DICOM_VIEWER.MANAGERS.printManager.showPcpndtPrompt();
                    console.log('[DEBUG Preview] PCPNDT prompt triggered');
                } else {
                    console.warn('[DEBUG Preview] Cannot trigger PCPNDT prompt - parent PrintManager not available');
                }
            } catch (e) {
                console.error('[DEBUG Preview] Error triggering PCPNDT prompt:', e);
            }
        }

        // Block Ctrl+P and use custom print dialog
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                trackAndPrint();
            }
        });
    </script>
    ` : ''}

                                    ${pagesHTML}
                                </body>
                            </html>`;
    }

    /**
     * Generate HTML for all images print with multiple pages
     */
    generateAllImagesPrintHTML(capturedImages, patientInfo, layout, totalPages, previewOnly) {
        const settings = this.getEffectivePrintSettings();
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
                    return `< div class="viewport-cell error" > <span>Image ${img.index}<br>Failed to load</span></div > `;
                }
                return `
                        < div class="viewport-cell" >
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
                        < div class="print-page" data - page="${pageIndex + 1}" >
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
                </div >
                        `;
        }).join('');

        return `
                        < !DOCTYPE html >
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
                                                body {margin: 0; padding: 0; }
                                            .no-print {display: none !important; }
                                            .print-page {page -break-after: always; margin-bottom: 0 !important; box-shadow: none !important; }
                                            .print-page:last-child {page -break-after: avoid; }
                                            * {-webkit - print - color - adjust: exact !important; print-color-adjust: exact !important; }
        }

                                            * {margin: 0; padding: 0; box-sizing: border-box; }

                                            body {
                                                font - family: 'Segoe UI', Arial, sans-serif;
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

                                            .print-toolbar h4 {color: #fff; font-weight: 600; margin: 0; }

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

                                            .btn-print:hover {transform: translateY(-2px); box-shadow: 0 6px 20px rgba(13, 110, 253, 0.4); }
                                            .btn-close {background: #6c757d; color: #fff; }

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
                                                border - bottom: 3px solid #0d6efd;
                                            padding-bottom: 10px;
                                            margin-bottom: 10px;
        }

                                            .header-content {
                                                display: flex;
                                            justify-content: space-between;
                                            align-items: flex-start;
        }

                                            .hospital-info h2 {color: #0d6efd; font-size: 18px; margin-bottom: 3px; }
                                            .hospital-info p {font - size: 11px; color: #666; }
                                            .patient-info {text - align: right; font-size: 11px; }
                                            .patient-info strong {font - size: 13px; color: #333; }

                                            .viewport-grid {
                                                display: grid;
                                            gap: 2px;
                                            height: ${settings.orientation === 'landscape' ? '155mm' : '230mm'};
                                            background: transparent;
        }

                                            .viewport-cell {
                                                position: relative;
                                            background: #000;
                                            border: ${settings.borderEnabled ? `${settings.borderWidth || 2}px ${settings.borderStyle || 'solid'} ${settings.borderColor || '#000000'}` : 'none'};
                                            overflow: hidden;
                                            display: flex;
                                            align-items: center;
                                            justify-content: center;
        }

                                            .viewport-cell img {
                                                max - width: 100%;
                                            max-height: 100%;
                                            object-fit: contain;
        }

                                            .viewport-cell.empty {background: #1a1a1a; }
                                            .viewport-cell.error {background: #2d1d1d; color: #ff6b6b; font-size: 11px; }

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
                                                border - top: 1px solid #ddd;
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

                                            .page-nav-item:hover {background: rgba(13, 110, 253, 0.5); border-color: #0d6efd; }
                                        </style>
                                </head>
                                <body>
                                    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>📄 Print Preview - ${capturedImages.length} Images on ${totalPages} Pages (${layout} Grid)</h4>
        <div>
            <button class="btn-print" id="printAllPagesBtn">🖨️ Print All Pages</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>
    <div class="page-nav no-print">
        ${pages.map((_, i) => `<div class="page-nav-item" title="Page ${i + 1}" onclick="document.querySelector('[data-page=\\'${i + 1}\\']').scrollIntoView({behavior:'smooth'})">${i + 1}</div>`).join('')}
    </div>
    <script>
        document.getElementById('printAllPagesBtn').addEventListener('click', async function() {
            const printManager = window.opener?.DICOM_VIEWER?.MANAGERS?.printManager;
            
            if (!printManager) {
                alert('Printing is only available through the main application window');
                return;
            }
            
            const printContent = document.documentElement.outerHTML;
            const printSettings = { 
                orientation: '${settings.orientation}', 
                paperSize: '${settings.paperSize}' 
            };
            
            console.log('[DEBUG Preview] Calling parent directPrintWithDialog...');
            try {
                await printManager.directPrintWithDialog(printContent, printSettings);
                console.log('[DEBUG Preview] directPrintWithDialog returned');
            } catch (error) {
                console.error('[DEBUG Preview] directPrintWithDialog failed:', error);
                alert('Print failed: ' + error.message);
            }
        });
    </script>
    ` : ''}

                                    <div class="print-content">
                                        ${pagesHTML}
                                    </div>
                                </body>
                            </html>
                    `;
    }
    generateViewportPrintHTML(viewportState, patientInfo, previewOnly) {
        const settings = this.getEffectivePrintSettings();
        const [rows, cols] = viewportState.layout.split('x').map(Number);

        const marginValues = { none: 0, narrow: 5, normal: 10, wide: 20 };
        const margin = marginValues[settings.margins] || 10;

        return `
                        < !DOCTYPE html >
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
                                                body {margin: 0; padding: 0; }
                                            .no-print {display: none !important; }
                                            * {-webkit - print - color - adjust: exact !important; print-color-adjust: exact !important; }
        }

                                            * {margin: 0; padding: 0; box-sizing: border-box; }

                                            body {
                                                font - family: 'Segoe UI', Arial, sans-serif;
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
                                                padding - top: ${previewOnly ? '80px' : '0'};
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
                                                font - size: 12px;
                                            color: #666;
        }

                                            .patient-info {
                                                text - align: right;
                                            font-size: 12px;
        }

                                            .patient-info strong {
                                                font - size: 14px;
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
                                                max - width: 100%;
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

                                            .overlay-top-left {top: 8px; left: 8px; }
                                            .overlay-top-right {top: 8px; right: 8px; text-align: right; }
                                            .overlay-bottom-left {bottom: 8px; left: 8px; }
                                            .overlay-bottom-right {bottom: 8px; right: 8px; text-align: right; }

                                            .page-footer {
                                                border - top: 2px solid #ddd;
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
            <button class="btn-print" id="viewportPrintBtn">Print Now</button>
            <button class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>
    <script>
        document.getElementById('viewportPrintBtn').addEventListener('click', async function() {
            const printManager = window.opener?.DICOM_VIEWER?.MANAGERS?.printManager;
            
            if (!printManager) {
                alert('Printing is only available through the main application window');
                return;
            }
            
            const printContent = document.documentElement.outerHTML;
            const printSettings = { 
                orientation: '${settings.orientation}', 
                paperSize: '${settings.paperSize}' 
            };
            
            console.log('[DEBUG Preview] Calling parent directPrintWithDialog...');
            try {
                await printManager.directPrintWithDialog(printContent, printSettings);
                console.log('[DEBUG Preview] directPrintWithDialog returned');
            } catch (error) {
                console.error('[DEBUG Preview] directPrintWithDialog failed:', error);
                alert('Print failed: ' + error.message);
            }
        });
    </script>
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
        console.log('[DEBUG REPORT] PrintManager.printReport called, reportId:', reportId, 'previewOnly:', previewOnly);

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
                // Track report print BEFORE triggering print dialog
                console.log('[DEBUG REPORT] Tracking report print...');
                await this.trackPrint({
                    printType: 'report',
                    paperSize: 'A4',
                    orientation: 'portrait',
                    colorMode: 'grayscale',
                    quality: 'high',
                    layoutType: 'report',
                    totalPages: 1,
                    includePatientInfo: true,
                    includeAnnotations: false,
                    includeMeasurements: true
                });
                console.log('[DEBUG REPORT] Report print tracked!');

                // Use custom print dialog for Electron
                setTimeout(() => {
                    this.executePrintWithCustomDialog(printWindow, {
                        paperSize: 'A4',
                        orientation: 'portrait',
                        colorMode: 'grayscale'
                    });
                }, 500);
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
                                                body {margin: 0; padding: 0; }
                                            .no-print {display: none !important; }
                                            * {-webkit - print - color - adjust: exact !important; print-color-adjust: exact !important; }
        }

                                            * {margin: 0; padding: 0; box-sizing: border-box; }

                                            body {
                                                font - family: 'Segoe UI', Arial, sans-serif;
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
                                                padding - top: ${previewOnly ? '80px' : '0'};
                                            max-width: 210mm;
                                            margin: 0 auto;
                                            background: #fff;
                                            padding: ${previewOnly ? '80px 40px 40px' : '40px'};
        }

                                            .report-header {
                                                text - align: center;
                                            border-bottom: 3px solid #0d6efd;
                                            padding-bottom: 20px;
                                            margin-bottom: 30px;
        }

                                            .hospital-logo {
                                                max - width: 120px;
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
                                                font - size: 13px;
        }

                                            .patient-field strong {
                                                color: #555;
                                            display: block;
                                            margin-bottom: 2px;
        }

                                            .report-section {
                                                margin - bottom: 25px;
        }

                                            .report-section h4 {
                                                color: #0d6efd;
                                            font-size: 16px;
                                            margin-bottom: 12px;
                                            padding-bottom: 6px;
                                            border-bottom: 1px solid #dee2e6;
        }

                                            .report-section p {
                                                font - size: 13px;
                                            color: #333;
                                            white-space: pre-wrap;
                                            line-height: 1.8;
        }

                                            .report-footer {
                                                margin - top: 40px;
                                            padding-top: 20px;
                                            border-top: 2px solid #dee2e6;
                                            display: grid;
                                            grid-template-columns: repeat(2, 1fr);
                                            gap: 30px;
        }

                                            .signature-box {
                                                text - align: center;
        }

                                            .signature-line {
                                                border - top: 2px solid #333;
                                            margin: 60px auto 10px;
                                            width: 200px;
        }

                                            .signature-box p {
                                                font - size: 12px;
                                            color: #666;
        }

                                            .report-meta {
                                                margin - top: 30px;
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
            <button class="btn-print" onclick="trackAndPrint()">Print Report</button>
            <button class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>
    <script>
        async function trackAndPrint() {
            console.log('[DEBUG REPORT PREVIEW] trackAndPrint called');
            
            // Access parent window's PrintManager
            const printManager = window.opener?.DICOM_VIEWER?.MANAGERS?.printManager;
            
            if (!printManager) {
                console.error('[DEBUG REPORT PREVIEW] PrintManager not available in parent window');
                alert('Printing is only available through the main application window');
                return;
            }
            
            try {
                // Track the print
                await printManager.trackPrint({
                    printType: 'report',
                    paperSize: 'A4',
                    orientation: 'portrait',
                    colorMode: 'grayscale',
                    quality: 'high',
                    layoutType: 'report',
                    totalPages: 1,
                    includePatientInfo: true,
                    includeAnnotations: false,
                    includeMeasurements: true
                });
                console.log('[DEBUG REPORT PREVIEW] Report tracked successfully');
            } catch (error) {
                console.error('[DEBUG REPORT PREVIEW] Error tracking report print:', error);
            }

            // Get the HTML content for printing
            const printContent = document.documentElement.outerHTML;
            const printSettings = { 
                orientation: 'portrait', 
                paperSize: 'A4', 
                colorMode: 'grayscale' 
            };

            // Directly call parent's directPrintWithDialog method
            console.log('[DEBUG REPORT PREVIEW] Calling parent directPrintWithDialog...');
            try {
                await printManager.directPrintWithDialog(printContent, printSettings);
                console.log('[DEBUG REPORT PREVIEW] directPrintWithDialog returned');
            } catch (error) {
                console.error('[DEBUG REPORT PREVIEW] directPrintWithDialog failed:', error);
                alert('Print failed: ' + error.message);
            }
        }

        // Block Ctrl+P and use custom print dialog
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                trackAndPrint();
            }
        });
    </script>
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
                        < div id = "printLoadingModalV3" class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style = "background: rgba(0,0,0,0.8); z-index: 9999;" >
                            <div class="bg-dark text-light p-4 rounded-3 text-center border border-primary" style="min-width: 350px;">
                                <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
                                <div id="printLoadingMessageV3" class="mb-3 fs-5">${message}</div>
                                <div class="progress" style="height: 8px;">
                                    <div id="printLoadingProgressV3" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: ${progress}%"></div>
                                </div>
                                <small class="text-muted mt-2 d-block" id="printLoadingPercentV3">${progress}%</small>
                            </div>
                </div >
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
        if (progressEl) progressEl.style.width = `${progress}% `;
        if (percentEl) percentEl.textContent = `${progress}% `;
    }

    hideLoadingModal() {
        const modal = document.getElementById('printLoadingModalV3');
        if (modal) modal.remove();
    }

    /**
     * Show PCPNDT Form F prompt after image print
     * Asks user if they want to print the compliance form
     */
    showPcpndtPrompt() {
        // Create prompt modal HTML
        const modalHTML = `
                        < div id = "pcpndtPromptModal" class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
                    style = "background: rgba(0,0,0,0.8); z-index: 99999;" >
                        <div class="card text-white" style="background: #1a1f3a; border: 2px solid #0d6efd; border-radius: 15px; max-width: 400px;">
                            <div class="card-body text-center p-4">
                                <div class="mb-3">
                                    <i class="bi bi-file-medical-fill text-success" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="mb-3">PCPNDT Form F</h5>
                                <p class="text-muted mb-4">Do you want to print PCPNDT Form F (Pre-Conception and Pre-Natal Diagnostic Techniques compliance form)?</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button id="pcpndtYesBtn" class="btn btn-success px-4">
                                        <i class="bi bi-check-lg me-1"></i> Yes, Print
                                    </button>
                                    <button id="pcpndtNoBtn" class="btn btn-secondary px-4">
                                        <i class="bi bi-x-lg me-1"></i> No, Skip
                                    </button>
                                </div>
                            </div>
                        </div>
            </div >
                        `;

        // Remove any existing modal
        document.getElementById('pcpndtPromptModal')?.remove();

        // Add to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modal = document.getElementById('pcpndtPromptModal');

        // Handle Yes button
        document.getElementById('pcpndtYesBtn')?.addEventListener('click', async () => {
            modal.remove();
            // Print PCPNDT form directly (no preview)
            try {
                await this.printPcpndtFormF(false);
                this.showToast('PCPNDT Form F printed successfully', 'success');
            } catch (error) {
                console.error('PCPNDT print error:', error);
                this.showToast('Failed to print PCPNDT form', 'error');
            }
        });

        // Handle No button
        document.getElementById('pcpndtNoBtn')?.addEventListener('click', () => {
            modal.remove();
        });

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
            }
        });
    }

    /**
     * Print PCPNDT Form F
     * Generates a compliance print form based on PCPNDT Act requirements
     */
    async printPcpndtFormF(previewOnly = false) {
        this.showLoadingModal('Generating PCPNDT Form F...', 0);

        try {
            // Get patient/study info
            const state = window.DICOM_VIEWER.STATE;
            const currentImage = state.currentSeriesImages?.[state.currentImageIndex] || {};

            const patientInfo = {
                name: currentImage.patient_name || currentImage.patientName || 'Unknown Patient',
                id: currentImage.patient_id || currentImage.patientId || '',
                age: currentImage.patient_age || '',
                sex: currentImage.patient_sex || '',
                studyDate: currentImage.study_date || currentImage.studyDate || new Date().toLocaleDateString(),
                studyDescription: currentImage.study_description || currentImage.studyDescription || 'Ultrasound Study',
                institution: this.hospitalSettings?.hospital_name || 'Medical Imaging Center'
            };

            // Load PCPNDT settings from localStorage or server
            let pcpndtSettings = {
                paperSize: 'A5',
                colorMode: 'color'
            };

            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            try {
                const response = await fetch(`${basePath} /api/settings / get - settings.php`);
                const data = await response.json();
                if (data.success && data.settings) {
                    pcpndtSettings.paperSize = data.settings.pcpndt_default_paper_size || 'A5';
                    pcpndtSettings.colorMode = data.settings.pcpndt_default_color_mode || 'color';
                }
            } catch (e) {
                console.warn('Error loading PCPNDT settings:', e);
            }

            this.updateLoadingProgress('Generating Form F...', 50);

            const formHTML = this.generatePcpndtFormHTML(patientInfo, pcpndtSettings, previewOnly);

            const printWindow = window.open('', '_blank', 'width=800,height=600');
            if (!printWindow) {
                throw new Error('Please allow popups to print');
            }

            printWindow.document.write(formHTML);
            printWindow.document.close();

            this.updateLoadingProgress('Complete!', 100);

            if (!previewOnly) {
                // Track PCPNDT print before triggering print dialog
                console.log('[DEBUG PCPNDT] Tracking PCPNDT print...');
                await this.trackPrint({
                    printType: 'pcpndt',
                    paperSize: pcpndtSettings.paperSize,
                    orientation: 'portrait',
                    colorMode: pcpndtSettings.colorMode,
                    quality: 'high',
                    layoutType: 'pcpndt',
                    totalPages: 1,
                    includePatientInfo: true,
                    includeAnnotations: false,
                    includeMeasurements: false
                });
                console.log('[DEBUG PCPNDT] PCPNDT print tracked!');

                // Use custom print dialog for Electron
                setTimeout(() => {
                    this.executePrintWithCustomDialog(printWindow, {
                        paperSize: pcpndtSettings.paperSize,
                        orientation: 'portrait',
                        colorMode: pcpndtSettings.colorMode
                    });
                }, 500);
            }
        } catch (error) {
            console.error('PCPNDT print error:', error);
            throw error;
        } finally {
            this.hideLoadingModal();
        }
    }

    generatePcpndtFormHTML(patientInfo, settings, previewOnly) {
        const isA4 = settings.paperSize === 'A4';
        const isGrayscale = settings.colorMode === 'grayscale';

        return `
                        < !DOCTYPE html >
                            <html>
                                <head>
                                    <meta charset="UTF-8">
                                        <title>PCPNDT Form F - ${patientInfo.name}</title>
                                        <style>
                                            @page {
                                                size: ${settings.paperSize} portrait;
                                            margin: 10mm;
        }

                                            @media print {
                                                body {margin: 0; padding: 0; }
                                            .no-print {display: none !important; }
                                            ${isGrayscale ? '* { filter: grayscale(100%); -webkit-filter: grayscale(100%); }' : ''}
        }

                                            * {margin: 0; padding: 0; box-sizing: border-box; }

                                            body {
                                                font - family: 'Times New Roman', serif;
                                            font-size: ${isA4 ? '12pt' : '10pt'};
                                            background: ${previewOnly ? '#2d2d2d' : '#fff'};
                                            color: #000;
                                            ${isGrayscale ? 'filter: grayscale(100%);' : ''}
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

                                            .print-toolbar h4 {color: #fff; margin: 0; font-size: 16px; }
                                            .btn-print {background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%); color: white; border: none; padding: 10px 25px; border-radius: 6px; cursor: pointer; font-weight: 600; margin-right: 10px; }
                                            .btn-close {background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }

                                            .form-container {
                                                width: ${isA4 ? '190mm' : '128mm'};
                                            min-height: ${isA4 ? '277mm' : '190mm'};
                                            padding: 8mm;
                                            margin: ${previewOnly ? '60px auto 20px' : '0 auto'};
                                            background: #fff;
                                            box-shadow: ${previewOnly ? '0 4px 20px rgba(0,0,0,0.4)' : 'none'};
        }

                                            .form-title {
                                                text - align: center;
                                            font-weight: bold;
                                            font-size: ${isA4 ? '14pt' : '11pt'};
                                            margin-bottom: 10px;
                                            border-bottom: 2px solid #000;
                                            padding-bottom: 5px;
        }

                                            .form-subtitle {
                                                text - align: center;
                                            font-size: ${isA4 ? '10pt' : '8pt'};
                                            margin-bottom: 15px;
                                            color: #666;
        }

                                            .form-section {
                                                margin - bottom: 10px;
        }

                                            .form-row {
                                                display: flex;
                                            margin-bottom: 5px;
                                            font-size: ${isA4 ? '11pt' : '9pt'};
        }

                                            .form-label {
                                                min - width: 35%;
                                            font-weight: 500;
        }

                                            .form-value {
                                                flex: 1;
                                            border-bottom: 1px dotted #999;
                                            padding-left: 5px;
        }

                                            .declaration {
                                                margin - top: 15px;
                                            padding: 8px;
                                            border: 1px solid #000;
                                            font-size: ${isA4 ? '10pt' : '8pt'};
        }

                                            .signature-section {
                                                margin - top: 20px;
                                            display: flex;
                                            justify-content: space-between;
        }

                                            .signature-box {
                                                text - align: center;
                                            width: 45%;
        }

                                            .signature-line {
                                                border - top: 1px solid #000;
                                            margin-top: 40px;
                                            padding-top: 5px;
                                            font-size: ${isA4 ? '10pt' : '8pt'};
        }

                                            .footer-note {
                                                margin - top: 15px;
                                            font-size: ${isA4 ? '9pt' : '7pt'};
                                            text-align: center;
                                            color: #666;
        }
                                        </style>
                                </head>
                                <body>
                                    ${previewOnly ? `
    <div class="print-toolbar no-print">
        <h4>📋 PCPNDT Form F - Preview</h4>
        <div>
            <button class="btn-print" id="pcpndtPrintBtn">🖨️ Print Now</button>
            <button class="btn-close" onclick="window.close()">✕ Close</button>
        </div>
    </div>
    <script>
        document.getElementById('pcpndtPrintBtn').addEventListener('click', async function() {
            const printManager = window.opener?.DICOM_VIEWER?.MANAGERS?.printManager;
            
            if (!printManager) {
                alert('Printing is only available through the main application window');
                return;
            }
            
            const printContent = document.documentElement.outerHTML;
            const printSettings = { 
                orientation: 'portrait', 
                paperSize: '${settings.paperSize}', 
                colorMode: '${settings.colorMode}' 
            };
            
            console.log('[DEBUG Preview] Calling parent directPrintWithDialog...');
            try {
                await printManager.directPrintWithDialog(printContent, printSettings);
                console.log('[DEBUG Preview] directPrintWithDialog returned');
            } catch (error) {
                console.error('[DEBUG Preview] directPrintWithDialog failed:', error);
                alert('Print failed: ' + error.message);
            }
        });
    </script>
    ` : ''}

                                    <div class="form-container">
                                        <div class="form-title">FORM F</div>
                                        <div class="form-subtitle">
                                            (As per Rule 9(4) read with Section 5(2) of the Pre-Conception and Pre-Natal Diagnostic Techniques<br>
                                                (Prohibition of Sex Selection) Act, 1994 and Rules thereunder)
                                        </div>

                                        <div class="form-section">
                                            <div class="form-row">
                                                <span class="form-label">1. Name of Centre/Hospital:</span>
                                                <span class="form-value">${patientInfo.institution}</span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">2. Registration No. under PCPNDT:</span>
                                                <span class="form-value"></span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">3. Name of Patient:</span>
                                                <span class="form-value">${patientInfo.name}</span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">4. Patient ID:</span>
                                                <span class="form-value">${patientInfo.id}</span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">5. Age/Sex:</span>
                                                <span class="form-value">${patientInfo.age} / ${patientInfo.sex}</span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">6. Date of Procedure:</span>
                                                <span class="form-value">${patientInfo.studyDate}</span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">7. Type of Procedure:</span>
                                                <span class="form-value">${patientInfo.studyDescription}</span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">8. Indication for Procedure:</span>
                                                <span class="form-value"></span>
                                            </div>
                                            <div class="form-row">
                                                <span class="form-label">9. Referral (if any):</span>
                                                <span class="form-value"></span>
                                            </div>
                                        </div>

                                        <div class="declaration">
                                            <strong>DECLARATION</strong><br>
                                                I hereby declare that while conducting ultrasonography/image scanning on ${patientInfo.name}
                                                I have neither detected nor disclosed the sex of foetus to anybody in any manner.
                                        </div>

                                        <div class="signature-section">
                                            <div class="signature-box">
                                                <div class="signature-line">Signature of Patient/Guardian</div>
                                            </div>
                                            <div class="signature-box">
                                                <div class="signature-line">Signature of Doctor/Sonologist</div>
                                            </div>
                                        </div>

                                        <div class="footer-note">
                                            This form is maintained as per PCPNDT Act, 1994 | Printed: ${new Date().toLocaleString()}
                                        </div>
                                    </div>
                                </body>
                            </html>
                    `;
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

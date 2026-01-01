/**
 * Custom Print Dialog for Electron
 *
 * This module provides a custom print dialog that:
 * 1. Shows only printers assigned to the hospital
 * 2. Uses Electron's silent print to bypass the system print dialog
 * 3. Integrates with the existing print manager
 *
 * @requires window.electronAPI (from preload.js)
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.CustomPrintDialog = class {
    constructor() {
        this.hospitalPrinters = []; // Printers assigned to this hospital
        this.systemPrinters = []; // Printers available on the system
        this.matchedPrinters = []; // Hospital printers that exist on the system
        this.selectedPrinter = null;
        this.printCallback = null;

        console.log('[CustomPrintDialog] Initialized, Electron mode:', this.isElectron);
    }

    /**
     * Check if running in Electron (always evaluate current state)
     */
    get isElectron() {
        return !!(window.electronAPI && window.electronAPI.isElectron);
    }

    /**
     * Check if running in Electron with printer support
     */
    isAvailable() {
        return this.isElectron && typeof window.electronAPI.getSystemPrinters === 'function';
    }

    /**
     * Load hospital-assigned printers from the API
     */
    async loadHospitalPrinters() {
        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const url = `${basePath}/api/settings/hospital-printers.php`;
            console.log('[CustomPrintDialog] Fetching hospital printers from:', url);

            const response = await fetch(url);
            const text = await response.text();

            try {
                const data = JSON.parse(text);
                console.log('[CustomPrintDialog] Hospital printers API response:', data);

                if (data.success && data.printers) {
                    // Only include active printers
                    this.hospitalPrinters = data.printers.filter(p => p.is_active == 1);
                    console.log('[CustomPrintDialog] Hospital printers loaded:', this.hospitalPrinters.length);
                    console.log('[CustomPrintDialog] Printers:', this.hospitalPrinters);
                    return this.hospitalPrinters;
                } else {
                    console.warn('[CustomPrintDialog] API returned no printers or failed:', data);
                }
            } catch (jsonError) {
                console.error('[CustomPrintDialog] Failed to parse JSON response:', jsonError);
                console.error('[CustomPrintDialog] Raw response:', text);
                throw new Error('Invalid server response: ' + text.substring(0, 50) + '...');
            }
        } catch (error) {
            console.error('[CustomPrintDialog] Error loading hospital printers:', error);
        }

        this.hospitalPrinters = [];
        return [];
    }

    /**
     * Load system printers from Electron
     */
    async loadSystemPrinters() {
        if (!this.isAvailable()) {
            console.warn('[CustomPrintDialog] Electron printer API not available');
            return [];
        }

        try {
            const result = await window.electronAPI.getSystemPrinters();
            if (result.success) {
                this.systemPrinters = result.printers;
                console.log('[CustomPrintDialog] System printers loaded:', this.systemPrinters.length);
                return this.systemPrinters;
            } else {
                console.error('[CustomPrintDialog] Failed to get system printers:', result.error);
            }
        } catch (error) {
            console.error('[CustomPrintDialog] Error getting system printers:', error);
        }
        return [];
    }

    /**
     * Match hospital printers with system printers
     * Returns printers that are both assigned AND available on the system
     */
    async matchPrinters() {
        await Promise.all([
            this.loadHospitalPrinters(),
            this.loadSystemPrinters()
        ]);

        this.matchedPrinters = [];

        // For each hospital printer, check if it exists on the system
        for (const hospitalPrinter of this.hospitalPrinters) {
            // Use printer_name from hospital_printers table
            const hospitalPrinterName = (hospitalPrinter.printer_name || hospitalPrinter.name || '').toLowerCase().trim();

            // Try to find a matching system printer by name
            const systemPrinter = this.systemPrinters.find(sp => {
                const systemName = sp.name.toLowerCase().trim();
                const systemDisplayName = (sp.displayName || '').toLowerCase().trim();

                // Exact match or contains match
                return systemName === hospitalPrinterName ||
                    systemDisplayName === hospitalPrinterName ||
                    systemName.includes(hospitalPrinterName) ||
                    hospitalPrinterName.includes(systemName);
            });

            if (systemPrinter) {
                this.matchedPrinters.push({
                    ...hospitalPrinter,
                    name: hospitalPrinter.display_name || hospitalPrinter.printer_name,
                    systemName: systemPrinter.name, // Use exact system name for printing
                    displayName: hospitalPrinter.display_name || systemPrinter.displayName || systemPrinter.name,
                    isSystemDefault: systemPrinter.isDefault,
                    status: systemPrinter.status,
                    isOnline: true
                });
            } else {
                // Printer is assigned but not found on system
                this.matchedPrinters.push({
                    ...hospitalPrinter,
                    name: hospitalPrinter.display_name || hospitalPrinter.printer_name,
                    systemName: hospitalPrinter.printer_name,
                    displayName: hospitalPrinter.display_name || hospitalPrinter.printer_name,
                    isSystemDefault: false,
                    status: 'offline',
                    isOnline: false
                });
            }
        }

        console.log('[CustomPrintDialog] Matched printers:', this.matchedPrinters.length);
        return this.matchedPrinters;
    }

    /**
     * Show the custom printer selection dialog
     * @param {Object} options - Dialog options
     * @param {Object} options.printSettings - Default print settings
     * @param {Function} options.onPrint - Callback when print is confirmed (receives printer name and settings)
     * @param {Function} options.onCancel - Callback when dialog is cancelled
     */
    async show(options = {}) {
        console.log('[CustomPrintDialog] ========================================');
        console.log('[CustomPrintDialog] show() method called');
        console.log('[CustomPrintDialog] options:', options);
        console.log('[CustomPrintDialog] ========================================');

        const { printSettings = {}, onPrint, onCancel } = options;

        // Match printers first
        await this.matchPrinters();

        console.log('[CustomPrintDialog] After matchPrinters:');
        console.log('[CustomPrintDialog]   - hospitalPrinters.length:', this.hospitalPrinters.length);
        console.log('[CustomPrintDialog]   - matchedPrinters.length:', this.matchedPrinters.length);

        // If no printers assigned, show configuration required message - DO NOT fall back to system dialog
        if (this.hospitalPrinters.length === 0) {
            console.warn('[CustomPrintDialog] No hospital printers configured - blocking print');
            this.showNoPrintersConfiguredDialog(onCancel);
            return;
        }

        console.log('[CustomPrintDialog] Calling createModal()...');
        // Create and show the modal
        this.createModal(printSettings, onPrint, onCancel);
        console.log('[CustomPrintDialog] createModal() completed');
    }

    /**
     * Show dialog when no printers are configured
     */
    showNoPrintersConfiguredDialog(onCancel) {
        const existingModal = document.getElementById('noPrintersDialog');
        if (existingModal) existingModal.remove();

        const modalHTML = `
            <div class="modal fade" id="noPrintersDialog" tabindex="-1" data-bs-backdrop="static">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content bg-dark text-light border-warning">
                        <div class="modal-header border-warning" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);">
                            <div class="d-flex align-items-center">
                                <div class="print-icon-box me-3" style="background: rgba(255, 193, 7, 0.2);">
                                    <i class="bi bi-exclamation-triangle-fill fs-3 text-warning"></i>
                                </div>
                                <div>
                                    <h5 class="modal-title mb-0">Printer Configuration Required</h5>
                                    <small class="text-muted">No authorized printers found</small>
                                </div>
                            </div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>No printers have been configured for this hospital.</strong>
                            </div>
                            <p class="text-light mb-2">To enable printing, an administrator must:</p>
                            <ol class="text-muted">
                                <li>Go to <strong>Settings</strong></li>
                                <li>Scroll to <strong>Authorized Printers</strong> section</li>
                                <li>Click <strong>Add Printer</strong> to authorize printers</li>
                            </ol>
                            <p class="text-muted small mt-3">
                                <i class="bi bi-shield-check me-1"></i>
                                This restriction ensures only approved printers can be used.
                            </p>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-lg me-1"></i>Close
                            </button>
                            <a href="/admin/general-settings.php" class="btn btn-warning">
                                <i class="bi bi-gear me-1"></i>Go to Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        const modal = new bootstrap.Modal(document.getElementById('noPrintersDialog'));
        modal.show();

        document.getElementById('noPrintersDialog').addEventListener('hidden.bs.modal', () => {
            if (onCancel) onCancel();
            document.getElementById('noPrintersDialog').remove();
        });
    }

    /**
     * Create the printer selection modal
     */
    createModal(printSettings, onPrint, onCancel) {
        // Remove existing modal if present
        const existingModal = document.getElementById('customPrintDialog');
        if (existingModal) existingModal.remove();

        // Find default printer
        const defaultPrinter = this.matchedPrinters.find(p => p.is_default == 1) ||
            this.matchedPrinters.find(p => p.isOnline) ||
            this.matchedPrinters[0];

        const modalHTML = `
            <div class="modal fade" id="customPrintDialog" tabindex="-1" data-bs-backdrop="static">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content bg-dark text-light border-secondary">
                        <div class="modal-header border-secondary" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);">
                            <div class="d-flex align-items-center">
                                <div class="print-icon-box me-3">
                                    <i class="bi bi-printer-fill fs-3 text-primary"></i>
                                </div>
                                <div>
                                    <h5 class="modal-title mb-0">Select Printer</h5>
                                    <small class="text-muted">Choose from authorized printers</small>
                                </div>
                            </div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body p-4">
                            ${this.matchedPrinters.length === 0 ? `
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    No printers are configured for this hospital. Please contact your administrator.
                                </div>
                            ` : `
                                <div class="printers-list mb-4">
                                    ${this.matchedPrinters.map((printer, index) => `
                                        <div class="printer-item ${printer.id === defaultPrinter?.id ? 'selected' : ''} ${!printer.isOnline ? 'offline' : ''}"
                                             data-printer-id="${printer.id}"
                                             data-printer-name="${printer.systemName}"
                                             data-is-online="${printer.isOnline}">
                                            <div class="d-flex align-items-center">
                                                <div class="printer-icon me-3">
                                                    <i class="bi ${printer.isOnline ? 'bi-printer-fill text-success' : 'bi-printer text-muted'} fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center">
                                                        <strong>${printer.displayName || printer.name}</strong>
                                                        ${printer.is_default == 1 ? '<span class="badge bg-primary ms-2">Default</span>' : ''}
                                                        ${!printer.isOnline ? '<span class="badge bg-danger ms-2">Offline</span>' : ''}
                                                    </div>
                                                    <small class="text-muted">
                                                        ${printer.description || ''}
                                                        ${printer.location_name ? ` (${printer.location_name})` : ''}
                                                        ${printer.systemName && printer.systemName !== printer.displayName ? `<br><span class="text-secondary">System: ${printer.systemName}</span>` : ''}
                                                    </small>
                                                </div>
                                                <div class="check-icon">
                                                    <i class="bi bi-check-circle-fill text-primary fs-5"></i>
                                                </div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>

                                <!-- Print Settings Summary -->
                                <div class="print-settings-summary p-3 rounded" style="background: rgba(255,255,255,0.05);">
                                    <h6 class="text-info mb-2"><i class="bi bi-sliders me-2"></i>Print Settings</h6>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <small class="text-muted">Paper Size:</small>
                                            <div><strong>${printSettings.paperSize || 'A4'}</strong></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Orientation:</small>
                                            <div><strong>${printSettings.orientation || 'Portrait'}</strong></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Copies:</small>
                                            <input type="number" id="printCopies" class="form-control form-control-sm bg-dark text-light border-secondary"
                                                   value="${printSettings.copies || 1}" min="1" max="100" style="width: 70px;">
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Color:</small>
                                            <div><strong>${printSettings.colorMode === 'color' ? 'Color' : 'Grayscale'}</strong></div>
                                        </div>
                                    </div>
                                </div>
                            `}
                        </div>

                        <div class="modal-footer border-secondary" style="background: linear-gradient(135deg, #16213e 0%, #1a1a2e 100%);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="customPrintCancel">
                                <i class="bi bi-x-lg me-2"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-primary" id="customPrintConfirm" ${this.matchedPrinters.filter(p => p.isOnline).length === 0 ? 'disabled' : ''}>
                                <i class="bi bi-printer me-2"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                /* Ensure modal appears above all other windows */
                #customPrintDialog.modal {
                    z-index: 99999 !important;
                }

                #customPrintDialog + .modal-backdrop {
                    z-index: 99998 !important;
                }

                #customPrintDialog .print-icon-box {
                    width: 50px;
                    height: 50px;
                    background: linear-gradient(135deg, rgba(13, 110, 253, 0.2) 0%, rgba(13, 110, 253, 0.1) 100%);
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                #customPrintDialog .printer-item {
                    background: rgba(255, 255, 255, 0.05);
                    border: 2px solid rgba(255, 255, 255, 0.1);
                    border-radius: 10px;
                    padding: 15px;
                    margin-bottom: 10px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }

                #customPrintDialog .printer-item:hover:not(.offline) {
                    background: rgba(13, 110, 253, 0.1);
                    border-color: rgba(13, 110, 253, 0.3);
                }

                #customPrintDialog .printer-item.selected {
                    background: rgba(13, 110, 253, 0.15);
                    border-color: #0d6efd;
                    box-shadow: 0 0 15px rgba(13, 110, 253, 0.2);
                }

                #customPrintDialog .printer-item.offline {
                    opacity: 0.6;
                    cursor: not-allowed;
                }

                #customPrintDialog .printer-item .check-icon {
                    display: none;
                }

                #customPrintDialog .printer-item.selected .check-icon {
                    display: block;
                }

                #customPrintDialog .printers-list {
                    max-height: 300px;
                    overflow-y: auto;
                }
            </style>
        `;

        console.log('[CustomPrintDialog] Adding modal HTML to document body...');
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        console.log('[CustomPrintDialog] Creating Bootstrap Modal instance...');
        const modal = new bootstrap.Modal(document.getElementById('customPrintDialog'));

        console.log('[CustomPrintDialog] Calling modal.show()...');
        modal.show();
        console.log('[CustomPrintDialog] Modal should now be visible!');

        // Setup event listeners
        this.setupModalEventListeners(printSettings, onPrint, onCancel, modal);
    }

    /**
     * Setup modal event listeners
     */
    setupModalEventListeners(printSettings, onPrint, onCancel, modal) {
        const dialog = document.getElementById('customPrintDialog');

        // Printer selection
        dialog.querySelectorAll('.printer-item').forEach(item => {
            item.addEventListener('click', () => {
                if (item.dataset.isOnline === 'false') {
                    // Show toast for offline printer
                    this.showToast('This printer is not available', 'warning');
                    return;
                }

                dialog.querySelectorAll('.printer-item').forEach(p => p.classList.remove('selected'));
                item.classList.add('selected');
                this.selectedPrinter = {
                    id: item.dataset.printerId,
                    name: item.dataset.printerName
                };
            });
        });

        // Set initial selected printer
        const defaultSelected = dialog.querySelector('.printer-item.selected');
        if (defaultSelected) {
            this.selectedPrinter = {
                id: defaultSelected.dataset.printerId,
                name: defaultSelected.dataset.printerName
            };
        }

        // Print button
        document.getElementById('customPrintConfirm')?.addEventListener('click', () => {
            const copies = parseInt(document.getElementById('printCopies')?.value || 1);

            if (this.selectedPrinter && onPrint) {
                modal.hide();
                onPrint({
                    printerName: this.selectedPrinter.name,
                    printerId: this.selectedPrinter.id,
                    copies: copies,
                    printSettings: {
                        ...printSettings,
                        copies: copies
                    }
                });
            }
        });

        // Cancel button and modal hidden event
        dialog.addEventListener('hidden.bs.modal', () => {
            if (onCancel) onCancel();
            dialog.remove();
        });

        // Enter key to print
        dialog.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('customPrintConfirm')?.click();
            }
        });
    }

    /**
     * Print HTML content to the selected printer
     * @param {string} htmlContent - Full HTML document to print
     * @param {string} printerName - Name of the printer
     * @param {Object} printSettings - Print settings
     * @returns {Promise<{success: boolean, message?: string, error?: string}>}
     */
    async printContent(htmlContent, printerName, printSettings = {}) {
        if (!this.isAvailable()) {
            console.warn('[CustomPrintDialog] Electron not available, falling back to window.print()');
            return { success: false, error: 'Electron not available', fallback: true };
        }

        try {
            console.log(`[CustomPrintDialog] Printing to: ${printerName}`);
            const result = await window.electronAPI.printToPrinter({
                printerName: printerName,
                htmlContent: htmlContent,
                printSettings: printSettings
            });

            if (result.success) {
                this.showToast('Print job sent successfully', 'success');
            } else {
                this.showToast('Print failed: ' + (result.error || 'Unknown error'), 'error');
            }

            return result;
        } catch (error) {
            console.error('[CustomPrintDialog] Print error:', error);
            this.showToast('Print error: ' + error.message, 'error');
            return { success: false, error: error.message };
        }
    }

    /**
     * Show a toast notification
     */
    showToast(message, type = 'info') {
        // Use existing toast system if available
        if (window.DICOM_VIEWER?.showToast) {
            window.DICOM_VIEWER.showToast(message, type);
            return;
        }

        // Fallback toast
        const toast = document.createElement('div');
        toast.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} position-fixed`;
        toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 350px;';
        toast.innerHTML = `<i class="bi bi-${type === 'error' ? 'x-circle' : type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>${message}`;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
};

// Create singleton instance
window.DICOM_VIEWER.customPrintDialog = new window.DICOM_VIEWER.CustomPrintDialog();

console.log('[CustomPrintDialog] Module loaded');

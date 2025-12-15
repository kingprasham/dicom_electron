/**
 * Text Annotation Tool
 * Add text annotations to DICOM images with customizable font size and color
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.TextAnnotationTool = class {
    constructor() {
        this.isActive = false;
        this.annotations = new Map(); // viewport -> annotations array
        this.selectedAnnotation = null;
        this.isDragging = false;
        this.dragOffset = { x: 0, y: 0 };

        // Default settings
        this.settings = {
            fontSize: 16,
            fontColor: '#FFFF00', // Yellow - common for medical annotations
            fontFamily: 'Arial, sans-serif',
            backgroundColor: 'rgba(0, 0, 0, 0.6)',
            padding: 6
        };

        this.init();
    }

    init() {
        this.createToolUI();
        this.createInputDialog();
        this.setupEventListeners();
        console.log('✓ Text Annotation Tool initialized');
    }

    /**
     * Create tool button in the toolbar
     */
    createToolUI() {
        // Add Text Annotation button to tools panel
        const toolsPanel = document.getElementById('tools-panel');
        if (toolsPanel) {
            // Check if button already exists
            if (!document.getElementById('textAnnotationBtn')) {
                const btn = document.createElement('button');
                btn.id = 'textAnnotationBtn';
                btn.className = 'btn btn-secondary tool-btn';
                btn.setAttribute('data-tool', 'TextAnnotation');
                btn.setAttribute('title', 'Add Text Annotation (T)');
                btn.innerHTML = `
                    <i class="bi bi-fonts"></i>
                    <span class="tool-label">Text</span>
                `;

                // Find a good position - after existing tools
                const existingButtons = toolsPanel.querySelectorAll('.tool-btn');
                if (existingButtons.length > 0) {
                    existingButtons[existingButtons.length - 1].after(btn);
                } else {
                    toolsPanel.appendChild(btn);
                }

                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.toggleTool();
                });
            }
        }

        // Create settings panel
        this.createSettingsPanel();
    }

    /**
     * Create settings panel for font size and color
     */
    createSettingsPanel() {
        if (document.getElementById('textAnnotationSettings')) return;

        const settingsHTML = `
            <div id="textAnnotationSettings" class="text-annotation-settings" style="display: none;">
                <div class="settings-header">
                    <i class="bi bi-fonts me-2"></i>Text Annotation Settings
                    <button class="btn-close-settings" id="closeTextSettings">&times;</button>
                </div>
                <div class="settings-body">
                    <div class="setting-group">
                        <label>Font Size</label>
                        <div class="font-size-controls">
                            <input type="range" id="textFontSize" min="10" max="48" value="${this.settings.fontSize}" class="form-range">
                            <span id="fontSizeValue">${this.settings.fontSize}px</span>
                        </div>
                    </div>
                    <div class="setting-group">
                        <label>Text Color</label>
                        <div class="color-presets">
                            <button class="color-btn active" data-color="#FFFF00" style="background: #FFFF00" title="Yellow"></button>
                            <button class="color-btn" data-color="#FF0000" style="background: #FF0000" title="Red"></button>
                            <button class="color-btn" data-color="#00FF00" style="background: #00FF00" title="Green"></button>
                            <button class="color-btn" data-color="#00FFFF" style="background: #00FFFF" title="Cyan"></button>
                            <button class="color-btn" data-color="#FF00FF" style="background: #FF00FF" title="Magenta"></button>
                            <button class="color-btn" data-color="#FFFFFF" style="background: #FFFFFF" title="White"></button>
                            <button class="color-btn" data-color="#FFA500" style="background: #FFA500" title="Orange"></button>
                            <input type="color" id="customTextColor" value="${this.settings.fontColor}" title="Custom Color">
                        </div>
                    </div>
                </div>
            </div>

            <style id="textAnnotationStyles">
                .text-annotation-settings {
                    position: fixed;
                    top: 80px;
                    right: 20px;
                    width: 280px;
                    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                    border: 1px solid rgba(255, 255, 255, 0.15);
                    border-radius: 12px;
                    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
                    z-index: 1050;
                    overflow: hidden;
                }

                .settings-header {
                    background: rgba(13, 110, 253, 0.3);
                    color: #fff;
                    padding: 12px 15px;
                    font-weight: 600;
                    display: flex;
                    align-items: center;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                }

                .btn-close-settings {
                    margin-left: auto;
                    background: none;
                    border: none;
                    color: #fff;
                    font-size: 20px;
                    cursor: pointer;
                    opacity: 0.7;
                    transition: opacity 0.2s;
                }

                .btn-close-settings:hover {
                    opacity: 1;
                }

                .settings-body {
                    padding: 15px;
                }

                .setting-group {
                    margin-bottom: 15px;
                }

                .setting-group label {
                    display: block;
                    color: #aaa;
                    font-size: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin-bottom: 8px;
                }

                .font-size-controls {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .font-size-controls input[type="range"] {
                    flex: 1;
                }

                #fontSizeValue {
                    color: #ffc107;
                    font-weight: bold;
                    min-width: 45px;
                }

                .color-presets {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    align-items: center;
                }

                .color-btn {
                    width: 28px;
                    height: 28px;
                    border-radius: 50%;
                    border: 2px solid transparent;
                    cursor: pointer;
                    transition: all 0.2s;
                }

                .color-btn:hover {
                    transform: scale(1.15);
                }

                .color-btn.active {
                    border-color: #fff;
                    box-shadow: 0 0 8px rgba(255, 255, 255, 0.5);
                }

                #customTextColor {
                    width: 28px;
                    height: 28px;
                    border: none;
                    border-radius: 50%;
                    cursor: pointer;
                    background: transparent;
                }

                /* Text annotation overlay styles */
                .text-annotation {
                    position: absolute;
                    cursor: move;
                    user-select: none;
                    z-index: 100;
                    transition: box-shadow 0.2s;
                }

                .text-annotation:hover {
                    box-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
                }

                .text-annotation.selected {
                    box-shadow: 0 0 0 2px #0d6efd, 0 0 15px rgba(13, 110, 253, 0.5);
                }

                .text-annotation-content {
                    padding: 4px 8px;
                    border-radius: 4px;
                    white-space: nowrap;
                }

                .text-annotation-delete {
                    position: absolute;
                    top: -8px;
                    right: -8px;
                    width: 20px;
                    height: 20px;
                    background: #dc3545;
                    border: none;
                    border-radius: 50%;
                    color: #fff;
                    font-size: 12px;
                    cursor: pointer;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    line-height: 1;
                }

                .text-annotation.selected .text-annotation-delete {
                    display: flex;
                }

                /* Input dialog */
                .text-input-dialog {
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                    border: 1px solid rgba(255, 255, 255, 0.2);
                    border-radius: 12px;
                    padding: 20px;
                    z-index: 2000;
                    min-width: 320px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
                    display: none;
                }

                .text-input-dialog h5 {
                    color: #fff;
                    margin-bottom: 15px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .text-input-dialog input[type="text"] {
                    width: 100%;
                    padding: 10px 15px;
                    border: 1px solid rgba(255, 255, 255, 0.2);
                    border-radius: 8px;
                    background: rgba(0, 0, 0, 0.3);
                    color: #fff;
                    font-size: 16px;
                    margin-bottom: 15px;
                }

                .text-input-dialog input[type="text"]:focus {
                    outline: none;
                    border-color: #0d6efd;
                }

                .text-input-dialog .dialog-buttons {
                    display: flex;
                    gap: 10px;
                    justify-content: flex-end;
                }

                .dialog-backdrop {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 1999;
                    display: none;
                }
            </style>
        `;

        document.body.insertAdjacentHTML('beforeend', settingsHTML);

        // Setup settings event listeners
        this.setupSettingsListeners();
    }

    /**
     * Create text input dialog
     */
    createInputDialog() {
        if (document.getElementById('textInputDialog')) return;

        const dialogHTML = `
            <div class="dialog-backdrop" id="textDialogBackdrop"></div>
            <div class="text-input-dialog" id="textInputDialog">
                <h5><i class="bi bi-fonts"></i> Add Text Annotation</h5>
                <input type="text" id="textAnnotationInput" placeholder="Enter text..." autocomplete="off">
                <div class="dialog-buttons">
                    <button class="btn btn-secondary" id="cancelTextBtn">Cancel</button>
                    <button class="btn btn-primary" id="addTextBtn">Add Text</button>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', dialogHTML);

        // Dialog event listeners
        document.getElementById('cancelTextBtn').addEventListener('click', () => this.hideInputDialog());
        document.getElementById('addTextBtn').addEventListener('click', () => this.confirmAddText());
        document.getElementById('textDialogBackdrop').addEventListener('click', () => this.hideInputDialog());
        document.getElementById('textAnnotationInput').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this.confirmAddText();
            if (e.key === 'Escape') this.hideInputDialog();
        });
    }

    /**
     * Setup settings panel event listeners
     */
    setupSettingsListeners() {
        // Font size slider
        const fontSizeSlider = document.getElementById('textFontSize');
        const fontSizeValue = document.getElementById('fontSizeValue');
        if (fontSizeSlider) {
            fontSizeSlider.addEventListener('input', (e) => {
                this.settings.fontSize = parseInt(e.target.value);
                fontSizeValue.textContent = `${this.settings.fontSize}px`;
            });
        }

        // Color preset buttons
        document.querySelectorAll('.color-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.settings.fontColor = btn.dataset.color;
            });
        });

        // Custom color picker
        const customColor = document.getElementById('customTextColor');
        if (customColor) {
            customColor.addEventListener('input', (e) => {
                this.settings.fontColor = e.target.value;
                document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('active'));
            });
        }

        // Close button
        document.getElementById('closeTextSettings')?.addEventListener('click', () => {
            this.hideSettingsPanel();
        });
    }

    /**
     * Setup event listeners for viewports
     */
    setupEventListeners() {
        // Keyboard shortcut: T for text tool
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            if (e.key.toLowerCase() === 't' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault();
                this.toggleTool();
            }
        });

        // Click outside to deselect
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.text-annotation') && !e.target.closest('.text-input-dialog')) {
                this.deselectAnnotation();
            }
        });
    }

    /**
     * Toggle tool active state
     */
    toggleTool() {
        this.isActive = !this.isActive;
        const btn = document.getElementById('textAnnotationBtn');
        const settingsPanel = document.getElementById('textAnnotationSettings');

        if (this.isActive) {
            // Deactivate other tools
            document.querySelectorAll('.tool-btn').forEach(b => {
                b.classList.remove('btn-primary', 'active');
                b.classList.add('btn-secondary');
            });

            btn?.classList.remove('btn-secondary');
            btn?.classList.add('btn-primary', 'active');

            settingsPanel.style.display = 'block';
            this.activateOnViewports();

            window.DICOM_VIEWER.showAISuggestion('Text Annotation Tool active. Click on image to add text. Press T to toggle off.');
        } else {
            btn?.classList.remove('btn-primary', 'active');
            btn?.classList.add('btn-secondary');

            settingsPanel.style.display = 'none';
            this.deactivateFromViewports();
        }
    }

    /**
     * Activate tool on all viewports
     */
    activateOnViewports() {
        document.querySelectorAll('.viewport').forEach(viewport => {
            viewport.style.cursor = 'text';

            // Remove existing listener to prevent duplicates
            viewport.removeEventListener('click', viewport._textAnnotationHandler);

            // Create and store handler
            viewport._textAnnotationHandler = (e) => this.handleViewportClick(e, viewport);
            viewport.addEventListener('click', viewport._textAnnotationHandler);
        });
    }

    /**
     * Deactivate tool from viewports
     */
    deactivateFromViewports() {
        document.querySelectorAll('.viewport').forEach(viewport => {
            viewport.style.cursor = '';
            if (viewport._textAnnotationHandler) {
                viewport.removeEventListener('click', viewport._textAnnotationHandler);
            }
        });
    }

    /**
     * Handle viewport click to add text
     */
    handleViewportClick(e, viewport) {
        if (!this.isActive) return;
        if (e.target.closest('.text-annotation')) return; // Don't add when clicking existing annotation

        const rect = viewport.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        // Store click position for later
        this.pendingAnnotation = {
            viewport,
            x,
            y
        };

        this.showInputDialog();
    }

    /**
     * Show text input dialog
     */
    showInputDialog() {
        const dialog = document.getElementById('textInputDialog');
        const backdrop = document.getElementById('textDialogBackdrop');
        const input = document.getElementById('textAnnotationInput');

        dialog.style.display = 'block';
        backdrop.style.display = 'block';
        input.value = '';
        input.focus();
    }

    /**
     * Hide text input dialog
     */
    hideInputDialog() {
        const dialog = document.getElementById('textInputDialog');
        const backdrop = document.getElementById('textDialogBackdrop');

        dialog.style.display = 'none';
        backdrop.style.display = 'none';
        this.pendingAnnotation = null;
    }

    /**
     * Confirm and add text annotation
     */
    confirmAddText() {
        const input = document.getElementById('textAnnotationInput');
        const text = input.value.trim();

        if (!text || !this.pendingAnnotation) {
            this.hideInputDialog();
            return;
        }

        this.addAnnotation(
            this.pendingAnnotation.viewport,
            this.pendingAnnotation.x,
            this.pendingAnnotation.y,
            text
        );

        this.hideInputDialog();
    }

    /**
     * Add annotation to viewport
     */
    addAnnotation(viewport, x, y, text) {
        const annotation = document.createElement('div');
        annotation.className = 'text-annotation';
        annotation.dataset.id = Date.now().toString();

        annotation.style.cssText = `
            left: ${x}px;
            top: ${y}px;
        `;

        annotation.innerHTML = `
            <div class="text-annotation-content" style="
                font-size: ${this.settings.fontSize}px;
                color: ${this.settings.fontColor};
                font-family: ${this.settings.fontFamily};
                background: ${this.settings.backgroundColor};
                padding: ${this.settings.padding}px;
            ">${this.escapeHtml(text)}</div>
            <button class="text-annotation-delete" title="Delete">&times;</button>
        `;

        // Setup annotation event listeners
        this.setupAnnotationListeners(annotation, viewport);

        viewport.appendChild(annotation);

        // Store annotation reference
        if (!this.annotations.has(viewport)) {
            this.annotations.set(viewport, []);
        }
        this.annotations.get(viewport).push({
            id: annotation.dataset.id,
            element: annotation,
            text,
            settings: { ...this.settings }
        });

        window.DICOM_VIEWER.showAISuggestion(`Text annotation added: "${text}"`);
    }

    /**
     * Setup event listeners for individual annotation
     */
    setupAnnotationListeners(annotation, viewport) {
        // Select on click
        annotation.addEventListener('click', (e) => {
            e.stopPropagation();
            this.selectAnnotation(annotation);
        });

        // Double-click to edit
        annotation.addEventListener('dblclick', (e) => {
            e.stopPropagation();
            this.editAnnotation(annotation);
        });

        // Delete button
        annotation.querySelector('.text-annotation-delete').addEventListener('click', (e) => {
            e.stopPropagation();
            this.deleteAnnotation(annotation, viewport);
        });

        // Drag to move
        annotation.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('text-annotation-delete')) return;
            this.startDrag(e, annotation);
        });
    }

    /**
     * Start dragging annotation
     */
    startDrag(e, annotation) {
        this.isDragging = true;
        this.selectedAnnotation = annotation;

        const rect = annotation.getBoundingClientRect();
        this.dragOffset = {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };

        annotation.classList.add('selected');

        const onMouseMove = (e) => {
            if (!this.isDragging) return;

            const viewport = annotation.parentElement;
            const viewportRect = viewport.getBoundingClientRect();

            let newX = e.clientX - viewportRect.left - this.dragOffset.x;
            let newY = e.clientY - viewportRect.top - this.dragOffset.y;

            // Keep within viewport bounds
            newX = Math.max(0, Math.min(newX, viewportRect.width - annotation.offsetWidth));
            newY = Math.max(0, Math.min(newY, viewportRect.height - annotation.offsetHeight));

            annotation.style.left = `${newX}px`;
            annotation.style.top = `${newY}px`;
        };

        const onMouseUp = () => {
            this.isDragging = false;
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        };

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    }

    /**
     * Select annotation
     */
    selectAnnotation(annotation) {
        this.deselectAnnotation();
        this.selectedAnnotation = annotation;
        annotation.classList.add('selected');
    }

    /**
     * Deselect annotation
     */
    deselectAnnotation() {
        if (this.selectedAnnotation) {
            this.selectedAnnotation.classList.remove('selected');
            this.selectedAnnotation = null;
        }
    }

    /**
     * Edit annotation text
     */
    editAnnotation(annotation) {
        const contentEl = annotation.querySelector('.text-annotation-content');
        const currentText = contentEl.textContent;

        this.pendingAnnotation = {
            viewport: annotation.parentElement,
            annotation,
            isEdit: true
        };

        const input = document.getElementById('textAnnotationInput');
        input.value = currentText;

        document.getElementById('textInputDialog').querySelector('h5').innerHTML =
            '<i class="bi bi-pencil"></i> Edit Text Annotation';

        this.showInputDialog();

        // Override confirm to edit instead of add
        const originalConfirm = this.confirmAddText.bind(this);
        this.confirmAddText = () => {
            const newText = input.value.trim();
            if (newText) {
                contentEl.textContent = newText;
                window.DICOM_VIEWER.showAISuggestion(`Annotation updated: "${newText}"`);
            }
            this.hideInputDialog();
            this.confirmAddText = originalConfirm;
            document.getElementById('textInputDialog').querySelector('h5').innerHTML =
                '<i class="bi bi-fonts"></i> Add Text Annotation';
        };
    }

    /**
     * Delete annotation
     */
    deleteAnnotation(annotation, viewport) {
        annotation.remove();

        // Remove from stored annotations
        if (this.annotations.has(viewport)) {
            const annotations = this.annotations.get(viewport);
            const index = annotations.findIndex(a => a.id === annotation.dataset.id);
            if (index > -1) {
                annotations.splice(index, 1);
            }
        }

        window.DICOM_VIEWER.showAISuggestion('Text annotation deleted');
    }

    /**
     * Show settings panel
     */
    showSettingsPanel() {
        document.getElementById('textAnnotationSettings').style.display = 'block';
    }

    /**
     * Hide settings panel
     */
    hideSettingsPanel() {
        document.getElementById('textAnnotationSettings').style.display = 'none';
    }

    /**
     * Clear all annotations from a viewport
     */
    clearViewportAnnotations(viewport) {
        viewport.querySelectorAll('.text-annotation').forEach(a => a.remove());
        this.annotations.delete(viewport);
    }

    /**
     * Clear all annotations from all viewports
     */
    clearAllAnnotations() {
        document.querySelectorAll('.text-annotation').forEach(a => a.remove());
        this.annotations.clear();
        window.DICOM_VIEWER.showAISuggestion('All text annotations cleared');
    }

    /**
     * Get annotations for export/save
     */
    getAnnotationsData() {
        const data = [];
        this.annotations.forEach((annotations, viewport) => {
            annotations.forEach(ann => {
                const rect = ann.element.getBoundingClientRect();
                const viewportRect = viewport.getBoundingClientRect();
                data.push({
                    text: ann.text,
                    x: parseFloat(ann.element.style.left),
                    y: parseFloat(ann.element.style.top),
                    settings: ann.settings,
                    viewportId: viewport.id || viewport.dataset.viewportName
                });
            });
        });
        return data;
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    if (!window.DICOM_VIEWER.MANAGERS) {
        window.DICOM_VIEWER.MANAGERS = {};
    }

    // Initialize after a short delay to ensure other components are ready
    setTimeout(() => {
        window.DICOM_VIEWER.MANAGERS.textAnnotationTool = new window.DICOM_VIEWER.TextAnnotationTool();
    }, 1000);
});

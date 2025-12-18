/**
 * Text Annotation Tool
 * Add text annotations to DICOM images with customizable font size and color
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.TextAnnotationTool = class {
    constructor() {
        this.isActive = false;
        this.annotationsByImageId = new Map(); // imageId -> annotations array (persistent storage)
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
     * Setup tool button (already exists in index.php, just add event listener)
     */
    createToolUI() {
        // Button is defined in index.php, just attach the click handler
        const btn = document.getElementById('textAnnotationBtn');
        if (btn) {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleTool();
            });
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
                    <div class="setting-group" style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 12px; margin-top: 8px;">
                        <button id="applyToSelectedText" class="btn btn-sm btn-outline-primary w-100" style="font-size: 12px;">
                            <i class="bi bi-check2-circle me-1"></i>Apply to Selected
                        </button>
                        <small class="d-block text-muted mt-1" style="font-size: 10px;">Click a text annotation to select it, then click this button</small>
                    </div>
                </div>
            </div>

            <style id="textAnnotationStyles">
                /* Settings panel - positioned near the Text button in right sidebar */
                .text-annotation-settings {
                    position: fixed;
                    top: 120px;
                    right: 270px;
                    width: 240px;
                    background: rgba(28, 33, 40, 0.98);
                    backdrop-filter: blur(12px);
                    border: 1px solid rgba(13, 110, 253, 0.4);
                    border-radius: 10px;
                    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255,255,255,0.05);
                    z-index: 1050;
                    overflow: hidden;
                    animation: slideIn 0.2s ease-out;
                }

                @keyframes slideIn {
                    from { opacity: 0; transform: translateX(10px); }
                    to { opacity: 1; transform: translateX(0); }
                }

                .settings-header {
                    background: linear-gradient(135deg, rgba(13, 110, 253, 0.2) 0%, rgba(13, 110, 253, 0.1) 100%);
                    color: #fff;
                    padding: 10px 14px;
                    font-weight: 600;
                    font-size: 13px;
                    display: flex;
                    align-items: center;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
                }

                .btn-close-settings {
                    margin-left: auto;
                    background: none;
                    border: none;
                    color: rgba(255,255,255,0.6);
                    font-size: 18px;
                    cursor: pointer;
                    width: 24px;
                    height: 24px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 4px;
                    transition: all 0.2s;
                }

                .btn-close-settings:hover {
                    background: rgba(255,255,255,0.1);
                    color: #fff;
                }

                .settings-body {
                    padding: 12px 14px;
                }

                .setting-group {
                    margin-bottom: 14px;
                }

                .setting-group:last-child {
                    margin-bottom: 0;
                }

                .setting-group label {
                    display: block;
                    color: rgba(255,255,255,0.6);
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin-bottom: 8px;
                    font-weight: 500;
                }

                .font-size-controls {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .font-size-controls input[type="range"] {
                    flex: 1;
                    height: 4px;
                }

                #fontSizeValue {
                    color: #0d6efd;
                    font-weight: 600;
                    font-size: 13px;
                    min-width: 40px;
                    text-align: right;
                }

                .color-presets {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 6px;
                    align-items: center;
                }

                .color-btn {
                    width: 24px;
                    height: 24px;
                    border-radius: 4px;
                    border: 2px solid rgba(255,255,255,0.2);
                    cursor: pointer;
                    transition: all 0.15s;
                }

                .color-btn:hover {
                    transform: scale(1.1);
                    border-color: rgba(255,255,255,0.5);
                }

                .color-btn.active {
                    border-color: #fff;
                    box-shadow: 0 0 8px rgba(255, 255, 255, 0.4);
                }

                #customTextColor {
                    width: 24px;
                    height: 24px;
                    border: 2px solid rgba(255,255,255,0.3);
                    border-radius: 4px;
                    cursor: pointer;
                    background: linear-gradient(135deg, #ff0000, #00ff00, #0000ff);
                    padding: 0;
                }

                /* Text annotation overlay styles */
                .text-annotation {
                    position: absolute;
                    cursor: move;
                    user-select: none;
                    z-index: 100;
                    transition: box-shadow 0.15s, transform 0.1s;
                }

                .text-annotation:hover {
                    box-shadow: 0 2px 12px rgba(255, 255, 255, 0.2);
                }

                .text-annotation.selected {
                    box-shadow: 0 0 0 2px #0d6efd, 0 4px 20px rgba(13, 110, 253, 0.4);
                }

                .text-annotation-content {
                    padding: 4px 8px;
                    border-radius: 3px;
                    white-space: nowrap;
                    text-shadow: 0 1px 2px rgba(0,0,0,0.8);
                }

                .text-annotation-delete {
                    position: absolute;
                    top: -10px;
                    right: -10px;
                    width: 22px;
                    height: 22px;
                    background: #dc3545;
                    border: 2px solid rgba(255,255,255,0.3);
                    border-radius: 50%;
                    color: #fff;
                    font-size: 14px;
                    font-weight: bold;
                    cursor: pointer;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    line-height: 1;
                    transition: all 0.15s;
                }

                .text-annotation-delete:hover {
                    background: #c82333;
                    transform: scale(1.1);
                }

                .text-annotation.selected .text-annotation-delete {
                    display: flex;
                }

                /* Compact text input dialog - inline style */
                .text-input-dialog {
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: rgba(28, 33, 40, 0.98);
                    backdrop-filter: blur(16px);
                    border: 1px solid rgba(13, 110, 253, 0.4);
                    border-radius: 12px;
                    padding: 0;
                    z-index: 2000;
                    min-width: 300px;
                    max-width: 360px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255,255,255,0.05);
                    display: none;
                    overflow: hidden;
                    animation: dialogIn 0.2s ease-out;
                }

                @keyframes dialogIn {
                    from { opacity: 0; transform: translate(-50%, -50%) scale(0.95); }
                    to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                }

                .text-input-dialog h5 {
                    color: #fff;
                    margin: 0;
                    padding: 14px 18px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    font-size: 14px;
                    font-weight: 600;
                    background: linear-gradient(135deg, rgba(13, 110, 253, 0.15) 0%, transparent 100%);
                    border-bottom: 1px solid rgba(255,255,255,0.08);
                }

                .text-input-dialog h5 i {
                    color: #0d6efd;
                }

                .text-input-dialog .dialog-content {
                    padding: 16px 18px;
                }

                .text-input-dialog input[type="text"] {
                    width: 100%;
                    padding: 12px 14px;
                    border: 1px solid rgba(255, 255, 255, 0.15);
                    border-radius: 8px;
                    background: rgba(0, 0, 0, 0.3);
                    color: #fff;
                    font-size: 15px;
                    transition: all 0.2s;
                }

                .text-input-dialog input[type="text"]::placeholder {
                    color: rgba(255,255,255,0.4);
                }

                .text-input-dialog input[type="text"]:focus {
                    outline: none;
                    border-color: #0d6efd;
                    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.2);
                }

                .text-input-dialog .dialog-buttons {
                    display: flex;
                    gap: 10px;
                    justify-content: flex-end;
                    padding: 12px 18px;
                    background: rgba(0,0,0,0.2);
                    border-top: 1px solid rgba(255,255,255,0.05);
                }

                .text-input-dialog .dialog-buttons .btn {
                    padding: 8px 16px;
                    font-size: 13px;
                    font-weight: 500;
                    border-radius: 6px;
                }

                .dialog-backdrop {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.6);
                    backdrop-filter: blur(4px);
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
                <div class="dialog-content">
                    <input type="text" id="textAnnotationInput" placeholder="Type your annotation..." autocomplete="off">
                </div>
                <div class="dialog-buttons">
                    <button class="btn btn-secondary btn-sm" id="cancelTextBtn">Cancel</button>
                    <button class="btn btn-primary btn-sm" id="addTextBtn"><i class="bi bi-check2 me-1"></i>Add</button>
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

        // Apply to Selected button
        document.getElementById('applyToSelectedText')?.addEventListener('click', () => {
            this.applySettingsToSelected();
        });
    }

    /**
     * Apply current settings to the selected annotation
     */
    applySettingsToSelected() {
        if (!this.selectedAnnotation) {
            window.DICOM_VIEWER.showAISuggestion('No annotation selected. Click a text annotation first.');
            return;
        }

        const content = this.selectedAnnotation.querySelector('.text-annotation-content');
        if (!content) return;

        // Apply current settings to the selected annotation's DOM
        content.style.fontSize = `${this.settings.fontSize}px`;
        content.style.color = this.settings.fontColor;
        content.style.fontFamily = this.settings.fontFamily;
        content.style.background = this.settings.backgroundColor;
        content.style.padding = `${this.settings.padding}px`;

        // Update stored annotation data
        const annotationId = this.selectedAnnotation.dataset.id;
        const imageId = this.selectedAnnotation.dataset.imageId;

        if (imageId && this.annotationsByImageId.has(imageId)) {
            const annotations = this.annotationsByImageId.get(imageId);
            const stored = annotations.find(a => a.id === annotationId);
            if (stored) {
                stored.settings = { ...this.settings };
            }
        }

        window.DICOM_VIEWER.showAISuggestion(`Updated annotation style: ${this.settings.fontSize}px, ${this.settings.fontColor}`);
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

        // Deactivate text tool when other tools are clicked
        document.addEventListener('click', (e) => {
            const toolBtn = e.target.closest('.tool-btn');
            if (toolBtn && toolBtn.id !== 'textAnnotationBtn' && this.isActive) {
                // Another tool was clicked, deactivate text tool
                this.isActive = false;
                const btn = document.getElementById('textAnnotationBtn');
                btn?.classList.remove('btn-primary', 'active');
                btn?.classList.add('btn-secondary');
                document.getElementById('textAnnotationSettings').style.display = 'none';
                this.deactivateFromViewports();
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
                delete viewport._textAnnotationHandler; // Clear the reference
            }
        });

        // Also hide any open dialogs
        this.hideInputDialog();
    }

    /**
     * Handle viewport click to add text
     */
    handleViewportClick(e, viewport) {
        console.log('Text annotation handleViewportClick called', {
            isActive: this.isActive,
            target: e.target,
            viewport: viewport.id
        });

        if (!this.isActive) {
            console.log('Text tool not active, ignoring click');
            return;
        }

        if (e.target.closest('.text-annotation')) {
            console.log('Clicked on existing annotation, ignoring');
            return; // Don't add when clicking existing annotation
        }

        const rect = viewport.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        console.log(`Adding text annotation at position (${x}, ${y})`);

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

        if (!dialog || !backdrop || !input) {
            console.error('Text annotation dialog elements not found in DOM');
            return;
        }

        // Ensure backdrop is shown first with explicit z-index
        backdrop.style.display = 'block';
        backdrop.style.zIndex = '9999';

        // Show dialog on top with explicit z-index
        dialog.style.display = 'block';
        dialog.style.zIndex = '10000';

        input.value = '';

        // Use requestAnimationFrame to ensure DOM has updated before focusing
        requestAnimationFrame(() => {
            input.focus();
        });

        console.log('Text input dialog shown');
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
     * Add annotation to viewport (tied to the current image in that viewport)
     */
    addAnnotation(viewport, x, y, text) {
        // Get the current image ID for this viewport
        const imageId = this.getViewportImageId(viewport);
        if (!imageId) {
            console.warn('Cannot add annotation: no image loaded in viewport');
            return;
        }

        const annotationId = Date.now().toString();
        const annotation = document.createElement('div');
        annotation.className = 'text-annotation';
        annotation.dataset.id = annotationId;
        annotation.dataset.imageId = imageId;

        // Store position as percentage for resolution independence
        const viewportRect = viewport.getBoundingClientRect();
        const xPercent = (x / viewportRect.width) * 100;
        const yPercent = (y / viewportRect.height) * 100;

        annotation.style.cssText = `
            left: ${xPercent}%;
            top: ${yPercent}%;
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

        // Store annotation data by image ID
        if (!this.annotationsByImageId.has(imageId)) {
            this.annotationsByImageId.set(imageId, []);
        }
        this.annotationsByImageId.get(imageId).push({
            id: annotationId,
            text,
            xPercent,
            yPercent,
            settings: { ...this.settings }
        });

        console.log(`Annotation added for image ${imageId}:`, text);
        window.DICOM_VIEWER.showAISuggestion(`Text annotation added: "${text}"`);
    }

    /**
     * Get the image ID currently displayed in a viewport
     */
    getViewportImageId(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            return enabledElement?.image?.imageId || null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Restore annotations for the current image in a viewport
     */
    restoreAnnotationsForViewport(viewport) {
        const imageId = this.getViewportImageId(viewport);
        if (!imageId) return;

        // Clear existing DOM annotations in this viewport
        viewport.querySelectorAll('.text-annotation').forEach(el => el.remove());

        // Restore annotations for this image
        const annotations = this.annotationsByImageId.get(imageId);
        if (!annotations || annotations.length === 0) return;

        annotations.forEach(ann => {
            const annotation = document.createElement('div');
            annotation.className = 'text-annotation';
            annotation.dataset.id = ann.id;
            annotation.dataset.imageId = imageId;

            annotation.style.cssText = `
                left: ${ann.xPercent}%;
                top: ${ann.yPercent}%;
            `;

            annotation.innerHTML = `
                <div class="text-annotation-content" style="
                    font-size: ${ann.settings.fontSize}px;
                    color: ${ann.settings.fontColor};
                    font-family: ${ann.settings.fontFamily};
                    background: ${ann.settings.backgroundColor};
                    padding: ${ann.settings.padding}px;
                ">${this.escapeHtml(ann.text)}</div>
                <button class="text-annotation-delete" title="Delete">&times;</button>
            `;

            this.setupAnnotationListeners(annotation, viewport);
            viewport.appendChild(annotation);
        });

        console.log(`Restored ${annotations.length} annotations for image ${imageId}`);
    }

    /**
     * Clear DOM annotations from viewport (but keep stored data)
     */
    clearViewportAnnotationDOM(viewport) {
        viewport.querySelectorAll('.text-annotation').forEach(el => el.remove());
    }

    /**
     * Called when page changes - restore annotations for new images
     */
    onPageChange() {
        document.querySelectorAll('.viewport').forEach(viewport => {
            this.restoreAnnotationsForViewport(viewport);
        });
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

            // Convert to percentage
            const xPercent = (newX / viewportRect.width) * 100;
            const yPercent = (newY / viewportRect.height) * 100;

            annotation.style.left = `${xPercent}%`;
            annotation.style.top = `${yPercent}%`;
        };

        const onMouseUp = () => {
            this.isDragging = false;
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);

            // Update stored position
            const imageId = annotation.dataset.imageId;
            const annotationId = annotation.dataset.id;
            if (imageId && this.annotationsByImageId.has(imageId)) {
                const annotations = this.annotationsByImageId.get(imageId);
                const ann = annotations.find(a => a.id === annotationId);
                if (ann) {
                    const viewport = annotation.parentElement;
                    const viewportRect = viewport.getBoundingClientRect();
                    const left = parseFloat(annotation.style.left);
                    const top = parseFloat(annotation.style.top);
                    ann.xPercent = left;
                    ann.yPercent = top;
                }
            }
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
        const imageId = annotation.dataset.imageId;
        const annotationId = annotation.dataset.id;

        annotation.remove();

        // Remove from stored annotations by image ID
        if (imageId && this.annotationsByImageId.has(imageId)) {
            const annotations = this.annotationsByImageId.get(imageId);
            const index = annotations.findIndex(a => a.id === annotationId);
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

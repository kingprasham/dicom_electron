// Custom Mouse Controls Manager - Fixed Version
window.DICOM_VIEWER.MouseControlsManager = class {
    constructor() {
        this.isRightMouseDown = false;
        this.isMiddleMouseDown = false;
        this.lastMousePosition = { x: 0, y: 0 };
        this.startMousePosition = { x: 0, y: 0 };
        this.originalWindowLevel = { width: 0, center: 0 };
        this.panStartTranslation = { x: 0, y: 0 };
        this.allPanStartTranslations = new Map(); // Store start translations for all selected viewports
        this.allOriginalWindowLevels = new Map(); // Store original W/L for all selected viewports
        this.isEnabled = true;
        this.activeViewport = null;
        this.boundEventHandlers = new Map();
    }

    initialize() {
        console.log('Initializing custom mouse controls...');
        
        // First disable all Cornerstone tools
        this.disableAllCornerstoneTools();
        
        // Then setup our custom controls
        this.setupCustomMouseControls();
        
        // Setup for existing and future viewports
        this.setupExistingViewports();
        this.observeNewViewports();
        
        // Setup crosshair and reference lines checkboxes
        this.setupDisplayOptionsCheckboxes();
    }

    setupDisplayOptionsCheckboxes() {
        console.log('Setting up display options checkboxes...');
        
        // Setup Crosshair checkbox
        const crosshairCheckbox = document.getElementById('showCrosshairs');
        if (crosshairCheckbox) {
            crosshairCheckbox.addEventListener('change', (e) => {
                const manager = window.DICOM_VIEWER.MANAGERS.crosshairManager;
                if (manager) {
                    if (e.target.checked) {
                        manager.enable();
                        console.log('Crosshairs enabled');
                    } else {
                        manager.disable();
                        console.log('Crosshairs disabled');
                    }
                }
            });
            
            // Initialize crosshairs based on checkbox state
            const manager = window.DICOM_VIEWER.MANAGERS.crosshairManager;
            if (manager) {
                if (crosshairCheckbox.checked) {
                    manager.enable();
                    console.log('Crosshairs initialized as enabled');
                } else {
                    manager.disable();
                    console.log('Crosshairs initialized as disabled');
                }
            }
        } else {
            console.warn('Crosshairs checkbox not found');
        }
        
        // Reference Lines checkbox - disabled feature
        const refLinesCheckbox = document.getElementById('enableReferenceLines');
        if (refLinesCheckbox) {
            console.log('Reference lines checkbox found (feature not implemented)');
        }
    }

    disableAllCornerstoneTools() {
        try {
            // Disable all cornerstone tools
            const tools = ['Pan', 'Zoom', 'Wwwc', 'StackScrollMouseWheel', 'Length', 'Angle', 'FreehandRoi', 'EllipticalRoi', 'RectangleRoi', 'Probe'];
            tools.forEach(tool => {
                try {
                    cornerstoneTools.setToolDisabled(tool);
                } catch (e) {
                    // Tool might not exist, continue
                }
            });
            console.log('Disabled all Cornerstone tools for custom mouse control');
        } catch (error) {
            console.warn('Error disabling Cornerstone tools:', error);
        }
    }

    setupCustomMouseControls() {
        // Remove any existing listeners
        this.removeAllListeners();

        // Add global event listeners
        this.addGlobalListener('mousedown', this.handleMouseDown.bind(this));
        this.addGlobalListener('mousemove', this.handleMouseMove.bind(this));
        this.addGlobalListener('mouseup', this.handleMouseUp.bind(this));
        this.addGlobalListener('contextmenu', this.handleContextMenu.bind(this));
        
        // Prevent text selection during mouse operations
        this.addGlobalListener('selectstart', (e) => {
            if (this.isRightMouseDown || this.isMiddleMouseDown) {
                e.preventDefault();
            }
        });
    }

    addGlobalListener(event, handler) {
        document.addEventListener(event, handler, true);
        this.boundEventHandlers.set(event, handler);
    }

    removeAllListeners() {
        this.boundEventHandlers.forEach((handler, event) => {
            document.removeEventListener(event, handler, true);
        });
        this.boundEventHandlers.clear();
    }

    setupExistingViewports() {
        document.querySelectorAll('.viewport').forEach(viewport => {
            this.setupViewportListeners(viewport);
        });
    }

    observeNewViewports() {
        // Watch for new viewports being added
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.classList && node.classList.contains('viewport')) {
                            this.setupViewportListeners(node);
                        }
                        // Also check children
                        const viewports = node.querySelectorAll && node.querySelectorAll('.viewport');
                        if (viewports) {
                            viewports.forEach(vp => this.setupViewportListeners(vp));
                        }
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    setupViewportListeners(viewport) {
        console.log('Setting up custom listeners for viewport:', viewport.dataset.viewportName);

        // Remove any existing wheel listeners on this viewport
        const existingWheelHandler = viewport._customWheelHandler;
        if (existingWheelHandler) {
            viewport.removeEventListener('wheel', existingWheelHandler);
        }

        // Create new wheel handler
        const wheelHandler = (event) => {
            this.handleWheel(event, viewport);
        };

        // Store reference for cleanup
        viewport._customWheelHandler = wheelHandler;

        // Add wheel listener with high priority
        viewport.addEventListener('wheel', wheelHandler, { 
            passive: false, 
            capture: true 
        });

        // Also disable cornerstone tools on this specific viewport
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (enabledElement) {
                // Remove cornerstone event listeners
                cornerstone.events.removeEventListener(viewport, 'cornerstonetoolsmousewheel');
                cornerstone.events.removeEventListener(viewport, 'cornerstonetoolsmousedown');
                cornerstone.events.removeEventListener(viewport, 'cornerstonetoolsmousemove');
                cornerstone.events.removeEventListener(viewport, 'cornerstonetoolsmouseup');
            }
        } catch (error) {
            // Viewport might not be enabled yet
            console.log('Viewport not yet enabled, will retry when image loads');
        }
    }

    handleMouseDown(event) {
        if (!this.isEnabled) return;
        
        const viewport = event.target.closest('.viewport');
        if (!viewport) return;

        this.activeViewport = viewport;
        this.lastMousePosition = { x: event.clientX, y: event.clientY };
        this.startMousePosition = { x: event.clientX, y: event.clientY };

        if (event.button === 2) { // Right mouse button
            event.preventDefault();
            event.stopPropagation();
            this.isRightMouseDown = true;
            this.startWindowLevelAdjustment(viewport);
            document.body.style.cursor = 'ew-resize';
        } else if (event.button === 1) { // Middle mouse button
            event.preventDefault();
            event.stopPropagation();
            this.isMiddleMouseDown = true;
            this.startPanOperation(viewport);
            document.body.style.cursor = 'move';
        }
    }

    handleMouseMove(event) {
        if (!this.isEnabled || !this.activeViewport) return;

        if (this.isRightMouseDown) {
            event.preventDefault();
            event.stopPropagation();
            this.performWindowLevelAdjustment(event);
        } else if (this.isMiddleMouseDown) {
            event.preventDefault();
            event.stopPropagation();
            this.performPanOperation(event);
        }

        this.lastMousePosition = { x: event.clientX, y: event.clientY };
    }

    handleMouseUp(event) {
        if (!this.isEnabled) return;

        if (event.button === 2) { // Right mouse button
            this.isRightMouseDown = false;
            document.body.style.cursor = 'default';
        } else if (event.button === 1) { // Middle mouse button
            this.isMiddleMouseDown = false;
            document.body.style.cursor = 'default';
        }

        if (!this.isRightMouseDown && !this.isMiddleMouseDown) {
            this.activeViewport = null;
        }
    }

    handleWheel(event, viewport) {
        if (!this.isEnabled) return;

        console.log('Custom wheel event triggered on viewport:', viewport.dataset.viewportName);
        
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        // Perform zoom operation
        this.performZoomOperation(viewport, event.deltaY);
        
        return false;
    }

    handleContextMenu(event) {
        // Prevent context menu when right-clicking on viewports
        const viewport = event.target.closest('.viewport');
        if (viewport) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    performZoomOperation(viewport, deltaY) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (!enabledElement || !enabledElement.image) {
                console.log('No enabled element or image for zoom');
                return;
            }

            console.log('Performing zoom operation, deltaY:', deltaY);

            // More sensitive zoom
            const zoomSensitivity = 0.002;
            const zoomDirection = deltaY > 0 ? -1 : 1; // Reverse for natural zoom direction
            const zoomFactor = 1 + (zoomDirection * zoomSensitivity * Math.abs(deltaY));

            // ENHANCED: Apply zoom to all selected viewports if applicable
            const state = window.DICOM_VIEWER.STATE;
            const viewportsToZoom = [];
            
            // Check if this viewport is in the selected set
            if (state.selectedViewports && state.selectedViewports.size > 1 && state.selectedViewports.has(viewport.id)) {
                // Apply zoom to all selected viewports
                state.selectedViewports.forEach(vpId => {
                    const vp = document.getElementById(vpId);
                    if (vp) viewportsToZoom.push(vp);
                });
            } else {
                // Just zoom this viewport
                viewportsToZoom.push(viewport);
            }
            
            viewportsToZoom.forEach(vp => {
                try {
                    const cornerstoneViewport = cornerstone.getViewport(vp);
                    if (!cornerstoneViewport) return;
                    
                    const currentScale = cornerstoneViewport.scale;
                    const newScale = Math.max(0.1, Math.min(10, currentScale * zoomFactor));

                    cornerstoneViewport.scale = newScale;
                    cornerstone.setViewport(vp, cornerstoneViewport);
                    cornerstone.updateImage(vp);
                } catch (err) {
                    // Ignore individual viewport errors
                }
            });

            // Update viewport info
            if (window.DICOM_VIEWER.updateViewportInfo) {
                window.DICOM_VIEWER.updateViewportInfo();
            }

        } catch (error) {
            console.error('Error during zoom operation:', error);
        }
    }

    startWindowLevelAdjustment(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (enabledElement && enabledElement.image) {
                const cornerstoneViewport = cornerstone.getViewport(viewport);
                this.originalWindowLevel = {
                    width: cornerstoneViewport.voi.windowWidth,
                    center: cornerstoneViewport.voi.windowCenter
                };
                
                // ENHANCED: Store original W/L for all selected viewports
                this.allOriginalWindowLevels.clear();
                const state = window.DICOM_VIEWER.STATE;
                if (state.selectedViewports && state.selectedViewports.size > 1 && state.selectedViewports.has(viewport.id)) {
                    state.selectedViewports.forEach(vpId => {
                        const vp = document.getElementById(vpId);
                        if (vp) {
                            try {
                                const vpViewport = cornerstone.getViewport(vp);
                                if (vpViewport && vpViewport.voi) {
                                    this.allOriginalWindowLevels.set(vpId, {
                                        width: vpViewport.voi.windowWidth,
                                        center: vpViewport.voi.windowCenter
                                    });
                                }
                            } catch (err) {
                                // Ignore
                            }
                        }
                    });
                }
            }
        } catch (error) {
            console.warn('Could not get viewport for W/L adjustment:', error);
        }
    }

    performWindowLevelAdjustment(event) {
        if (!this.activeViewport) return;

        try {
            const enabledElement = cornerstone.getEnabledElement(this.activeViewport);
            if (!enabledElement || !enabledElement.image) return;

            const deltaX = event.clientX - this.startMousePosition.x;
            const deltaY = event.clientY - this.startMousePosition.y;

            // Sensitivity factors
            const windowSensitivity = 3.0;
            const levelSensitivity = 2.0;

            // Calculate new window/level values
            const newWindowWidth = Math.max(1, this.originalWindowLevel.width + (deltaX * windowSensitivity));
            const newWindowCenter = this.originalWindowLevel.center + (deltaY * levelSensitivity);

            // ENHANCED: Apply W/L to all selected viewports if applicable
            const state = window.DICOM_VIEWER.STATE;
            const viewportsToAdjust = [];
            
            // Check if this viewport is in the selected set
            if (state.selectedViewports && state.selectedViewports.size > 1 && state.selectedViewports.has(this.activeViewport.id)) {
                // Apply W/L to all selected viewports
                state.selectedViewports.forEach(vpId => {
                    const vp = document.getElementById(vpId);
                    if (vp) viewportsToAdjust.push(vp);
                });
            } else {
                // Just adjust this viewport
                viewportsToAdjust.push(this.activeViewport);
            }
            
            // Calculate the delta from original values
            const wlDelta = {
                width: newWindowWidth - this.originalWindowLevel.width,
                center: newWindowCenter - this.originalWindowLevel.center
            };
            
            viewportsToAdjust.forEach(vp => {
                try {
                    const viewport = cornerstone.getViewport(vp);
                    if (!viewport) return;
                    
                    // Get the stored original W/L for this viewport
                    const originalWL = this.allOriginalWindowLevels.get(vp.id);
                    
                    if (originalWL) {
                        // Apply the same delta to all viewports from their original W/L
                        viewport.voi.windowWidth = Math.max(1, originalWL.width + wlDelta.width);
                        viewport.voi.windowCenter = originalWL.center + wlDelta.center;
                    } else if (vp === this.activeViewport) {
                        // For the active viewport (if not in multi-select), use absolute values
                        viewport.voi.windowWidth = newWindowWidth;
                        viewport.voi.windowCenter = newWindowCenter;
                    }
                    
                    cornerstone.setViewport(vp, viewport);
                    cornerstone.updateImage(vp);
                } catch (err) {
                    // Ignore individual viewport errors
                }
            });

            // Update UI controls
            this.updateWindowLevelUI(newWindowWidth, newWindowCenter);

        } catch (error) {
            console.warn('Error during W/L adjustment:', error);
        }
    }

    startPanOperation(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (enabledElement && enabledElement.image) {
                const cornerstoneViewport = cornerstone.getViewport(viewport);
                this.panStartTranslation = {
                    x: cornerstoneViewport.translation.x,
                    y: cornerstoneViewport.translation.y
                };
                
                // ENHANCED: Store start translations for all selected viewports
                this.allPanStartTranslations.clear();
                const state = window.DICOM_VIEWER.STATE;
                if (state.selectedViewports && state.selectedViewports.size > 1 && state.selectedViewports.has(viewport.id)) {
                    state.selectedViewports.forEach(vpId => {
                        const vp = document.getElementById(vpId);
                        if (vp) {
                            try {
                                const vpViewport = cornerstone.getViewport(vp);
                                if (vpViewport) {
                                    this.allPanStartTranslations.set(vpId, {
                                        x: vpViewport.translation.x,
                                        y: vpViewport.translation.y
                                    });
                                }
                            } catch (err) {
                                // Ignore
                            }
                        }
                    });
                }
            }
        } catch (error) {
            console.warn('Could not get viewport for pan operation:', error);
        }
    }

    performPanOperation(event) {
        if (!this.activeViewport) return;

        try {
            const enabledElement = cornerstone.getEnabledElement(this.activeViewport);
            if (!enabledElement || !enabledElement.image) return;

            const deltaX = event.clientX - this.startMousePosition.x;
            const deltaY = event.clientY - this.startMousePosition.y;

            // Pan sensitivity
            const panSensitivity = 1.0;
            
            // Calculate new translation values
            const newTranslationX = this.panStartTranslation.x + (deltaX * panSensitivity);
            const newTranslationY = this.panStartTranslation.y + (deltaY * panSensitivity);

            // ENHANCED: Apply pan to all selected viewports if applicable
            const state = window.DICOM_VIEWER.STATE;
            const viewportsToPan = [];
            
            // Check if this viewport is in the selected set
            if (state.selectedViewports && state.selectedViewports.size > 1 && state.selectedViewports.has(this.activeViewport.id)) {
                // Apply pan to all selected viewports
                state.selectedViewports.forEach(vpId => {
                    const vp = document.getElementById(vpId);
                    if (vp) viewportsToPan.push(vp);
                });
            } else {
                // Just pan this viewport
                viewportsToPan.push(this.activeViewport);
            }
            
            // Calculate the delta from start (how much we've moved since starting pan)
            const panDelta = {
                x: newTranslationX - this.panStartTranslation.x,
                y: newTranslationY - this.panStartTranslation.y
            };
            
            viewportsToPan.forEach(vp => {
                try {
                    const viewport = cornerstone.getViewport(vp);
                    if (!viewport) return;
                    
                    // Get the stored start translation for this viewport
                    const startTranslation = this.allPanStartTranslations.get(vp.id);
                    
                    if (startTranslation) {
                        // Apply the same delta to all viewports from their starting positions
                        viewport.translation.x = startTranslation.x + panDelta.x;
                        viewport.translation.y = startTranslation.y + panDelta.y;
                    } else if (vp === this.activeViewport) {
                        // For the active viewport (if not in multi-select), use absolute values
                        viewport.translation.x = newTranslationX;
                        viewport.translation.y = newTranslationY;
                    }
                    
                    cornerstone.setViewport(vp, viewport);
                    cornerstone.updateImage(vp);
                } catch (err) {
                    // Ignore individual viewport errors
                }
            });

        } catch (error) {
            console.warn('Error during pan operation:', error);
        }
    }

    updateWindowLevelUI(windowWidth, windowCenter) {
        const windowSlider = document.getElementById('windowSlider');
        const levelSlider = document.getElementById('levelSlider');
        const windowValue = document.getElementById('windowValue');
        const levelValue = document.getElementById('levelValue');

        if (windowSlider && windowValue) {
            windowSlider.value = Math.round(windowWidth);
            windowValue.textContent = Math.round(windowWidth);
        }

        if (levelSlider && levelValue) {
            levelSlider.value = Math.round(windowCenter);
            levelValue.textContent = Math.round(windowCenter);
        }

        // Update viewport info
        if (window.DICOM_VIEWER.updateViewportInfo) {
            window.DICOM_VIEWER.updateViewportInfo();
        }
    }

    enable() {
        this.isEnabled = true;
        this.disableAllCornerstoneTools();
        console.log('Custom mouse controls enabled');
    }

    disable() {
        this.isEnabled = false;
        this.isRightMouseDown = false;
        this.isMiddleMouseDown = false;
        document.body.style.cursor = 'default';
        console.log('Custom mouse controls disabled');
    }

    // Clean up when manager is destroyed
    destroy() {
        this.removeAllListeners();
        
        // Remove viewport-specific listeners
        document.querySelectorAll('.viewport').forEach(viewport => {
            if (viewport._customWheelHandler) {
                viewport.removeEventListener('wheel', viewport._customWheelHandler);
                delete viewport._customWheelHandler;
            }
        });
    }
};

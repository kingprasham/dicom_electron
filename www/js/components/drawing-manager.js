// Custom Drawing Tool Manager - Robust Hold-to-Draw Implementation
// Hold left mouse button to draw, release to finish and save
// Uses document-level event handlers to ensure reliable mouse tracking

window.DICOM_VIEWER.DrawingManager = class {
    constructor() {
        this.isDrawing = false;
        this.isMouseDown = false; // Track actual mouse button state
        this.currentPath = [];
        this.drawings = new Map(); // Map of imageId -> array of drawings
        this.strokeColor = '#00ff00';
        this.strokeWidth = 2;
        this.activeViewport = null;
        this.canvas = null;
        this.ctx = null;

        // Bind event handlers once for proper cleanup
        this.boundHandlers = {
            mousedown: this.handleGlobalMouseDown.bind(this),
            mousemove: this.handleGlobalMouseMove.bind(this),
            mouseup: this.handleGlobalMouseUp.bind(this),
            contextmenu: this.handleContextMenu.bind(this)
        };
    }

    initialize() {
        console.log('Initializing Custom Drawing Manager (Hold-to-Draw)...');
        this.setupGlobalListeners();
        this.observeNewViewports();
        this.setupViewportCanvases();
    }

    // Setup document-level event listeners for reliable mouse tracking
    setupGlobalListeners() {
        // Remove any existing listeners first
        this.removeGlobalListeners();

        // Add document-level listeners (capture phase for priority)
        document.addEventListener('mousedown', this.boundHandlers.mousedown, true);
        document.addEventListener('mousemove', this.boundHandlers.mousemove, true);
        document.addEventListener('mouseup', this.boundHandlers.mouseup, true);
        document.addEventListener('contextmenu', this.boundHandlers.contextmenu, true);

        // Also listen for mouse leaving the window entirely
        document.addEventListener('mouseleave', this.boundHandlers.mouseup, true);
        window.addEventListener('blur', this.boundHandlers.mouseup, true);

        console.log('Global drawing listeners attached');
    }

    removeGlobalListeners() {
        document.removeEventListener('mousedown', this.boundHandlers.mousedown, true);
        document.removeEventListener('mousemove', this.boundHandlers.mousemove, true);
        document.removeEventListener('mouseup', this.boundHandlers.mouseup, true);
        document.removeEventListener('contextmenu', this.boundHandlers.contextmenu, true);
        document.removeEventListener('mouseleave', this.boundHandlers.mouseup, true);
        window.removeEventListener('blur', this.boundHandlers.mouseup, true);
    }

    setupViewportCanvases() {
        document.querySelectorAll('.viewport').forEach(viewport => {
            this.ensureCanvasExists(viewport);
        });
    }

    observeNewViewports() {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.classList && node.classList.contains('viewport')) {
                            this.ensureCanvasExists(node);
                        }
                        const viewports = node.querySelectorAll && node.querySelectorAll('.viewport');
                        if (viewports) {
                            viewports.forEach(vp => this.ensureCanvasExists(vp));
                        }
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    ensureCanvasExists(viewport) {
        let canvas = viewport.querySelector('.drawing-overlay');
        if (!canvas) {
            canvas = document.createElement('canvas');
            canvas.className = 'drawing-overlay';
            canvas.style.cssText = `
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                pointer-events: none;
                z-index: 100;
            `;
            viewport.style.position = 'relative';
            viewport.appendChild(canvas);
            console.log(`Drawing canvas created for viewport: ${viewport.id || viewport.dataset.viewportName || 'unnamed'}`);
        }
        return canvas;
    }

    isDrawToolActive() {
        const state = window.DICOM_VIEWER.STATE;
        return state && (state.activeTool === 'FreehandRoi' || state.activeTool === 'Draw');
    }

    getViewportFromEvent(e) {
        // Find the viewport element from the event target
        return e.target.closest('.viewport');
    }

    getOrCreateCanvas(viewport) {
        let canvas = viewport.querySelector('.drawing-overlay');
        if (!canvas) {
            canvas = this.ensureCanvasExists(viewport);
        }

        // Match canvas size to viewport (important for proper drawing)
        const rect = viewport.getBoundingClientRect();
        if (canvas.width !== Math.floor(rect.width) || canvas.height !== Math.floor(rect.height)) {
            canvas.width = Math.floor(rect.width);
            canvas.height = Math.floor(rect.height);

            // Redraw existing drawings after resize
            this.redrawCurrentViewport(viewport);
        }

        return canvas;
    }

    // Global mouse down handler
    handleGlobalMouseDown(e) {
        // Only handle left mouse button
        if (e.button !== 0) return;

        // Check if draw tool is active
        if (!this.isDrawToolActive()) return;

        // Check if click is on a viewport
        const viewport = this.getViewportFromEvent(e);
        if (!viewport) return;

        // Prevent default behavior and stop propagation for drawing
        e.preventDefault();
        e.stopPropagation();

        // Mark mouse as down and start drawing
        this.isMouseDown = true;
        this.startDrawing(e, viewport);
    }

    // Global mouse move handler
    handleGlobalMouseMove(e) {
        // Only continue if left mouse is held down and we're drawing
        if (!this.isMouseDown || !this.isDrawing) return;
        if (!this.isDrawToolActive()) return;
        if (!this.activeViewport) return;

        // Prevent default to stop text selection, etc.
        e.preventDefault();

        // Continue drawing
        this.continueDrawing(e);
    }

    // Global mouse up handler - this is the key fix
    handleGlobalMouseUp(e) {
        // Handle any mouseup, blur, or mouseleave event
        // This ensures drawing stops even if mouse is released outside viewport

        // Only handle left mouse button (or any event for blur/leave)
        if (e.type === 'mouseup' && e.button !== 0) return;

        // If we were drawing, end it now
        if (this.isDrawing) {
            this.endDrawing();
        }

        // Always reset mouse state
        this.isMouseDown = false;
    }

    handleContextMenu(e) {
        // Cancel drawing on right-click
        if (this.isDrawing) {
            e.preventDefault();
            this.cancelDrawing();
        }
    }

    startDrawing(e, viewport) {
        this.isDrawing = true;
        this.activeViewport = viewport;
        this.canvas = this.getOrCreateCanvas(viewport);
        this.ctx = this.canvas.getContext('2d');

        const rect = viewport.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        // Initialize path with first point
        this.currentPath = [{ x, y }];

        // Setup canvas context for drawing
        this.ctx.strokeStyle = this.strokeColor;
        this.ctx.lineWidth = this.strokeWidth;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';

        // Begin new path
        this.ctx.beginPath();
        this.ctx.moveTo(x, y);

        // Visual feedback
        if (window.DICOM_VIEWER.showAISuggestion) {
            window.DICOM_VIEWER.showAISuggestion('Drawing... release mouse to finish');
        }

        console.log('Drawing STARTED at:', x.toFixed(0), y.toFixed(0));
    }

    continueDrawing(e) {
        if (!this.ctx || !this.activeViewport) return;

        const rect = this.activeViewport.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        // Add point to path
        this.currentPath.push({ x, y });

        // Draw line segment
        this.ctx.lineTo(x, y);
        this.ctx.stroke();

        // Begin new sub-path for smooth continuation
        this.ctx.beginPath();
        this.ctx.moveTo(x, y);
    }

    endDrawing() {
        if (!this.isDrawing) return;

        console.log('Drawing ENDED, path length:', this.currentPath.length);

        // Save the drawing if it has enough points
        if (this.currentPath.length > 1 && this.activeViewport) {
            this.saveDrawing(this.activeViewport);

            if (window.DICOM_VIEWER.showAISuggestion) {
                window.DICOM_VIEWER.showAISuggestion('Drawing saved');
            }
        } else {
            if (window.DICOM_VIEWER.showAISuggestion) {
                window.DICOM_VIEWER.showAISuggestion('Drawing cancelled (too short)');
            }
        }

        // Reset drawing state
        this.isDrawing = false;
        this.currentPath = [];
        this.activeViewport = null;
        this.canvas = null;
        this.ctx = null;
    }

    cancelDrawing() {
        console.log('Drawing CANCELLED');

        // Clear the current drawing from canvas (redraw only saved drawings)
        if (this.activeViewport && this.canvas) {
            this.redrawCurrentViewport(this.activeViewport);
        }

        if (window.DICOM_VIEWER.showAISuggestion) {
            window.DICOM_VIEWER.showAISuggestion('Drawing cancelled');
        }

        // Reset drawing state
        this.isDrawing = false;
        this.isMouseDown = false;
        this.currentPath = [];
        this.activeViewport = null;
        this.canvas = null;
        this.ctx = null;
    }

    redrawCurrentViewport(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (enabledElement && enabledElement.image) {
                this.redrawForImage(viewport, enabledElement.image.imageId);
            }
        } catch (e) {
            // If no image loaded, just clear the canvas
            const canvas = viewport.querySelector('.drawing-overlay');
            if (canvas) {
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            }
        }
    }

    saveDrawing(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (!enabledElement || !enabledElement.image) {
                console.warn('Cannot save drawing: no image loaded');
                return;
            }

            const imageId = enabledElement.image.imageId;

            if (!this.drawings.has(imageId)) {
                this.drawings.set(imageId, []);
            }

            // Save path in normalized coordinates (0-1 range) for resize resilience
            const canvas = this.canvas;
            const normalizedPath = this.currentPath.map(p => ({
                x: p.x / canvas.width,
                y: p.y / canvas.height
            }));

            this.drawings.get(imageId).push({
                path: normalizedPath,
                color: this.strokeColor,
                width: this.strokeWidth,
                timestamp: Date.now()
            });

            console.log(`Saved drawing for image ${imageId.substring(0, 50)}..., total drawings: ${this.drawings.get(imageId).length}`);

        } catch (error) {
            console.error('Error saving drawing:', error);
        }
    }

    redrawForImage(viewport, imageId) {
        const canvas = this.getOrCreateCanvas(viewport);
        const ctx = canvas.getContext('2d');

        // Clear canvas
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // Redraw all saved drawings for this image
        const drawings = this.drawings.get(imageId);
        if (!drawings || drawings.length === 0) return;

        drawings.forEach(drawing => {
            const points = drawing.path;
            if (points.length < 2) return;

            ctx.beginPath();
            ctx.strokeStyle = drawing.color;
            ctx.lineWidth = drawing.width;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';

            // Convert normalized coordinates back to canvas coordinates
            ctx.moveTo(points[0].x * canvas.width, points[0].y * canvas.height);
            for (let i = 1; i < points.length; i++) {
                ctx.lineTo(points[i].x * canvas.width, points[i].y * canvas.height);
            }
            ctx.stroke();
        });
    }

    clearViewportDrawings(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (enabledElement && enabledElement.image) {
                const imageId = enabledElement.image.imageId;
                this.drawings.delete(imageId);
            }
        } catch (e) {
            // Ignore errors
        }

        // Clear canvas
        const canvas = viewport.querySelector('.drawing-overlay');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        // Reset drawing state if this viewport was active
        if (this.activeViewport === viewport) {
            this.isDrawing = false;
            this.isMouseDown = false;
            this.currentPath = [];
            this.activeViewport = null;
        }

        console.log('Drawings cleared for viewport');
    }

    clearAllDrawings() {
        this.drawings.clear();
        document.querySelectorAll('.drawing-overlay').forEach(canvas => {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        });
        this.isDrawing = false;
        this.isMouseDown = false;
        this.currentPath = [];
        this.activeViewport = null;
        console.log('All drawings cleared');
    }

    // Undo last drawing for current viewport
    undoLastDrawing(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (!enabledElement || !enabledElement.image) return false;

            const imageId = enabledElement.image.imageId;
            const drawings = this.drawings.get(imageId);

            if (drawings && drawings.length > 0) {
                drawings.pop(); // Remove last drawing
                this.redrawForImage(viewport, imageId);
                console.log('Undid last drawing');
                return true;
            }
        } catch (e) {
            console.error('Error undoing drawing:', e);
        }
        return false;
    }

    setColor(color) {
        this.strokeColor = color;
        console.log('Drawing color set to:', color);
    }

    setWidth(width) {
        this.strokeWidth = width;
        console.log('Drawing width set to:', width);
    }

    // Get drawing count for current image
    getDrawingCount(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (enabledElement && enabledElement.image) {
                const drawings = this.drawings.get(enabledElement.image.imageId);
                return drawings ? drawings.length : 0;
            }
        } catch (e) {
            // Ignore
        }
        return 0;
    }

    // Cleanup when manager is destroyed
    destroy() {
        this.removeGlobalListeners();
        this.clearAllDrawings();
        console.log('Drawing manager destroyed');
    }
};

// Initialize after DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (!window.DICOM_VIEWER.MANAGERS) {
        window.DICOM_VIEWER.MANAGERS = {};
    }
    window.DICOM_VIEWER.MANAGERS.drawingManager = new window.DICOM_VIEWER.DrawingManager();

    // Initialize after a short delay to ensure viewports are ready
    setTimeout(() => {
        window.DICOM_VIEWER.MANAGERS.drawingManager.initialize();
    }, 1000);
});
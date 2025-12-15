// Custom Drawing Tool Manager - Click to Start, Click to End
// First left click starts drawing, follows mouse, second left click ends drawing

window.DICOM_VIEWER.DrawingManager = class {
    constructor() {
        this.isDrawing = false;
        this.currentPath = [];
        this.drawings = new Map(); // Map of imageId -> array of drawings
        this.strokeColor = '#00ff00';
        this.strokeWidth = 2;
        this.activeViewport = null;
        this.canvas = null;
        this.ctx = null;
    }

    initialize() {
        console.log('Initializing Custom Drawing Manager (Click-to-Start/Click-to-End)...');
        this.setupViewportListeners();
        this.observeNewViewports();
    }

    setupViewportListeners() {
        document.querySelectorAll('.viewport').forEach(viewport => {
            this.attachDrawingListeners(viewport);
        });
    }

    observeNewViewports() {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.classList && node.classList.contains('viewport')) {
                            this.attachDrawingListeners(node);
                        }
                        const viewports = node.querySelectorAll && node.querySelectorAll('.viewport');
                        if (viewports) {
                            viewports.forEach(vp => this.attachDrawingListeners(vp));
                        }
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    attachDrawingListeners(viewport) {
        // Check if listeners already attached
        if (viewport._drawingListenersAttached) return;
        viewport._drawingListenersAttached = true;

        // Use click for start/end toggle
        viewport.addEventListener('click', (e) => this.onClick(e, viewport));
        viewport.addEventListener('mousemove', (e) => this.onMouseMove(e, viewport));

        // End drawing if mouse leaves viewport
        viewport.addEventListener('mouseleave', (e) => {
            if (this.isDrawing && this.activeViewport === viewport) {
                this.endDrawing();
            }
        });

        // Right-click to cancel
        viewport.addEventListener('contextmenu', (e) => {
            if (this.isDrawing) {
                e.preventDefault();
                this.cancelDrawing();
            }
        });

        console.log(`Drawing listeners attached to viewport: ${viewport.id || 'unnamed'}`);
    }

    isDrawToolActive() {
        const state = window.DICOM_VIEWER.STATE;
        return state.activeTool === 'FreehandRoi' || state.activeTool === 'Draw';
    }

    getOrCreateCanvas(viewport) {
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
        }

        // Match canvas size to viewport
        const rect = viewport.getBoundingClientRect();
        if (canvas.width !== rect.width || canvas.height !== rect.height) {
            canvas.width = rect.width;
            canvas.height = rect.height;
        }

        return canvas;
    }

    onClick(e, viewport) {
        if (!this.isDrawToolActive()) return;
        if (e.button !== 0) return; // Only left mouse button

        e.preventDefault();
        e.stopPropagation();

        if (!this.isDrawing) {
            // First click - START drawing
            this.startDrawing(e, viewport);
        } else if (this.activeViewport === viewport) {
            // Second click on same viewport - END drawing
            this.endDrawing();
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

        this.currentPath = [{ x, y }];

        // Start path
        this.ctx.beginPath();
        this.ctx.strokeStyle = this.strokeColor;
        this.ctx.lineWidth = this.strokeWidth;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.moveTo(x, y);

        // Show visual feedback that drawing has started
        if (window.DICOM_VIEWER.showAISuggestion) {
            window.DICOM_VIEWER.showAISuggestion('Drawing started - click again to finish');
        }

        console.log('Drawing STARTED at:', x, y);
    }

    onMouseMove(e, viewport) {
        if (!this.isDrawing || !this.isDrawToolActive()) return;
        if (viewport !== this.activeViewport) return;

        const rect = viewport.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        this.currentPath.push({ x, y });

        // Draw line
        this.ctx.lineTo(x, y);
        this.ctx.stroke();
        this.ctx.beginPath();
        this.ctx.moveTo(x, y);
    }

    endDrawing() {
        console.log('Drawing ENDED, path length:', this.currentPath.length);

        // Save the drawing for this image
        if (this.currentPath.length > 1) {
            this.saveDrawing(this.activeViewport);
        }

        // Show visual feedback
        if (window.DICOM_VIEWER.showAISuggestion) {
            window.DICOM_VIEWER.showAISuggestion('Drawing completed');
        }

        this.isDrawing = false;
        this.currentPath = [];
        this.activeViewport = null;
    }

    cancelDrawing() {
        console.log('Drawing CANCELLED');

        // Clear the current drawing from canvas (redraw only saved drawings)
        if (this.activeViewport && this.canvas) {
            try {
                const enabledElement = cornerstone.getEnabledElement(this.activeViewport);
                if (enabledElement && enabledElement.image) {
                    this.redrawForImage(this.activeViewport, enabledElement.image.imageId);
                }
            } catch (e) {
                // Clear canvas entirely
                const ctx = this.canvas.getContext('2d');
                ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            }
        }

        if (window.DICOM_VIEWER.showAISuggestion) {
            window.DICOM_VIEWER.showAISuggestion('Drawing cancelled');
        }

        this.isDrawing = false;
        this.currentPath = [];
        this.activeViewport = null;
    }

    saveDrawing(viewport) {
        try {
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (!enabledElement || !enabledElement.image) return;

            const imageId = enabledElement.image.imageId;

            if (!this.drawings.has(imageId)) {
                this.drawings.set(imageId, []);
            }

            // Save path in normalized coordinates (0-1 range)
            const canvas = this.canvas;
            const normalizedPath = this.currentPath.map(p => ({
                x: p.x / canvas.width,
                y: p.y / canvas.height
            }));

            this.drawings.get(imageId).push({
                path: normalizedPath,
                color: this.strokeColor,
                width: this.strokeWidth
            });

            console.log(`Saved drawing for image ${imageId}, total drawings: ${this.drawings.get(imageId).length}`);

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
            ctx.beginPath();
            ctx.strokeStyle = drawing.color;
            ctx.lineWidth = drawing.width;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';

            const points = drawing.path;
            if (points.length < 2) return;

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
        } catch (e) { }

        // Clear canvas
        const canvas = viewport.querySelector('.drawing-overlay');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        // Reset drawing state if this viewport was active
        if (this.activeViewport === viewport) {
            this.isDrawing = false;
            this.currentPath = [];
            this.activeViewport = null;
        }
    }

    clearAllDrawings() {
        this.drawings.clear();
        document.querySelectorAll('.drawing-overlay').forEach(canvas => {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        });
        this.isDrawing = false;
        this.currentPath = [];
        this.activeViewport = null;
        console.log('All drawings cleared');
    }

    setColor(color) {
        this.strokeColor = color;
    }

    setWidth(width) {
        this.strokeWidth = width;
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

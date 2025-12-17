/**
 * Viewer Page Navigator
 * When images exceed current layout capacity, paginate them with page navigation
 * NO scrolling (preserves zoom/pan functionality)
 * Uses Page Up/Down keys and buttons for navigation
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.ViewerPageNavigator = class {
    constructor() {
        this.currentPage = 1;
        this.totalPages = 1;
        this.imagesPerPage = 4; // Default 2x2
        this.maxImagesPerPage = 25; // Maximum 25 images per page (5x5) - supports all layouts
        this.isEnabled = false;
        this.pageNavigatorUI = null;
        this.showImageNumbers = false; // Disabled by default for cleaner viewing

        // STATE PERSISTENCE: Track viewport->image mappings per page
        // Key: page number, Value: Array of image indices (or null for empty slots)
        // This allows manual image placements to persist across page switches
        this.pageViewportStates = new Map();

        // Track if state has been modified from default
        this.stateModified = false;

        this.init();
    }

    init() {
        this.setupKeyboardShortcuts();
        this.observeLayoutChanges();
        console.log('✓ Viewer Page Navigator initialized');
    }

    /**
     * Calculate pages based on current layout and image count
     * Enforces maximum of 16 images per page
     */
    calculatePages() {
        const state = window.DICOM_VIEWER.STATE;
        const totalImages = state.currentSeriesImages?.length || 0;

        if (totalImages === 0) {
            this.totalPages = 1;
            this.currentPage = 1;
            return;
        }

        // Get actual number of viewports by counting DOM elements
        // This is more reliable than parsing CSS grid styles
        const viewportContainer = document.getElementById('viewport-container');
        if (viewportContainer) {
            const viewportCount = viewportContainer.querySelectorAll('.viewport').length;

            if (viewportCount > 0) {
                this.imagesPerPage = viewportCount;
            } else {
                // Fallback: try to parse CSS grid if no viewports yet
                const containerStyles = window.getComputedStyle(viewportContainer);
                const colParts = containerStyles.gridTemplateColumns.split(' ').filter(s => s.trim() && s !== 'none');
                const rowParts = containerStyles.gridTemplateRows.split(' ').filter(s => s.trim() && s !== 'none');

                const gridCols = colParts.length || 2;
                const gridRows = rowParts.length || 2;
                this.imagesPerPage = gridRows * gridCols;
            }
        }

        // Enforce maximum of 16 images per page
        if (this.imagesPerPage > this.maxImagesPerPage) {
            this.imagesPerPage = this.maxImagesPerPage;
            console.log(`Capped images per page to ${this.maxImagesPerPage}`);
        }

        // Ensure at least 1 image per page
        if (this.imagesPerPage < 1) {
            this.imagesPerPage = 1;
        }

        this.totalPages = Math.ceil(totalImages / this.imagesPerPage);

        // Clamp current page
        if (this.currentPage > this.totalPages) {
            this.currentPage = this.totalPages;
        }
        if (this.currentPage < 1) {
            this.currentPage = 1;
        }

        // Enable/disable based on whether we need pagination
        const needsPagination = this.totalPages > 1;

        if (needsPagination && !this.isEnabled) {
            this.enable();
        } else if (!needsPagination && this.isEnabled) {
            this.disable();
        }

        // Update UI if enabled
        if (this.isEnabled) {
            this.updatePageIndicator();
        }

        console.log(`Page calculation: ${totalImages} images, ${this.imagesPerPage} per page (max ${this.maxImagesPerPage}) = ${this.totalPages} pages`);
    }

    /**
     * Observe layout changes and recalculate pages when layout changes
     * Auto-adjusts images when switching layouts (e.g., 4x4 to 3x3)
     */
    observeLayoutChanges() {
        // Listen for layout change events
        document.addEventListener('layoutChanged', (e) => {
            console.log('Layout changed event detected', e.detail);

            // Delay to let viewports be created first (300ms for reliability)
            setTimeout(() => {
                // CLEAR saved states when layout changes - viewport slots change meaning
                this.clearSavedStates();

                // Recalculate pages based on new layout
                this.calculatePages();

                // If we have images, reload them for the current page
                const state = window.DICOM_VIEWER.STATE;
                if (state.currentSeriesImages && state.currentSeriesImages.length > 0) {
                    // Reset to page 1 when layout changes
                    this.currentPage = 1;
                    this.loadCurrentPageImages();

                    // Update page indicator
                    this.updatePageIndicator();
                }

                console.log(`Layout change complete: ${this.totalPages} pages, ${this.imagesPerPage} images per page`);
            }, 300);
        });

        // Also observe viewport container for child changes (fallback)
        const viewportContainer = document.getElementById('viewport-container');
        if (viewportContainer) {
            const observer = new MutationObserver((mutations) => {
                // Only react if viewports were added or removed
                const viewportsChanged = mutations.some(m =>
                    m.addedNodes.length > 0 || m.removedNodes.length > 0
                );

                if (viewportsChanged) {
                    // Debounce to avoid multiple triggers
                    clearTimeout(this._layoutChangeTimeout);
                    this._layoutChangeTimeout = setTimeout(() => {
                        console.log('Viewport container structure changed');
                        this.calculatePages();
                    }, 200);
                }
            });

            observer.observe(viewportContainer, { childList: true });
        }

        console.log('Layout change observation enabled');
    }

    /**
     * Enable page navigation UI
     */
    enable() {
        if (this.isEnabled) return;
        this.isEnabled = true;
        this.createPageNavigatorUI();
        console.log('Page navigation enabled');
    }

    /**
     * Disable page navigation UI
     */
    disable() {
        if (!this.isEnabled) return;
        this.isEnabled = false;
        this.removePageNavigatorUI();
        console.log('Page navigation disabled');
    }

    /**
     * Create the page navigator UI
     */
    createPageNavigatorUI() {
        // Remove existing if any
        this.removePageNavigatorUI();

        const html = `
            <div id="viewerPageNavigator" class="viewer-page-navigator">
                <button class="page-nav-btn" id="prevPageBtn" title="Previous Page (Page Up)">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <div class="page-indicator" id="pageIndicator">
                    Page <span id="currentPageNum">1</span> of <span id="totalPagesNum">1</span>
                </div>
                <button class="page-nav-btn" id="nextPageBtn" title="Next Page (Page Down)">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>

            <style id="pageNavigatorStyles">
                .viewer-page-navigator {
                    position: fixed;
                    bottom: 15px;
                    left: 50%;
                    transform: translateX(-50%);
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    background: linear-gradient(135deg, rgba(26, 26, 46, 0.95) 0%, rgba(22, 33, 62, 0.95) 100%);
                    padding: 10px 20px;
                    border-radius: 30px;
                    border: 1px solid rgba(255, 255, 255, 0.25);
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
                    z-index: 1000;
                    backdrop-filter: blur(12px);
                    opacity: 0.9;
                    transition: opacity 0.3s ease, transform 0.2s ease;
                }

                .viewer-page-navigator:hover {
                    opacity: 1;
                    transform: translateX(-50%) scale(1.02);
                }

                .page-nav-btn {
                    width: 32px;
                    height: 32px;
                    border-radius: 50%;
                    border: 2px solid rgba(255, 255, 255, 0.2);
                    background: rgba(255, 255, 255, 0.1);
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    font-size: 14px;
                }

                .page-nav-btn:hover:not(:disabled) {
                    background: rgba(13, 110, 253, 0.5);
                    border-color: #0d6efd;
                    transform: scale(1.1);
                }

                .page-nav-btn:disabled {
                    opacity: 0.3;
                    cursor: not-allowed;
                }

                .page-indicator {
                    color: #fff;
                    font-size: 12px;
                    font-weight: 500;
                    min-width: 80px;
                    text-align: center;
                }

                .page-indicator span {
                    color: #ffc107;
                    font-weight: 700;
                }

                /* Hide page navigator during print */
                @media print {
                    .viewer-page-navigator {
                        display: none !important;
                    }
                }
            </style>
        `;

        document.body.insertAdjacentHTML('beforeend', html);
        this.pageNavigatorUI = document.getElementById('viewerPageNavigator');

        // Setup button listeners
        document.getElementById('prevPageBtn')?.addEventListener('click', () => this.previousPage());
        document.getElementById('nextPageBtn')?.addEventListener('click', () => this.nextPage());

        this.updatePageIndicator();
    }

    /**
     * Remove page navigator UI
     */
    removePageNavigatorUI() {
        const nav = document.getElementById('viewerPageNavigator');
        const styles = document.getElementById('pageNavigatorStyles');
        if (nav) nav.remove();
        if (styles) styles.remove();
        this.pageNavigatorUI = null;
    }

    /**
     * Update page indicator display
     */
    updatePageIndicator() {
        const currentNum = document.getElementById('currentPageNum');
        const totalNum = document.getElementById('totalPagesNum');
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');

        if (currentNum) currentNum.textContent = this.currentPage;
        if (totalNum) totalNum.textContent = this.totalPages;

        if (prevBtn) prevBtn.disabled = this.currentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.currentPage >= this.totalPages;
    }

    /**
     * Go to specific page
     * STATE PERSISTENCE: Saves current viewport state before switching pages
     */
    async goToPage(pageNum) {
        if (pageNum < 1 || pageNum > this.totalPages) return;
        if (pageNum === this.currentPage) return;

        // SAVE current viewport state before switching
        this.saveCurrentPageState();

        const previousPage = this.currentPage;
        this.currentPage = pageNum;

        console.log(`[PageNavigator] Switching from page ${previousPage} to page ${pageNum}`);

        await this.loadCurrentPageImages();
        this.updatePageIndicator();
    }

    /**
     * STATE PERSISTENCE: Save the current viewport configuration for the current page
     * Captures which image (by global index) is in each viewport slot
     */
    saveCurrentPageState() {
        const viewports = document.querySelectorAll('.viewport');
        const state = window.DICOM_VIEWER.STATE;
        const images = state.currentSeriesImages || [];

        if (viewports.length === 0 || images.length === 0) return;

        const pageState = [];

        viewports.forEach((viewport, slotIndex) => {
            try {
                const enabledElement = cornerstone.getEnabledElement(viewport);
                if (enabledElement && enabledElement.image) {
                    const imageId = enabledElement.image.imageId;

                    // Find which image index this corresponds to
                    const imageIndex = this.findImageIndexByImageId(imageId, images);
                    pageState.push(imageIndex); // Will be -1 if not found, which means keep as-is

                    console.log(`[SaveState] Viewport ${slotIndex + 1}: Image index ${imageIndex}`);
                } else {
                    // Empty viewport
                    pageState.push(null);
                    console.log(`[SaveState] Viewport ${slotIndex + 1}: Empty`);
                }
            } catch (e) {
                // Viewport not enabled
                pageState.push(null);
            }
        });

        this.pageViewportStates.set(this.currentPage, pageState);
        console.log(`[SaveState] Saved state for page ${this.currentPage}:`, pageState);
    }

    /**
     * Find the global image index from an imageId
     */
    findImageIndexByImageId(imageId, images) {
        for (let i = 0; i < images.length; i++) {
            const img = images[i];

            // Check various ID formats
            if (img.imageId && imageId.includes(img.imageId)) return i;
            if (img.image_id && imageId.includes(img.image_id)) return i;
            if (img.orthancInstanceId && imageId.includes(img.orthancInstanceId)) return i;
            if (img.instanceId && imageId.includes(img.instanceId)) return i;
            if (img.id && imageId.includes(img.id)) return i;
        }
        return -1;
    }

    /**
     * STATE PERSISTENCE: Get the saved state for a page, or return default order
     * Returns array of image indices for each viewport slot
     */
    getPageState(pageNum) {
        if (this.pageViewportStates.has(pageNum)) {
            console.log(`[GetState] Found saved state for page ${pageNum}`);
            return this.pageViewportStates.get(pageNum);
        }
        return null; // No saved state, use default
    }

    /**
     * STATE PERSISTENCE: Record a manual image placement
     * Called when user drops an image into a viewport
     */
    recordManualPlacement(viewportSlotIndex, imageIndex) {
        // Get or create state for current page
        let pageState = this.pageViewportStates.get(this.currentPage);
        if (!pageState) {
            // Initialize with current default
            pageState = this.getDefaultPageState();
        }

        // Update the specific slot
        pageState[viewportSlotIndex] = imageIndex;
        this.pageViewportStates.set(this.currentPage, pageState);
        this.stateModified = true;

        console.log(`[ManualPlacement] Viewport ${viewportSlotIndex + 1} now has image ${imageIndex + 1}`);
        console.log(`[ManualPlacement] Page ${this.currentPage} state:`, pageState);
    }

    /**
     * Get the default viewport state for the current page (based on sequential order)
     */
    getDefaultPageState() {
        const viewports = document.querySelectorAll('.viewport');
        const state = window.DICOM_VIEWER.STATE;
        const images = state.currentSeriesImages || [];

        const startIndex = (this.currentPage - 1) * this.imagesPerPage;
        const pageState = [];

        for (let i = 0; i < viewports.length; i++) {
            const globalIndex = startIndex + i;
            if (globalIndex < images.length) {
                pageState.push(globalIndex);
            } else {
                pageState.push(null);
            }
        }

        return pageState;
    }

    /**
     * STATE PERSISTENCE: Clear all saved viewport states
     * Called when layout changes or a new study is loaded
     */
    clearSavedStates() {
        this.pageViewportStates.clear();
        this.stateModified = false;
        console.log('[PageNavigator] Cleared all saved viewport states');
    }

    /**
     * Go to previous page
     */
    async previousPage() {
        await this.goToPage(this.currentPage - 1);
    }

    /**
     * Go to next page
     */
    async nextPage() {
        await this.goToPage(this.currentPage + 1);
    }

    /**
     * Load images for current page into viewports
     * STATE PERSISTENCE: Uses saved state if available, otherwise uses default order
     */
    async loadCurrentPageImages() {
        const state = window.DICOM_VIEWER.STATE;
        const images = state.currentSeriesImages || [];
        const viewports = document.querySelectorAll('.viewport');

        if (images.length === 0 || viewports.length === 0) return;

        // Check for saved state for this page
        const savedState = this.getPageState(this.currentPage);

        // Calculate default indices
        const startIndex = (this.currentPage - 1) * this.imagesPerPage;
        const endIndex = Math.min(startIndex + this.imagesPerPage, images.length);

        if (savedState) {
            console.log(`[LoadPage] Using SAVED state for page ${this.currentPage}:`, savedState);
        } else {
            console.log(`[LoadPage] Using DEFAULT order for page ${this.currentPage}: images ${startIndex + 1} to ${endIndex}`);
        }

        // Load images into viewports
        for (let i = 0; i < viewports.length; i++) {
            const viewport = viewports[i];

            // Determine which image to load in this slot
            let imageIndex;
            let image;
            let globalImageNumber;

            if (savedState && savedState[i] !== undefined) {
                // Use saved state
                imageIndex = savedState[i];
                if (imageIndex !== null && imageIndex >= 0 && imageIndex < images.length) {
                    image = images[imageIndex];
                    globalImageNumber = imageIndex + 1;
                } else {
                    image = null;
                    globalImageNumber = null;
                }
            } else {
                // Use default sequential order
                imageIndex = startIndex + i;
                if (imageIndex < images.length) {
                    image = images[imageIndex];
                    globalImageNumber = imageIndex + 1;
                } else {
                    image = null;
                    globalImageNumber = null;
                }
            }

            // Update viewport label
            this.updateViewportLabel(viewport, globalImageNumber, i + 1);

            if (image) {
                try {
                    // Remove empty viewport indicator if present
                    const emptyIndicator = viewport.querySelector('.empty-viewport-indicator');
                    if (emptyIndicator) emptyIndicator.remove();

                    // Clear isEmpty flag
                    viewport.dataset.isEmpty = 'false';

                    // Construct imageId
                    let imageId = image.imageId || image.image_id;

                    if (!imageId && image.orthancInstanceId) {
                        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
                        imageId = `wadouri:${basePath}/api/get_dicom_from_orthanc.php?instanceId=${image.orthancInstanceId}`;
                    }

                    if (!imageId && image.instanceId) {
                        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
                        imageId = `wadouri:${basePath}/api/get_dicom_from_orthanc.php?instanceId=${image.instanceId}`;
                    }

                    if (imageId) {
                        const loadedImage = await cornerstone.loadImage(imageId);
                        await cornerstone.displayImage(viewport, loadedImage);

                        // Add image number overlay
                        this.addImageNumberOverlay(viewport, globalImageNumber);

                        // Restore text annotations for this image
                        if (window.DICOM_VIEWER.MANAGERS?.textAnnotationTool) {
                            window.DICOM_VIEWER.MANAGERS.textAnnotationTool.restoreAnnotationsForViewport(viewport);
                        }

                        console.log(`[LoadPage] Viewport ${i + 1}: Loaded image ${globalImageNumber}`);
                    }
                } catch (err) {
                    console.error(`Error loading image ${imageIndex}:`, err);
                    this.showErrorInViewport(viewport, globalImageNumber || i + 1);
                }
            } else {
                // Clear viewport if no image for this slot
                console.log(`[LoadPage] Viewport ${i + 1}: Empty`);
                this.clearViewport(viewport);
            }
        }

        // CRITICAL: After loading/clearing viewports, ensure all drop handlers are set up
        setTimeout(() => {
            const eventHandlers = window.DICOM_VIEWER?.EventHandlers;
            if (eventHandlers) {
                viewports.forEach(viewport => {
                    if (!viewport.dataset.dropConfigured) {
                        eventHandlers.setupSingleViewport(viewport);
                    }
                });
            }
        }, 100);

        // Update image counter in sidebar
        const imageCounter = document.getElementById('imageCounter');
        if (imageCounter) {
            if (savedState) {
                imageCounter.textContent = `Page ${this.currentPage}/${this.totalPages} (Custom arrangement)`;
            } else {
                imageCounter.textContent = `Page ${this.currentPage}/${this.totalPages} (${startIndex + 1}-${endIndex} of ${images.length})`;
            }
        }
    }

    /**
     * Add image number overlay to viewport
     * Disabled by default for cleaner viewing - numbers shown in viewport label instead
     */
    addImageNumberOverlay(viewport, imageNumber) {
        // Remove existing overlay if any
        const existing = viewport.querySelector('.image-page-number');
        if (existing) existing.remove();

        // Image numbers are now disabled by default for cleaner viewing
        // The image number is already shown in the viewport label
        // To re-enable, set this.showImageNumbers = true in constructor

        if (this.showImageNumbers) {
            const overlay = document.createElement('div');
            overlay.className = 'image-page-number';
            overlay.style.cssText = `
                position: absolute;
                top: 8px;
                left: 8px;
                background: rgba(0, 0, 0, 0.7);
                color: #00ff00;
                font-size: 12px;
                padding: 3px 8px;
                border-radius: 4px;
                font-family: 'Consolas', monospace;
                z-index: 10;
                pointer-events: none;
            `;
            overlay.textContent = `#${imageNumber}`;
            viewport.appendChild(overlay);
        }
    }

    /**
     * Update viewport label to show global image number
     * @param {HTMLElement} viewport - The viewport element
     * @param {number|null} globalImageNumber - Global image number (null if empty)
     * @param {number} slotNumber - Viewport slot number (1-based)
     */
    updateViewportLabel(viewport, globalImageNumber, slotNumber) {
        // Find or create the viewport-number-overlay
        let numberOverlay = viewport.querySelector('.viewport-number-overlay');

        if (!numberOverlay) {
            // Create the overlay if it doesn't exist (for viewports from viewport-manager)
            numberOverlay = document.createElement('div');
            numberOverlay.className = 'viewport-number-overlay';
            numberOverlay.style.cssText = `
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
            viewport.appendChild(numberOverlay);
        }

        if (globalImageNumber !== null) {
            numberOverlay.textContent = globalImageNumber;
            numberOverlay.title = `Image ${globalImageNumber} (Slot ${slotNumber})`;
            numberOverlay.style.color = '#0d6efd'; // Blue for images
        } else {
            numberOverlay.textContent = `${slotNumber} (Empty)`;
            numberOverlay.title = `Empty viewport (Slot ${slotNumber})`;
            numberOverlay.style.color = '#6c757d'; // Gray for empty
        }

        // Also update the viewport-overlay (shows view type like Original/Axial/etc)
        const viewportOverlay = viewport.querySelector('.viewport-overlay');
        if (viewportOverlay) {
            if (globalImageNumber !== null) {
                viewportOverlay.textContent = `Viewport ${globalImageNumber}`;
            } else {
                viewportOverlay.textContent = `Viewport ${slotNumber}`;
            }
        }
    }

    /**
     * Show error in viewport
     */
    showErrorInViewport(viewport, imageNumber) {
        const existing = viewport.querySelector('.image-page-number');
        if (existing) existing.remove();

        viewport.innerHTML = `
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #666;">
                <i class="bi bi-exclamation-triangle text-warning fs-2"></i>
                <small>Image #${imageNumber} failed</small>
            </div>
        `;
    }

    /**
     * Clear viewport - properly reset to empty state with visible empty indicator
     * FIXED: Ensures viewport remains enabled and can receive drag-drop events
     */
    clearViewport(viewport) {
        // Remove any overlays
        const existingOverlay = viewport.querySelector('.image-page-number');
        if (existingOverlay) existingOverlay.remove();

        // Remove any previous empty indicator
        const prevEmpty = viewport.querySelector('.empty-viewport-indicator');
        if (prevEmpty) prevEmpty.remove();

        // IMPORTANT: Keep track if we need to re-enable the viewport
        let needsReEnable = false;

        try {
            // Check if viewport is enabled
            const enabledElement = cornerstone.getEnabledElement(viewport);
            if (enabledElement) {
                // Clear the image reference completely
                if (enabledElement.image) {
                    enabledElement.image = null;
                }

                // Clear all canvases
                const canvases = viewport.querySelectorAll('canvas');
                canvases.forEach(canvas => {
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#1a1a1a';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                });

                // Force cornerstone to update (will show blank)
                try {
                    cornerstone.updateImage(viewport);
                } catch (updateErr) {
                    // Ignore update errors
                }
            }
        } catch (e) {
            // Viewport not enabled by cornerstone - need to enable it
            needsReEnable = true;

            // Clear any existing canvas
            const canvases = viewport.querySelectorAll('canvas');
            canvases.forEach(canvas => {
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#1a1a1a';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            });
        }

        // CRITICAL FIX: Ensure viewport is enabled for Cornerstone so drops work
        // Empty viewports must be enabled to receive images via drag-drop
        if (needsReEnable) {
            try {
                cornerstone.enable(viewport);
                console.log('Re-enabled viewport for drag-drop:', viewport.dataset.viewportName || viewport.id);
            } catch (enableErr) {
                console.warn('Could not re-enable viewport:', enableErr);
            }
        }

        // CRITICAL FIX: Ensure drop handlers are configured on this viewport
        // This is needed because the viewport may have been recreated or modified
        if (viewport.dataset.dropConfigured !== 'true') {
            if (window.DICOM_VIEWER && window.DICOM_VIEWER.EventHandlers) {
                window.DICOM_VIEWER.EventHandlers.setupSingleViewport(viewport);
                console.log('Re-configured drop handlers for empty viewport');
            }
        }

        // Add visible empty viewport indicator
        // FIXED: All elements have pointer-events: none to allow drops to pass through
        const emptyIndicator = document.createElement('div');
        emptyIndicator.className = 'empty-viewport-indicator';
        emptyIndicator.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            z-index: 5;
        `;
        emptyIndicator.innerHTML = `
            <div style="
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background: rgba(26, 26, 26, 0.9);
                color: #666;
                pointer-events: none;
            ">
                <i class="bi bi-plus-circle" style="font-size: 32px; opacity: 0.6; color: #0d6efd;"></i>
                <span style="font-size: 12px; margin-top: 8px; color: #888;">Drop image here</span>
                <span style="font-size: 10px; margin-top: 2px; color: #555;">or drag from sidebar</span>
            </div>
        `;
        viewport.appendChild(emptyIndicator);

        // Mark viewport as empty for styling purposes
        viewport.dataset.isEmpty = 'true';
    }

    /**
     * Setup keyboard shortcuts for page navigation
     */
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            if (!this.isEnabled) return;

            // Ignore if typing in input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            if (e.key === 'PageUp') {
                e.preventDefault();
                this.previousPage();
            } else if (e.key === 'PageDown') {
                e.preventDefault();
                this.nextPage();
            }
        });
    }

    /**
     * Observe layout changes and recalculate pages
     */
    observeLayoutChanges() {
        // Watch for layout button clicks
        const layoutButtons = document.querySelectorAll('[data-layout]');
        layoutButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                // Wait for layout to be applied
                setTimeout(() => {
                    this.calculatePages();
                    if (this.isEnabled) {
                        this.currentPage = 1;
                        this.loadCurrentPageImages();
                    }
                }, 300);
            });
        });

        // Also trigger on image load events
        document.addEventListener('dicomImagesLoaded', () => {
            this.calculatePages();
            if (this.isEnabled) {
                this.currentPage = 1;
                this.loadCurrentPageImages();
            }
        });
    }

    /**
     * Manually trigger page calculation and loading
     * Call this after images are loaded or layout changes
     */
    refresh() {
        console.log('PageNavigator.refresh() called');

        // Recalculate pages based on current viewports
        this.calculatePages();

        // Always try to load images if we have them
        const state = window.DICOM_VIEWER.STATE;
        if (state.currentSeriesImages && state.currentSeriesImages.length > 0) {
            // Enable pagination if needed
            if (this.totalPages > 1 && !this.isEnabled) {
                this.enable();
            }

            // Load images for current page
            this.loadCurrentPageImages();

            // Update the page indicator
            this.updatePageIndicator();
        }

        console.log(`PageNavigator refreshed: ${this.totalPages} pages, ${this.imagesPerPage} per page, current page ${this.currentPage}`);
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    if (!window.DICOM_VIEWER.MANAGERS) {
        window.DICOM_VIEWER.MANAGERS = {};
    }
    window.DICOM_VIEWER.MANAGERS.pageNavigator = new window.DICOM_VIEWER.ViewerPageNavigator();
});

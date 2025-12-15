/**
 * Viewport Actions Manager
 * Handles Insert All, Clear All, and Drag-Drop between viewports
 */

window.DICOM_VIEWER.ViewportActionsManager = class {
    constructor() {
        this.initialized = false;
        this.draggedViewportId = null;
        this.draggedImageData = null;
        this.isSyncing = false; // Flag to prevent sync feedback loops
    }

    initialize() {
        if (this.initialized) return;

        console.log('Initializing Viewport Actions Manager');
        this.createActionButtons();
        this.setupDragAndDrop();
        this.setupViewportSync();
        this.setupKeyboardShortcuts(); // Add Ctrl+A handler
        this.setupViewportClickHandlers(); // Add Ctrl+Click handler
        this.initialized = true;
        console.log('Viewport Actions Manager initialized successfully');
    }

    /**
     * Setup Ctrl+A keyboard shortcut for select all
     */
    setupKeyboardShortcuts() {
        const self = this;
        document.addEventListener('keydown', function (e) {
            // Ctrl+A or Cmd+A for Select All
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
                // Don't capture if typing in input
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                console.log('Ctrl+A pressed - toggling select all');
                self.toggleSelectAll();
            }
        }, true); // Use capture phase to get event first

        console.log('Viewport selection keyboard shortcuts initialized (Ctrl+A)');
    }

    /**
     * Setup Ctrl+Click handler for viewport multi-selection using event delegation
     */
    setupViewportClickHandlers() {
        const self = this;

        // Use event delegation on the viewport container
        document.addEventListener('click', function (e) {
            // Check for Ctrl or Cmd key
            if (!e.ctrlKey && !e.metaKey) return;

            // Find if clicked element is inside a viewport
            const viewport = e.target.closest('.viewport');
            if (!viewport) return;

            // Prevent default behavior
            e.preventDefault();
            e.stopPropagation();

            console.log('Ctrl+Click on viewport:', viewport.id);
            self.toggleViewportSelection(viewport);
        }, true); // Use capture phase

        console.log('Viewport Ctrl+Click selection initialized');
    }

    /**
     * Attach event listeners to existing action buttons in index.php
     */
    createActionButtons() {
        // Get existing buttons from index.php
        const insertAllBtn = document.getElementById('insertAllBtn');
        const clearAllBtn = document.getElementById('clearAllBtn');
        const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
        const selectAllBtn = document.getElementById('selectAllBtn');

        // Setup event listeners for existing buttons
        if (insertAllBtn) {
            insertAllBtn.addEventListener('click', () => this.insertAllImages());
            console.log('Insert All button handler attached');
        }

        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', () => this.clearAllViewports());
            console.log('Clear All button handler attached');
        }

        if (deleteSelectedBtn) {
            deleteSelectedBtn.addEventListener('click', () => this.deleteSelectedImage());
            console.log('Delete Selected button handler attached');
        }

        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => this.toggleSelectAll());
            console.log('Select All button handler attached');
        }

        // New Ordered Selection Buttons
        const selectModeBtn = document.getElementById('selectModeBtn');
        const arrangeBtn = document.getElementById('arrangeBtn');

        if (selectModeBtn) {
            selectModeBtn.addEventListener('click', () => this.toggleSelectionMode());
        }
        if (arrangeBtn) {
            arrangeBtn.addEventListener('click', () => this.arrangeOrderedImages());
        }

        console.log('Action button handlers initialized');
    }

    /**
     * Toggle selection mode for ordered arrangement
     */
    toggleSelectionMode() {
        const state = window.DICOM_VIEWER.STATE;
        state.isSelectionMode = !state.isSelectionMode;

        const btn = document.getElementById('selectModeBtn');
        const arrangeBtn = document.getElementById('arrangeBtn');
        const list = document.getElementById('series-list');

        if (state.isSelectionMode) {
            btn.classList.add('btn-primary');
            btn.classList.remove('btn-outline-primary');
            if (arrangeBtn) arrangeBtn.style.display = 'inline-block';
            if (list) list.classList.add('selection-mode');
            window.DICOM_VIEWER.showAISuggestion('Select images in desired order, then click Arrange');
        } else {
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-outline-primary');
            if (arrangeBtn) arrangeBtn.style.display = 'none';
            if (list) list.classList.remove('selection-mode');
            this.clearOrderedSelection();
        }
    }

    /**
     * Toggle individual image selection in ordered list
     */
    toggleOrderedSelection(file) {
        console.log('toggleOrderedSelection called for file:', file.id);
        const state = window.DICOM_VIEWER.STATE;

        // Find if already selected
        const index = state.orderedImageSelection.findIndex(f => f.id === file.id);

        if (index === -1) {
            state.orderedImageSelection.push(file);
        } else {
            state.orderedImageSelection.splice(index, 1);
        }

        this.updateSelectionBadges();

        // Update Arrange button text
        const arrangeBtn = document.getElementById('arrangeBtn');
        if (arrangeBtn) {
            arrangeBtn.innerHTML = `<i class="bi bi-sort-numeric-down"></i> Arrange (${state.orderedImageSelection.length})`;
            arrangeBtn.disabled = state.orderedImageSelection.length === 0;
            // Force display update if needed
            arrangeBtn.style.display = 'inline-block';
        }
    }

    clearOrderedSelection() {
        window.DICOM_VIEWER.STATE.orderedImageSelection = [];
        this.updateSelectionBadges();
        const arrangeBtn = document.getElementById('arrangeBtn');
        if (arrangeBtn) {
            arrangeBtn.innerHTML = `<i class="bi bi-sort-numeric-down"></i> Arrange`;
            arrangeBtn.disabled = true;
        }
    }

    updateSelectionBadges() {
        console.log('Updating selection badges (Robust Mode)...');

        // Clear existing badges
        document.querySelectorAll('.selection-badge').forEach(el => el.remove());
        document.querySelectorAll('.series-item').forEach(el => el.classList.remove('selected-order'));

        const state = window.DICOM_VIEWER.STATE;

        // Create a map for fast lookup
        const selectedMap = new Map();
        state.orderedImageSelection.forEach((file, index) => {
            selectedMap.set(String(file.id), index + 1);
        });

        // Iterate ALL items to find matches (avoids selector issues)
        const allItems = document.querySelectorAll('.series-item');
        console.log(`Scanning ${allItems.length} series items...`);

        allItems.forEach(item => {
            const fileId = item.dataset.fileId;
            if (selectedMap.has(fileId)) {
                const order = selectedMap.get(fileId);
                console.log(`Match found for file ${fileId} -> Order ${order}`);

                item.classList.add('selected-order');

                // Find thumbnail container
                const thumbnailDiv = item.querySelector('.series-thumbnail');

                if (thumbnailDiv && thumbnailDiv.parentElement) {
                    // TARGET THE PARENT WRAPPER (Stable), not the thumbnail div (which gets wiped)
                    const wrapper = thumbnailDiv.parentElement;

                    // Create badge
                    const badge = document.createElement('div');
                    badge.className = 'selection-badge';
                    badge.textContent = order;

                    // Style adjustments to ensure visibility
                    wrapper.style.position = 'relative'; // Ensure relative positioning on wrapper
                    // badge styles are in CSS, but ensure z-index here too just in case
                    badge.style.zIndex = '100';

                    wrapper.appendChild(badge);
                    console.log('Badge appended to wrapper');
                } else {
                    console.warn('Thumbnail wrapper not found');
                }
            }
        });
    }

    /**
     * Arrange selected images in order
     */
    async arrangeOrderedImages() {
        const state = window.DICOM_VIEWER.STATE;
        const images = state.orderedImageSelection;

        if (images.length === 0) {
            window.DICOM_VIEWER.showAISuggestion('No images selected to arrange');
            return;
        }

        console.log(`Arranging ${images.length} images in order`);

        // Use Custom Grid Manager to get optimal layout
        const customGridManager = window.DICOM_VIEWER.MANAGERS.customGridManager;
        if (customGridManager) {
            const { rows, cols } = customGridManager.calculateOptimalGrid(images.length);
            customGridManager.applyCustomGrid(rows, cols);
        }

        // Wait for layout update
        setTimeout(async () => {
            const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
            const viewports = viewportManager.getAllViewports();

            // Clear persistent tracking for this new set
            state.viewportImages = [];

            const count = Math.min(images.length, viewports.length);
            window.DICOM_VIEWER.showAISuggestion(`Arranging ${count} images...`);

            for (let i = 0; i < count; i++) {
                const viewport = viewports[i];
                const image = images[i];
                const url = await this.loadImageToViewport(viewport, image, i);

                if (url) state.viewportImages[i] = url;
            }

            this.fitAllImagesToViewports();
            window.DICOM_VIEWER.showAISuggestion('Images arranged successfully');

            // Auto-exit selection mode to clear UI (badges/borders)
            this.toggleSelectionMode();

        }, 300);
    }

    /**
     * Insert all images into viewports automatically
     * Respects the current layout selection and uses page navigator for overflow
     */
    async insertAllImages() {
        console.log('Insert All triggered');

        // Get current series images from STATE (the correct source)
        const state = window.DICOM_VIEWER.STATE;
        const images = state.currentSeriesImages;

        if (!images || images.length === 0) {
            window.DICOM_VIEWER.showAISuggestion('No images loaded. Please select a study first.');
            return;
        }

        const imageCount = images.length;
        console.log(`Inserting ${imageCount} images`);

        // Get viewport manager - use CURRENT layout (don't override user's selection)
        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
        if (!viewportManager) {
            window.DICOM_VIEWER.showAISuggestion('Viewport manager not ready. Please wait...');
            return;
        }

        // Get available viewports in current layout
        const viewports = viewportManager.getAllViewports();
        const viewportCount = viewports.length;
        console.log(`Available viewports in current layout: ${viewportCount}`);

        if (viewportCount === 0) {
            window.DICOM_VIEWER.showAISuggestion('No viewports available.');
            return;
        }

        // Clear previous viewport images tracking
        state.viewportImages = [];

        // Load only the first page of images (up to viewport count)
        const imagesToLoad = Math.min(imageCount, viewportCount);
        window.DICOM_VIEWER.showAISuggestion(`Loading ${imagesToLoad} of ${imageCount} images...`);

        for (let i = 0; i < imagesToLoad; i++) {
            const viewport = viewports[i];
            const image = images[i];
            const imageUrl = await this.loadImageToViewport(viewport, image, i);

            // Track the imageId in state
            if (imageUrl) {
                state.viewportImages[i] = imageUrl;
                console.log(`Tracked image at index ${i}: ${imageUrl}`);
            }
        }

        // Fit images to viewports after loading
        setTimeout(() => {
            this.fitAllImagesToViewports();

            // Trigger page navigator refresh to enable pagination for remaining images
            if (window.DICOM_VIEWER.MANAGERS.pageNavigator) {
                window.DICOM_VIEWER.MANAGERS.pageNavigator.refresh();
            }

            // Show appropriate message based on whether pagination is needed
            if (imageCount > viewportCount) {
                const totalPages = Math.ceil(imageCount / viewportCount);
                window.DICOM_VIEWER.showAISuggestion(
                    `Page 1 of ${totalPages} (${imagesToLoad} images). Use Page Navigator or PageUp/PageDown for all ${imageCount} images.`
                );
            } else {
                window.DICOM_VIEWER.showAISuggestion(`Loaded all ${imagesToLoad} images into viewports`);
            }
        }, 300);

        console.log('Insert All completed. Tracked images:', state.viewportImages.length);
    }

    /**
     * Load image to specific viewport
     * @param {HTMLElement} viewport - The viewport element
     * @param {Object} image - The image object
     * @param {number} index - The viewport index (for tracking)
     * @returns {string|null} The imageUrl if successful, null otherwise
     */
    async loadImageToViewport(viewport, image, index = 0) {
        try {
            const imageUrl = window.DICOM_VIEWER.getImageUrl(image);
            if (!imageUrl) {
                console.error('Invalid image URL');
                return null;
            }

            // Load image
            const loadedImage = await cornerstone.loadImage(imageUrl);

            // Display image
            cornerstone.displayImage(viewport, loadedImage);

            // Reset viewport (no zoom, centered)
            cornerstone.reset(viewport);

            // Fit to window
            cornerstone.fitToWindow(viewport);

            console.log('Image loaded to viewport:', viewport.id, 'URL:', imageUrl);

            return imageUrl; // Return imageUrl for tracking

        } catch (error) {
            console.error('Error loading image to viewport:', error);
            return null;
        }
    }

    /**
     * Fit all images to their viewports
     */
    fitAllImagesToViewports() {
        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
        if (!viewportManager) return;

        const viewports = viewportManager.getAllViewports();

        viewports.forEach(viewport => {
            try {
                const enabledElement = cornerstone.getEnabledElement(viewport);
                if (enabledElement && enabledElement.image) {
                    // Reset zoom and pan
                    cornerstone.reset(viewport);

                    // Fit to window
                    cornerstone.fitToWindow(viewport);

                    // Update viewport
                    cornerstone.updateImage(viewport);
                }
            } catch (error) {
                // Viewport might not have an image
            }
        });

        console.log('Fitted all images to viewports');
    }

    /**
     * Clear all viewports
     */
    clearAllViewports() {
        if (!confirm('Are you sure you want to clear all viewports?')) {
            return;
        }

        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
        if (!viewportManager) return;

        const viewports = viewportManager.getAllViewports();

        viewports.forEach(viewport => {
            try {
                // Disable and re-enable to clear
                cornerstone.disable(viewport);
                cornerstone.enable(viewport);
                console.log('Cleared viewport:', viewport.id);
            } catch (error) {
                console.error('Error clearing viewport:', error);
            }
        });

        console.log('All viewports cleared');
    }

    /**
     * Setup drag and drop between viewports
     */
    setupDragAndDrop() {
        // Watch for viewport creation and add drag listeners
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.classList && node.classList.contains('viewport')) {
                        this.makeViewportDraggable(node);
                    }
                });
            });
        });

        const container = document.getElementById('viewport-container');
        if (container) {
            observer.observe(container, {
                childList: true,
                subtree: true
            });
        }

        // Add drag listeners to existing viewports
        setTimeout(() => {
            const viewports = document.querySelectorAll('.viewport');
            viewports.forEach(viewport => this.makeViewportDraggable(viewport));
        }, 1000);
    }

    /**
     * Make viewport draggable
     */
    makeViewportDraggable(viewport) {
        // Make viewport draggable
        viewport.setAttribute('draggable', 'true');

        // Drag start
        viewport.addEventListener('dragstart', (e) => {
            try {
                const enabledElement = cornerstone.getEnabledElement(viewport);
                if (!enabledElement || !enabledElement.image) {
                    e.preventDefault();
                    return;
                }

                this.draggedViewportId = viewport.id;
                this.draggedImageData = {
                    imageId: enabledElement.image.imageId,
                    viewport: enabledElement.viewport
                };

                viewport.style.opacity = '0.5';
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', viewport.id);

                console.log('Drag started from:', viewport.id);

            } catch (error) {
                e.preventDefault();
            }
        });

        // Drag end
        viewport.addEventListener('dragend', (e) => {
            viewport.style.opacity = '1';
            this.draggedViewportId = null;
            this.draggedImageData = null;
        });

        // Drag over
        viewport.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            viewport.style.border = '3px solid #0d6efd';
        });

        // Drag leave
        viewport.addEventListener('dragleave', (e) => {
            viewport.style.border = '';
        });

        // Drop
        viewport.addEventListener('drop', (e) => {
            e.preventDefault();
            viewport.style.border = '';

            const sourceViewportId = this.draggedViewportId;
            const targetViewportId = viewport.id;

            if (sourceViewportId && sourceViewportId !== targetViewportId) {
                this.swapViewportImages(sourceViewportId, targetViewportId);
            }
        });

        // Touch support for mobile
        this.addTouchDragSupport(viewport);
    }

    /**
     * Swap images between two viewports
     */
    async swapViewportImages(sourceId, targetId) {
        const sourceViewport = document.getElementById(sourceId);
        const targetViewport = document.getElementById(targetId);

        if (!sourceViewport || !targetViewport) {
            console.error('Viewports not found for swap');
            return;
        }

        try {
            // Get source image data
            const sourceEnabled = cornerstone.getEnabledElement(sourceViewport);
            const sourceImage = sourceEnabled ? sourceEnabled.image : null;
            const sourceViewportData = sourceEnabled ? { ...sourceEnabled.viewport } : null;

            // Get target image data
            let targetEnabled = null;
            let targetImage = null;
            let targetViewportData = null;

            try {
                targetEnabled = cornerstone.getEnabledElement(targetViewport);
                targetImage = targetEnabled ? targetEnabled.image : null;
                targetViewportData = targetEnabled ? { ...targetEnabled.viewport } : null;
            } catch (error) {
                // Target might be empty
            }

            // Swap images
            if (sourceImage) {
                // Display source image in target
                await cornerstone.displayImage(targetViewport, sourceImage);
                if (sourceViewportData) {
                    cornerstone.setViewport(targetViewport, sourceViewportData);
                }
            } else {
                // Clear target if source was empty
                cornerstone.disable(targetViewport);
                cornerstone.enable(targetViewport);
            }

            if (targetImage) {
                // Display target image in source
                await cornerstone.displayImage(sourceViewport, targetImage);
                if (targetViewportData) {
                    cornerstone.setViewport(sourceViewport, targetViewportData);
                }
            } else {
                // Clear source if target was empty
                cornerstone.disable(sourceViewport);
                cornerstone.enable(sourceViewport);
            }

            console.log(`Swapped images between ${sourceId} and ${targetId}`);

        } catch (error) {
            console.error('Error swapping viewport images:', error);
        }
    }

    /**
     * Add touch drag support for mobile
     */
    addTouchDragSupport(viewport) {
        let touchStartViewport = null;
        let touchStartImageData = null;

        viewport.addEventListener('touchstart', (e) => {
            try {
                const enabledElement = cornerstone.getEnabledElement(viewport);
                if (!enabledElement || !enabledElement.image) {
                    return;
                }

                touchStartViewport = viewport;
                touchStartImageData = {
                    imageId: enabledElement.image.imageId,
                    viewport: { ...enabledElement.viewport }
                };

                viewport.style.opacity = '0.7';

            } catch (error) {
                // Ignore
            }
        });

        viewport.addEventListener('touchend', (e) => {
            viewport.style.opacity = '1';

            if (!touchStartViewport) return;

            // Find viewport under touch point
            const touch = e.changedTouches[0];
            const targetElement = document.elementFromPoint(touch.clientX, touch.clientY);

            if (targetElement && targetElement.classList.contains('viewport') &&
                targetElement !== touchStartViewport) {
                this.swapViewportImages(touchStartViewport.id, targetElement.id);
            }

            touchStartViewport = null;
            touchStartImageData = null;
        });
    }

    /**
     * Delete image from selected viewport and shift remaining images
     */
    deleteSelectedImage() {
        const state = window.DICOM_VIEWER.STATE;
        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;

        if (!viewportManager) {
            console.error('Viewport manager not found');
            return;
        }

        // Get all viewports in order
        const viewports = viewportManager.getAllViewports();

        // Find selected viewport - use state.selectedViewports or fall back to active viewport
        let selectedViewport = null;
        let selectedIndex = -1;

        if (state.selectedViewports.size > 0) {
            // Use first selected viewport
            const selectedId = Array.from(state.selectedViewports)[0];
            selectedViewport = document.getElementById(selectedId);
            selectedIndex = viewports.findIndex(vp => vp.id === selectedId);
        } else if (state.activeViewport) {
            // Fall back to active viewport
            selectedViewport = state.activeViewport;
            selectedIndex = viewports.indexOf(selectedViewport);
        }

        if (!selectedViewport || selectedIndex === -1) {
            window.DICOM_VIEWER.showAISuggestion('Please select a viewport first (click on it or Ctrl+Click to select)');
            return;
        }

        // Check if selected viewport has an image
        try {
            const enabledElement = cornerstone.getEnabledElement(selectedViewport);
            if (!enabledElement || !enabledElement.image) {
                window.DICOM_VIEWER.showAISuggestion('Selected viewport is already empty');
                return;
            }
        } catch (e) {
            window.DICOM_VIEWER.showAISuggestion('Selected viewport is already empty');
            return;
        }

        console.log(`Deleting image from viewport at index ${selectedIndex}`);

        // Collect images from subsequent viewports
        const imagesToShift = [];
        for (let i = selectedIndex + 1; i < viewports.length; i++) {
            try {
                const enabledElement = cornerstone.getEnabledElement(viewports[i]);
                if (enabledElement && enabledElement.image) {
                    imagesToShift.push({
                        image: enabledElement.image,
                        viewport: cornerstone.getViewport(viewports[i])
                    });
                } else {
                    imagesToShift.push(null);
                }
            } catch (error) {
                imagesToShift.push(null);
            }
        }

        // Shift images: move each subsequent image one position left
        for (let i = selectedIndex; i < viewports.length - 1; i++) {
            const shiftedImage = imagesToShift[i - selectedIndex];
            try {
                if (shiftedImage && shiftedImage.image) {
                    cornerstone.displayImage(viewports[i], shiftedImage.image);
                    if (shiftedImage.viewport) {
                        cornerstone.setViewport(viewports[i], shiftedImage.viewport);
                    }
                } else {
                    // Clear this viewport
                    cornerstone.disable(viewports[i]);
                    cornerstone.enable(viewports[i]);
                }
            } catch (error) {
                console.error(`Error shifting image to viewport ${i}:`, error);
            }
        }

        // Clear the last viewport
        const lastViewport = viewports[viewports.length - 1];
        try {
            cornerstone.disable(lastViewport);
            cornerstone.enable(lastViewport);
        } catch (error) {
            console.error('Error clearing last viewport:', error);
        }

        // Clear selection
        this.deselectAllViewports();

        window.DICOM_VIEWER.showAISuggestion(`Image deleted from viewport ${selectedIndex + 1}, remaining images shifted`);
        console.log('Delete and shift completed');
    }

    /**
     * Toggle select all viewports
     */
    toggleSelectAll() {
        console.log('toggleSelectAll called, allViewportsSelected:', window.DICOM_VIEWER.STATE.allViewportsSelected);
        const state = window.DICOM_VIEWER.STATE;

        if (state.allViewportsSelected) {
            this.deselectAllViewports();
        } else {
            this.selectAllViewports();
        }
    }

    /**
     * Select all viewports
     */
    selectAllViewports() {
        const state = window.DICOM_VIEWER.STATE;
        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;

        if (!viewportManager) return;

        const viewports = viewportManager.getAllViewports();

        viewports.forEach(viewport => {
            state.selectedViewports.add(viewport.id);
            this.updateViewportSelectionVisual(viewport, true);
        });

        state.allViewportsSelected = true;

        // Update button text
        const selectAllBtn = document.getElementById('selectAllBtn');
        if (selectAllBtn) {
            selectAllBtn.innerHTML = '<i class="bi bi-square"></i> Deselect All';
            selectAllBtn.classList.remove('btn-info');
            selectAllBtn.classList.add('btn-secondary');
        }

        window.DICOM_VIEWER.showAISuggestion(`All ${viewports.length} viewports selected - tools will apply to all`);
        console.log(`Selected all ${viewports.length} viewports`);
    }

    /**
     * Deselect all viewports
     */
    deselectAllViewports() {
        const state = window.DICOM_VIEWER.STATE;
        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;

        if (!viewportManager) return;

        const viewports = viewportManager.getAllViewports();

        viewports.forEach(viewport => {
            this.updateViewportSelectionVisual(viewport, false);
        });

        state.selectedViewports.clear();
        state.allViewportsSelected = false;

        // Update button text
        const selectAllBtn = document.getElementById('selectAllBtn');
        if (selectAllBtn) {
            selectAllBtn.innerHTML = '<i class="bi bi-check2-square"></i> Select All';
            selectAllBtn.classList.remove('btn-secondary');
            selectAllBtn.classList.add('btn-info');
        }

        console.log('Deselected all viewports');
    }

    /**
     * Toggle selection for a single viewport
     */
    toggleViewportSelection(viewport) {
        const state = window.DICOM_VIEWER.STATE;

        if (state.selectedViewports.has(viewport.id)) {
            state.selectedViewports.delete(viewport.id);
            this.updateViewportSelectionVisual(viewport, false);
            state.allViewportsSelected = false;

            // Update button if no longer all selected
            const selectAllBtn = document.getElementById('selectAllBtn');
            if (selectAllBtn) {
                selectAllBtn.innerHTML = '<i class="bi bi-check2-square"></i> Select All';
                selectAllBtn.classList.remove('btn-secondary');
                selectAllBtn.classList.add('btn-info');
            }
        } else {
            state.selectedViewports.add(viewport.id);
            this.updateViewportSelectionVisual(viewport, true);

            // Check if all are now selected
            const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
            if (viewportManager) {
                const allViewports = viewportManager.getAllViewports();
                if (state.selectedViewports.size === allViewports.length) {
                    state.allViewportsSelected = true;
                    const selectAllBtn = document.getElementById('selectAllBtn');
                    if (selectAllBtn) {
                        selectAllBtn.innerHTML = '<i class="bi bi-square"></i> Deselect All';
                        selectAllBtn.classList.remove('btn-info');
                        selectAllBtn.classList.add('btn-secondary');
                    }
                }
            }
        }

        const count = state.selectedViewports.size;
        window.DICOM_VIEWER.showAISuggestion(`${count} viewport${count !== 1 ? 's' : ''} selected`);
    }

    /**
     * Update visual selection indicator for viewport
     */
    updateViewportSelectionVisual(viewport, isSelected) {
        if (!viewport) return;

        if (isSelected) {
            viewport.classList.add('viewport-selected');
            viewport.style.outline = '3px solid #ffc107'; // Gold/yellow for selection
            viewport.style.outlineOffset = '-3px';
        } else {
            viewport.classList.remove('viewport-selected');
            viewport.style.outline = '';
            viewport.style.outlineOffset = '';
        }
    }

    /**
     * Setup viewport synchronization for selected viewports
     * When one viewport is zoomed/panned/W-L changed, sync to all selected viewports
     */
    setupViewportSync() {
        const self = this;

        // Sync function that can be called from various event handlers
        const syncViewports = function (sourceViewport) {
            if (self.isSyncing) return;

            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;
            if (!sourceViewport || !state.selectedViewports.has(sourceViewport.id)) return;

            // Get the viewport state from the source
            let sourceVpState;
            try {
                sourceVpState = cornerstone.getViewport(sourceViewport);
            } catch (err) {
                return;
            }

            if (!sourceVpState) return;

            // Sync to all other selected viewports
            self.isSyncing = true;

            state.selectedViewports.forEach(viewportId => {
                if (viewportId === sourceViewport.id) return;

                const targetViewport = document.getElementById(viewportId);
                if (!targetViewport) return;

                try {
                    const targetVpState = cornerstone.getViewport(targetViewport);
                    if (!targetVpState) return;

                    // Sync zoom, pan, W/L, rotation, flip, invert
                    targetVpState.scale = sourceVpState.scale;
                    targetVpState.translation = { ...sourceVpState.translation };
                    targetVpState.voi.windowWidth = sourceVpState.voi.windowWidth;
                    targetVpState.voi.windowCenter = sourceVpState.voi.windowCenter;
                    targetVpState.rotation = sourceVpState.rotation;
                    targetVpState.hflip = sourceVpState.hflip;
                    targetVpState.vflip = sourceVpState.vflip;
                    targetVpState.invert = sourceVpState.invert;

                    cornerstone.setViewport(targetViewport, targetVpState);
                } catch (err) {
                    // Silently ignore sync errors
                }
            });

            // Reset sync flag after a short delay
            setTimeout(() => {
                self.isSyncing = false;
            }, 30);
        };

        // Listen for cornerstone image rendered events
        document.addEventListener('cornerstoneimagerendered', function (e) {
            syncViewports(e.target);
        });

        // Listen for mouse move events on viewports (for real-time sync during drag)
        document.addEventListener('mousemove', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            // Only sync during mouse button press (dragging)
            if (e.buttons !== 1) return;

            // Find if we're inside a selected viewport
            const viewport = e.target.closest('.viewport');
            if (viewport && state.selectedViewports.has(viewport.id)) {
                // Debounce the sync
                if (self.syncTimeout) clearTimeout(self.syncTimeout);
                self.syncTimeout = setTimeout(() => {
                    syncViewports(viewport);
                }, 16); // ~60fps
            }
        });

        // Listen for mouse up to do final sync
        document.addEventListener('mouseup', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            const viewport = e.target.closest('.viewport');
            if (viewport && state.selectedViewports.has(viewport.id)) {
                setTimeout(() => syncViewports(viewport), 50);
            }
        });

        // Listen for wheel events for scroll sync
        document.addEventListener('wheel', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            const viewport = e.target.closest('.viewport');
            if (viewport && state.selectedViewports.has(viewport.id)) {
                setTimeout(() => syncViewports(viewport), 50);
            }
        }, { passive: true });

        console.log('Viewport sync initialized - selected viewports will sync zoom/pan/W-L');
    }
};

// Auto-initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.DICOM_VIEWER.MANAGERS) {
            window.DICOM_VIEWER.MANAGERS = {};
        }
        if (!window.DICOM_VIEWER.MANAGERS.viewportActionsManager) {
            window.DICOM_VIEWER.MANAGERS.viewportActionsManager = new window.DICOM_VIEWER.ViewportActionsManager();
            window.DICOM_VIEWER.MANAGERS.viewportActionsManager.initialize();
        }
    });
} else {
    if (!window.DICOM_VIEWER.MANAGERS) {
        window.DICOM_VIEWER.MANAGERS = {};
    }
    if (!window.DICOM_VIEWER.MANAGERS.viewportActionsManager) {
        window.DICOM_VIEWER.MANAGERS.viewportActionsManager = new window.DICOM_VIEWER.ViewportActionsManager();
        window.DICOM_VIEWER.MANAGERS.viewportActionsManager.initialize();
    }
}

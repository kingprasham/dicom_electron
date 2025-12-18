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
        this.setupSidebarDragAndDrop(); // Enable drag from sidebar to viewport
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

        // Both clearAll and deleteSelected now use the same smart delete function
        if (clearAllBtn) {
            // Update button tooltip to reflect new behavior
            clearAllBtn.title = 'Delete Selected (or All if none selected)';
            clearAllBtn.addEventListener('click', () => this.deleteSelectedImage());
            console.log('Clear/Delete button handler attached (unified)');
        }

        if (deleteSelectedBtn) {
            // Both buttons do the same thing now
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

            // Clear selection and reset UI
            this.clearOrderedSelection();

            // Also hide floating arrange button if visible (skip clearing selection since we just did it)
            if (window.DICOM_VIEWER.hideFloatingArrangeButton) {
                window.DICOM_VIEWER.hideFloatingArrangeButton(true);
            }

            // If in selection mode, exit it
            if (state.isSelectionMode) {
                this.toggleSelectionMode();
            }

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
     * Clear all viewports (DEPRECATED - now uses deleteSelectedImage for unified behavior)
     * This is kept for backward compatibility but just calls deleteSelectedImage
     */
    clearAllViewports() {
        // Redirect to the unified delete function
        this.deleteSelectedImage();
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

        // Enable sidebar item drops on this viewport
        this.enableSidebarDropOnViewport(viewport);
    }

    /**
     * Enable dropping sidebar items onto viewports
     */
    enableSidebarDropOnViewport(viewport) {
        const self = this;
        // Override drop handler to also accept sidebar items
        viewport.addEventListener('drop', async (e) => {
            // Check if this is a sidebar item drop
            const sidebarFileId = e.dataTransfer.getData('sidebar-file-id');
            if (sidebarFileId) {
                e.preventDefault();
                e.stopPropagation();

                // Reset all visual styles - remove drag-over class and inline styles
                viewport.classList.remove('drag-over');
                viewport.style.border = '';
                viewport.style.boxShadow = '';

                console.log('Dropping sidebar item:', sidebarFileId, 'onto viewport:', viewport.id);

                // Find the file in the current series
                const file = window.DICOM_VIEWER.STATE.currentSeriesImages.find(
                    img => img.id === sidebarFileId || img.orthancInstanceId === sidebarFileId
                );

                if (file) {
                    try {
                        // Load the image into this viewport using the manager method
                        await self.loadImageToViewport(viewport, file, 0);
                        console.log('Successfully loaded image to viewport');

                        // Show success state briefly
                        viewport.classList.add('drop-success');
                        setTimeout(() => {
                            viewport.classList.remove('drop-success');
                        }, 500);
                    } catch (error) {
                        console.error('Failed to load image to viewport:', error);
                    }
                }
            }
        }, true); // Use capture to handle before the viewport-to-viewport handler

        // Also handle dragover to add drag-over class for sidebar items
        viewport.addEventListener('dragover', (e) => {
            // Check if it's a sidebar drag
            if (e.dataTransfer.types.includes('sidebar-file-id')) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                if (!viewport.classList.contains('drag-over')) {
                    viewport.classList.add('drag-over');
                }
            }
        });

        // Handle dragleave
        viewport.addEventListener('dragleave', (e) => {
            if (!viewport.contains(e.relatedTarget)) {
                viewport.classList.remove('drag-over');
                viewport.style.border = '';
                viewport.style.boxShadow = '';
            }
        });
    }

    /**
     * Setup sidebar items to be draggable
     */
    setupSidebarDragAndDrop() {
        // Watch for series items and make them draggable
        const seriesList = document.getElementById('series-list');
        if (!seriesList) return;

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) {
                        // Make all series items inside draggable
                        const items = node.classList?.contains('series-item')
                            ? [node]
                            : node.querySelectorAll?.('.series-item') || [];
                        items.forEach(item => this.makeSidebarItemDraggable(item));
                    }
                });
            });
        });

        observer.observe(seriesList, {
            childList: true,
            subtree: true
        });

        // Make existing items draggable
        setTimeout(() => {
            document.querySelectorAll('.series-item').forEach(item => {
                this.makeSidebarItemDraggable(item);
            });
        }, 500);

        console.log('Sidebar drag and drop initialized');
    }

    /**
     * Make a sidebar series item draggable
     */
    makeSidebarItemDraggable(item) {
        if (item.dataset.draggableInitialized) return;
        item.dataset.draggableInitialized = 'true';

        item.setAttribute('draggable', 'true');

        item.addEventListener('dragstart', (e) => {
            const fileId = item.dataset.fileId;
            if (!fileId) {
                e.preventDefault();
                return;
            }

            e.dataTransfer.setData('sidebar-file-id', fileId);
            e.dataTransfer.effectAllowed = 'copy';
            item.style.opacity = '0.5';

            // Create a drag image from the thumbnail
            const thumbnail = item.querySelector('.series-thumbnail img');
            if (thumbnail) {
                e.dataTransfer.setDragImage(thumbnail, 40, 40);
            }

            console.log('Sidebar drag started:', fileId);
        });

        item.addEventListener('dragend', (e) => {
            item.style.opacity = '1';
        });
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
     * Delete images from selected viewports - COMPLETELY REWRITTEN v3 with terminal logging
     *
     * NEW APPROACH: Instead of trying to clear cornerstone viewports (which is unreliable),
     * we REMOVE the images from currentSeriesImages array and reload the viewports.
     *
     * - If no viewports selected: Clear all viewports (with confirmation)
     * - If 1+ viewports selected: Remove those images from series and reload
     */
    async deleteSelectedImage() {
        const logToTerminal = (msg) => {
            console.log(msg);
            // Also log to terminal if running in Electron
            if (typeof process !== 'undefined' && process.stdout) {
                process.stdout.write(msg + '\n');
            }
        };

        logToTerminal('=== DELETE BUTTON CLICKED (v3 - TERMINAL LOGGING) ===');

        const state = window.DICOM_VIEWER.STATE;
        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;

        if (!viewportManager) {
            logToTerminal('❌ ERROR: Viewport manager not found');
            return;
        }

        // Get all viewports in order
        const viewports = viewportManager.getAllViewports();
        const viewportCount = viewports.length;

        // Ensure selectedViewports exists
        if (!state.selectedViewports) {
            state.selectedViewports = new Set();
        }

        const selectedCount = state.selectedViewports.size;
        logToTerminal(`📊 Selected viewports: ${selectedCount}, Total viewports: ${viewportCount}`);
        logToTerminal(`📋 Selected viewport IDs: ${JSON.stringify(Array.from(state.selectedViewports))}`);

        // CASE 1: No viewports selected - clear all (with confirmation)
        if (selectedCount === 0) {
            logToTerminal('🔹 CASE 1: No viewports selected');
            if (!confirm('No viewports selected. Do you want to clear ALL viewports?')) {
                logToTerminal('⏸️  User cancelled');
                return;
            }

            // Clear all viewports by clearing the canvas manually
            for (const viewport of viewports) {
                await this.clearViewportCompletely(viewport);
            }

            // Clear tracking
            state.viewportImages = [];
            state.currentSeriesImages = [];

            window.DICOM_VIEWER.showAISuggestion('All viewports cleared');
            logToTerminal('✅ All viewports cleared');
            return;
        }

        // CASE 2: One or more viewports selected - delete those images
        logToTerminal(`🔹 CASE 2: Deleting ${selectedCount} selected viewport(s)`);

        // Get indices of selected viewports
        const selectedIndices = [];
        const selectedIds = Array.from(state.selectedViewports);

        selectedIds.forEach(viewportId => {
            const index = viewports.findIndex(vp => vp.id === viewportId);
            if (index !== -1) {
                selectedIndices.push(index);
                logToTerminal(`  → Viewport ${viewportId} is at index ${index}`);
            }
        });

        // Sort indices in descending order (for safe splice)
        selectedIndices.sort((a, b) => b - a);
        logToTerminal(`📍 Selected viewport indices (sorted desc): ${JSON.stringify(selectedIndices)}`);

        // Get the current series images - CRITICAL: Use the array reference directly
        if (!state.currentSeriesImages || state.currentSeriesImages.length === 0) {
            logToTerminal('❌ ERROR: No series images to delete');
            window.DICOM_VIEWER.showAISuggestion('No images loaded');
            return;
        }

        // Calculate which series image indices to remove
        const pageNavigator = window.DICOM_VIEWER.MANAGERS.pageNavigator;
        // CRITICAL FIX: Page navigator uses 1-indexed pages (page 1, 2, 3...)
        // We need 0-indexed for array calculations (0, 1, 2...)
        const currentPage = pageNavigator ? (pageNavigator.currentPage || 1) : 1;
        const startImageIndex = (currentPage - 1) * viewportCount;  // Convert to 0-indexed

        logToTerminal(`📄 Current page (1-indexed): ${currentPage}, Start index (0-indexed): ${startImageIndex}`);
        logToTerminal(`📊 Series has ${state.currentSeriesImages.length} images BEFORE deletion`);

        // Calculate the actual series indices to remove
        const indicesToRemove = [];
        for (const vpIndex of selectedIndices) {
            const seriesIndex = startImageIndex + vpIndex;
            if (seriesIndex < state.currentSeriesImages.length) {
                indicesToRemove.push(seriesIndex);
                logToTerminal(`  → Will remove series index ${seriesIndex} (viewport ${vpIndex})`);
            } else {
                logToTerminal(`  ⚠️  Skipping viewport ${vpIndex} - beyond series length`);
            }
        }

        if (indicesToRemove.length === 0) {
            logToTerminal('❌ No valid images to delete');
            return;
        }

        // Sort descending for safe removal
        indicesToRemove.sort((a, b) => b - a);
        logToTerminal(`🗑️  Final indices to remove (desc order): ${JSON.stringify(indicesToRemove)}`);

        // CRITICAL FIX: Remove images from the STATE array directly (NOT a copy)
        logToTerminal(`🔧 Removing ${indicesToRemove.length} images from STATE.currentSeriesImages...`);
        for (const idx of indicesToRemove) {
            const removedImage = state.currentSeriesImages[idx];
            logToTerminal(`  🗑️  Removing index ${idx}: ${removedImage?.id || 'unknown'}`);
            state.currentSeriesImages.splice(idx, 1);
        }

        logToTerminal(`✅ Series now has ${state.currentSeriesImages.length} images AFTER deletion`);
        logToTerminal(`📊 Verification: STATE.currentSeriesImages.length = ${state.currentSeriesImages.length}`);

        // Clear selection BEFORE reloading
        this.deselectAllViewports();

        // SMOOTH DELETION: Instead of clearing all viewports, just shift images
        logToTerminal(`🔄 Smoothly shifting remaining images...`);
        await this.smoothShiftImages(viewports, state.currentSeriesImages, startImageIndex, indicesToRemove.length);

        // Update page navigator without reloading
        if (pageNavigator) {
            const originalLoad = pageNavigator.loadCurrentPageImages;
            pageNavigator.loadCurrentPageImages = async function() {
                logToTerminal('📄 [SKIP] Page navigator reload prevented');
            };
            pageNavigator.refresh();
            pageNavigator.loadCurrentPageImages = originalLoad;
            logToTerminal('📄 Page navigator updated');
        }

        // Show message
        const deletedCount = indicesToRemove.length;
        if (deletedCount === 1) {
            window.DICOM_VIEWER.showAISuggestion(`Deleted 1 image, ${state.currentSeriesImages.length} remaining`);
        } else {
            window.DICOM_VIEWER.showAISuggestion(`Deleted ${deletedCount} images, ${state.currentSeriesImages.length} remaining`);
        }

        logToTerminal('✅✅✅ DELETE COMPLETED SUCCESSFULLY ✅✅✅');
        logToTerminal(`Final verification: ${state.currentSeriesImages.length} images in series`);
    }

    /**
     * Smooth image shifting after deletion - NO VISIBLE REFRESH
     *
     * Instead of clearing all viewports and reloading, we:
     * 1. Only update viewports that need to change
     * 2. Load next images into empty slots
     * 3. Clear only the last viewport if needed
     *
     * This creates an instant, smooth deletion experience
     */
    async smoothShiftImages(viewports, seriesImages, startImageIndex, deletedCount) {
        const viewportCount = viewports.length;
        const state = window.DICOM_VIEWER.STATE;

        console.log(`Smooth shift: ${deletedCount} deleted, shifting from index ${startImageIndex}`);

        // For each viewport, load the correct image (shifted by deletion)
        for (let i = 0; i < viewportCount; i++) {
            const viewport = viewports[i];
            const imageIndex = startImageIndex + i;

            if (imageIndex < seriesImages.length) {
                // Load the next image (shifted position)
                const image = seriesImages[imageIndex];

                try {
                    // Get current image in this viewport
                    let currentImageId = null;
                    try {
                        const enabledElement = cornerstone.getEnabledElement(viewport);
                        if (enabledElement && enabledElement.image) {
                            currentImageId = enabledElement.image.imageId;
                        }
                    } catch (e) {}

                    // Generate the URL for the new image
                    const newImageUrl = window.DICOM_VIEWER.getImageUrl(image);

                    // Only reload if the image is different (avoid unnecessary reloads)
                    if (currentImageId !== newImageUrl) {
                        console.log(`Viewport ${i + 1}: Loading image ${imageIndex + 1}`);
                        const loadedImage = await cornerstone.loadImage(newImageUrl);
                        await cornerstone.displayImage(viewport, loadedImage);
                        cornerstone.fitToWindow(viewport);
                        state.viewportImages[i] = newImageUrl;
                    } else {
                        console.log(`Viewport ${i + 1}: Already showing correct image`);
                    }
                } catch (error) {
                    console.error(`Error loading image into viewport ${i + 1}:`, error);
                }
            } else {
                // No more images - clear this viewport (it's at the end)
                console.log(`Viewport ${i + 1}: Clearing (no more images)`);
                try {
                    const canvas = viewport.querySelector('canvas');
                    if (canvas) {
                        const ctx = canvas.getContext('2d');
                        if (ctx) {
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                        }
                    }
                } catch (e) {
                    console.warn('Could not clear viewport:', e);
                }
            }
        }

        console.log('Smooth shift complete');
    }

    /**
     * Completely clear a viewport - REWRITTEN using Cornerstone.js best practices
     *
     * Based on research:
     * - cornerstone.disable() + enable() is unreliable and leaves residual image data
     * - Must purge from image cache to truly remove image
     * - Canvas must be completely cleared before re-enabling
     *
     * New approach:
     * 1. Get the current imageId (to remove from cache)
     * 2. Disable the viewport
     * 3. Remove image from Cornerstone's image cache
     * 4. Clear all canvas elements completely
     * 5. Re-enable viewport with fresh state
     */
    async clearViewportCompletely(viewport) {
        try {
            console.log('Clearing viewport:', viewport.id);

            // Step 1: Get the current image ID before disabling (to purge from cache)
            let imageId = null;
            try {
                const enabledElement = cornerstone.getEnabledElement(viewport);
                if (enabledElement && enabledElement.image) {
                    imageId = enabledElement.image.imageId;
                    console.log('Found imageId to purge:', imageId);
                }
            } catch (e) {
                // Viewport may not have an image
            }

            // Step 2: Disable viewport (this removes cornerstone's internal state)
            try {
                cornerstone.disable(viewport);
                console.log('Disabled viewport:', viewport.id);
            } catch (e) {
                // Viewport might not be enabled - that's OK
                console.log('Viewport was not enabled:', viewport.id);
            }

            // Step 3: Remove image from cache (prevents residual data)
            if (imageId && cornerstone.imageCache) {
                try {
                    // Remove from cache using Cornerstone's cache API
                    cornerstone.imageCache.removeImageLoadObject(imageId);
                    console.log('Removed image from cache:', imageId);
                } catch (e) {
                    console.warn('Could not remove from cache (image may not be cached):', e.message);
                }
            }

            // Step 4: Manually clear ALL canvas elements (cornerstone may create multiple)
            const canvases = viewport.querySelectorAll('canvas');
            canvases.forEach(canvas => {
                try {
                    const ctx = canvas.getContext('2d');
                    if (ctx) {
                        // Clear entire canvas
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        // Reset canvas dimensions to force complete clear
                        const w = canvas.width;
                        const h = canvas.height;
                        canvas.width = 1;
                        canvas.height = 1;
                        canvas.width = w;
                        canvas.height = h;
                    }
                } catch (e) {
                    console.warn('Could not clear canvas:', e);
                }
            });

            // Step 5: Remove any lingering cornerstone elements
            // Cornerstone creates additional div layers - remove them all
            const cornerstoneElements = viewport.querySelectorAll('div');
            cornerstoneElements.forEach(el => {
                if (el !== viewport && el.parentElement === viewport) {
                    el.remove();
                }
            });

            // Step 6: Re-enable viewport with clean state
            await new Promise(resolve => setTimeout(resolve, 100)); // Give time for cleanup

            try {
                cornerstone.enable(viewport);
                console.log('Re-enabled viewport with clean state:', viewport.id);
            } catch (e) {
                console.warn('Could not re-enable viewport:', e);
            }

            console.log('✓ Viewport completely cleared:', viewport.id);

        } catch (error) {
            console.error('Error in clearViewportCompletely:', error);
        }
    }

    /**
     * Reload all viewports from series images - ENHANCED for reliability
     *
     * Key improvements:
     * - Force-clear all viewports BEFORE loading new images (prevents residual data)
     * - Ensure viewports are properly enabled before loading
     * - Better error handling and recovery
     */
    async reloadViewportsFromSeries(viewports, seriesImages, currentPage = 1) {
        const viewportCount = viewports.length;
        // CRITICAL FIX: currentPage is 1-indexed (page 1, 2, 3...)
        // Convert to 0-indexed for array calculations
        const startImageIndex = (currentPage - 1) * viewportCount;
        const state = window.DICOM_VIEWER.STATE;

        // Clear viewport images tracking
        state.viewportImages = [];

        console.log(`=== RELOADING VIEWPORTS ===`);
        console.log(`Page: ${currentPage} (1-indexed), Start Index: ${startImageIndex} (0-indexed), Total Images: ${seriesImages.length}`);

        // STEP 1: FORCE CLEAR ALL VIEWPORTS FIRST (prevents residual images)
        console.log('Step 1: Clearing all viewports...');
        for (let i = 0; i < viewportCount; i++) {
            const viewport = viewports[i];
            await this.clearViewportCompletely(viewport);
        }

        // Small delay to ensure clearing is complete
        await new Promise(resolve => setTimeout(resolve, 150));

        // STEP 2: Load new images into cleared viewports
        console.log('Step 2: Loading new images...');
        for (let i = 0; i < viewportCount; i++) {
            const viewport = viewports[i];
            const imageIndex = startImageIndex + i;

            if (imageIndex < seriesImages.length) {
                const image = seriesImages[imageIndex];
                console.log(`Loading image ${imageIndex + 1}/${seriesImages.length} into viewport ${i + 1}/${viewportCount}`);

                try {
                    // Ensure viewport is enabled before loading
                    try {
                        cornerstone.getEnabledElement(viewport);
                    } catch (e) {
                        // Not enabled, enable it
                        console.log(`Enabling viewport ${viewport.id} before loading`);
                        cornerstone.enable(viewport);
                        await new Promise(resolve => setTimeout(resolve, 50));
                    }

                    // Load the image
                    const url = await this.loadImageToViewport(viewport, image, i);
                    if (url) {
                        state.viewportImages[i] = url;
                        console.log(`✓ Loaded image into viewport ${i + 1}`);
                    } else {
                        console.warn(`✗ Failed to get URL for viewport ${i + 1}`);
                    }
                } catch (error) {
                    console.error(`✗ Error loading image into viewport ${i + 1}:`, error);
                    // On error, try to clear and re-enable the viewport
                    await this.clearViewportCompletely(viewport);
                }
            } else {
                // No more images - viewport already cleared in step 1
                console.log(`No image for viewport ${i + 1} (beyond series length)`);
            }
        }

        // STEP 3: Fit all loaded images to viewports
        console.log('Step 3: Fitting images to viewports...');
        await new Promise(resolve => setTimeout(resolve, 100));
        this.fitAllImagesToViewports();

        console.log('=== RELOAD COMPLETE ===');
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

        // REMOVED: Toast notification - visual selection (yellow border) is sufficient feedback
        console.log(`Selected all ${viewports.length} viewports - tools will apply to all`);
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

        // Silent selection - no toast message displayed
        // The visual selection (yellow border) is sufficient feedback
    }

    /**
     * Update visual selection indicator for viewport
     * Uses yellow border (same as active viewport for consistency)
     */
    updateViewportSelectionVisual(viewport, isSelected) {
        if (!viewport) return;

        if (isSelected) {
            viewport.classList.add('viewport-selected');
            // Use border instead of outline for consistency with setActiveViewport
            viewport.style.border = '3px solid #ffc107'; // Gold/yellow for selection
            viewport.style.boxShadow = '0 0 15px rgba(255, 193, 7, 0.5)'; // Yellow glow
            viewport.style.outline = ''; // Clear outline since we use border
            viewport.style.outlineOffset = '';
        } else {
            viewport.classList.remove('viewport-selected');
            viewport.classList.remove('active');
            // Reset to default border based on viewport type
            if (viewport.classList.contains('mpr-view')) {
                viewport.style.border = '1px solid #28a745'; // Green for MPR
            } else {
                viewport.style.border = '1px solid #444444'; // Gray for normal
            }
            viewport.style.boxShadow = '';
            viewport.style.outline = '';
            viewport.style.outlineOffset = '';
        }
    }

    /**
     * Setup viewport synchronization for selected viewports
     * When one viewport is zoomed/panned/W-L changed, sync to all selected viewports
     * ENHANCED: Now supports real-time tool synchronization for Zoom, Pan, W/L tools
     * FIXED: Uses Cornerstone's event system for reliable tool operation detection
     */
    setupViewportSync() {
        const self = this;

        // Track the previous viewport state for calculating deltas
        this.previousViewportStates = new Map();
        this.initialViewportStates = new Map(); // Store states at mousedown for delta calc

        // Sync function that can be called from various event handlers
        const syncViewports = function (sourceViewport, forceSync = false) {
            if (self.isSyncing && !forceSync) return;

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
                    cornerstone.updateImage(targetViewport);
                } catch (err) {
                    // Silently ignore sync errors
                }
            });

            // Reset sync flag after a short delay
            setTimeout(() => {
                self.isSyncing = false;
            }, 10);
        };

        // Delta-based sync: applies relative changes from initial state
        const syncViewportsDelta = function (sourceViewport) {
            if (self.isSyncing) return;

            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;
            if (!sourceViewport || !state.selectedViewports.has(sourceViewport.id)) return;

            // Get current source state
            let sourceVpState;
            try {
                sourceVpState = cornerstone.getViewport(sourceViewport);
            } catch (err) {
                return;
            }
            if (!sourceVpState) return;

            // Get initial source state
            const sourceInitial = self.initialViewportStates.get(sourceViewport.id);
            if (!sourceInitial) {
                // Fallback to absolute sync if no initial state
                syncViewports(sourceViewport, true);
                return;
            }

            // Calculate deltas from initial state
            const deltaScale = sourceVpState.scale / sourceInitial.scale;
            const deltaPanX = sourceVpState.translation.x - sourceInitial.translationX;
            const deltaPanY = sourceVpState.translation.y - sourceInitial.translationY;
            const deltaWW = sourceVpState.voi.windowWidth - sourceInitial.windowWidth;
            const deltaWC = sourceVpState.voi.windowCenter - sourceInitial.windowCenter;

            self.isSyncing = true;

            state.selectedViewports.forEach(viewportId => {
                if (viewportId === sourceViewport.id) return;

                const targetViewport = document.getElementById(viewportId);
                if (!targetViewport) return;

                const targetInitial = self.initialViewportStates.get(viewportId);
                if (!targetInitial) return;

                try {
                    const targetVpState = cornerstone.getViewport(targetViewport);
                    if (!targetVpState) return;

                    // Apply deltas relative to each viewport's initial state
                    targetVpState.scale = targetInitial.scale * deltaScale;
                    targetVpState.scale = Math.max(0.1, Math.min(10, targetVpState.scale));
                    targetVpState.translation.x = targetInitial.translationX + deltaPanX;
                    targetVpState.translation.y = targetInitial.translationY + deltaPanY;
                    targetVpState.voi.windowWidth = Math.max(1, targetInitial.windowWidth + deltaWW);
                    targetVpState.voi.windowCenter = targetInitial.windowCenter + deltaWC;

                    cornerstone.setViewport(targetViewport, targetVpState);
                    cornerstone.updateImage(targetViewport);
                } catch (err) {
                    // Silently ignore sync errors
                }
            });

            setTimeout(() => {
                self.isSyncing = false;
            }, 5);
        };

        // Capture initial state of ALL selected viewports when mouse goes down
        const captureInitialStates = function (triggerViewport) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            self.initialViewportStates.clear();

            state.selectedViewports.forEach(viewportId => {
                const viewport = document.getElementById(viewportId);
                if (!viewport) return;

                try {
                    const vpState = cornerstone.getViewport(viewport);
                    if (vpState) {
                        self.initialViewportStates.set(viewportId, {
                            scale: vpState.scale,
                            translationX: vpState.translation.x,
                            translationY: vpState.translation.y,
                            windowWidth: vpState.voi.windowWidth,
                            windowCenter: vpState.voi.windowCenter
                        });
                    }
                } catch (err) {
                    // Ignore
                }
            });
        };

        // Listen for cornerstone image rendered events (fallback sync)
        document.addEventListener('cornerstoneimagerendered', function (e) {
            // Only sync if we have initial states captured (meaning a tool operation is in progress)
            if (self.initialViewportStates.size > 0) {
                syncViewportsDelta(e.target);
            }
        });

        // ENHANCED: Listen for cornerstone tool mouse drag events for real-time sync
        document.addEventListener('cornerstonetoolsmousedrag', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            const viewport = e.detail.element;
            if (!viewport || !state.selectedViewports.has(viewport.id)) return;

            // Sync using delta during tool drag
            syncViewportsDelta(viewport);
        });

        // CRITICAL: Capture mousedown on viewports to store initial states
        document.addEventListener('mousedown', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            const viewport = e.target.closest('.viewport');
            if (viewport && state.selectedViewports.has(viewport.id)) {
                captureInitialStates(viewport);
            }
        }, true);

        // ENHANCED: Listen for mouse move events on viewports with better tracking
        let lastSyncTime = 0;
        const SYNC_INTERVAL = 16; // ~60fps

        document.addEventListener('mousemove', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            // Only sync during mouse button press (dragging) 
            if (e.buttons === 0) return;

            // Find if we're inside a selected viewport
            const viewport = e.target.closest('.viewport');
            if (viewport && state.selectedViewports.has(viewport.id)) {
                // Throttle sync to prevent performance issues
                const now = performance.now();
                if (now - lastSyncTime >= SYNC_INTERVAL) {
                    lastSyncTime = now;
                    syncViewportsDelta(viewport);
                }
            }
        });

        // Listen for mouse up to do final sync and clear initial states
        document.addEventListener('mouseup', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) {
                self.initialViewportStates.clear();
                return;
            }

            const viewport = e.target.closest('.viewport');
            if (viewport && state.selectedViewports.has(viewport.id)) {
                // Final sync after mouse release
                setTimeout(() => {
                    syncViewportsDelta(viewport);
                    self.initialViewportStates.clear();
                }, 20);
            } else {
                self.initialViewportStates.clear();
            }
        });

        // ENHANCED: Listen for wheel events with immediate sync for zoom operations
        document.addEventListener('wheel', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            const viewport = e.target.closest('.viewport');
            if (viewport && state.selectedViewports.has(viewport.id)) {
                // Capture initial states for wheel zoom
                if (self.initialViewportStates.size === 0) {
                    captureInitialStates(viewport);
                }
                // Immediate sync for wheel zoom
                setTimeout(() => {
                    syncViewportsDelta(viewport);
                    // Clear after short delay to allow consecutive wheel events
                    setTimeout(() => self.initialViewportStates.clear(), 100);
                }, 10);
            }
        }, { passive: true });

        // ENHANCED: Listen for cornerstone tool mousedown to capture initial state
        document.addEventListener('cornerstonetoolsmousedown', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            const viewport = e.detail.element;
            if (!viewport || !state.selectedViewports.has(viewport.id)) return;

            // Capture initial states for all selected viewports
            captureInitialStates(viewport);
        });

        // ENHANCED: Listen for cornerstone tool mouseup to do final sync
        document.addEventListener('cornerstonetoolsmouseup', function (e) {
            const state = window.DICOM_VIEWER.STATE;
            if (!state.selectedViewports || state.selectedViewports.size <= 1) return;

            const viewport = e.detail.element;
            if (!viewport || !state.selectedViewports.has(viewport.id)) return;

            // Final sync after tool operation
            setTimeout(() => {
                syncViewportsDelta(viewport);
                self.initialViewportStates.clear();
            }, 30);
        });

        console.log('Enhanced viewport sync initialized - selected viewports will sync zoom/pan/W-L in real-time');
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

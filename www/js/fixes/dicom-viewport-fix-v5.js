/**
 * DICOM Viewer Fix v5.0 - Complete Rewrite
 * 
 * FIXES:
 * 1. Pagination logic - correct image ordering
 * 2. Viewport badges - NO FLICKERING (stable)
 * 3. Windows-style viewport selection (proper implementation)
 * 4. Empty viewport drag-drop
 * 
 * INSTALLATION:
 * 1. Save to: www/js/fixes/dicom-viewport-fix-v5.js
 * 2. Add to index.html BEFORE </body>:
 *    <script src="js/fixes/dicom-viewport-fix-v5.js"></script>
 */

(function () {
    'use strict';

    const log = (...args) => console.log('[FixV5]', ...args);
    const warn = (...args) => console.warn('[FixV5 WARN]', ...args);
    const error = (...args) => console.error('[FixV5 ERROR]', ...args);

    console.log('');
    log('='.repeat(60));
    log('🔧 DICOM Viewport Fix v5.0 Loading...');
    log('='.repeat(60));

    // =========================================================================
    // GLOBAL STATE
    // =========================================================================

    // Selected viewports set - USE STATE's set for unified behavior
    // This will be assigned to STATE.selectedViewports in initialize()
    let selectedViewports = new Set();

    // Badge state cache - prevents flickering by only updating on actual changes
    let badgeStateCache = '';
    let badgeUpdatePending = false;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    function waitForDependencies(callback, maxAttempts = 30) {
        let attempts = 0;
        const check = () => {
            attempts++;
            const ready = window.DICOM_VIEWER &&
                window.cornerstone &&
                window.DICOM_VIEWER.STATE &&
                window.DICOM_VIEWER.MANAGERS;

            if (ready) {
                log('✅ Dependencies ready');
                setTimeout(callback, 500); // Extra delay for stability
            } else if (attempts < maxAttempts) {
                setTimeout(check, 300);
            } else {
                error('Dependencies not found!');
            }
        };
        check();
    }

    function initialize() {
        log('Initializing all fixes...');

        // USE STATE's selectedViewports if it exists, otherwise create and assign
        if (window.DICOM_VIEWER.STATE && window.DICOM_VIEWER.STATE.selectedViewports) {
            // Use the existing Set from STATE
            selectedViewports = window.DICOM_VIEWER.STATE.selectedViewports;
            log('Using existing STATE.selectedViewports');
        } else {
            // Create new Set and assign to STATE
            if (window.DICOM_VIEWER.STATE) {
                window.DICOM_VIEWER.STATE.selectedViewports = selectedViewports;
            }
            log('Created new selectedViewports Set');
        }
        
        // Expose selected viewports globally for compatibility
        window.DICOM_VIEWER.selectedViewports = selectedViewports;

        // Apply fixes
        fixPaginationLogic();
        fixViewportSelection();
        fixViewportBadges();
        fixDragDrop();

        // Inject styles
        injectStyles();

        log('='.repeat(60));
        log('✅ DICOM Viewport Fix v5.0 Initialized');
        log('='.repeat(60));
    }

    // =========================================================================
    // FIX 1: PAGINATION LOGIC
    // =========================================================================

    function fixPaginationLogic() {
        log('Fixing pagination...');

        const pageNavigator = window.DICOM_VIEWER.MANAGERS?.pageNavigator;
        if (!pageNavigator) {
            setTimeout(fixPaginationLogic, 1000);
            return;
        }

        // Override calculatePages
        pageNavigator.calculatePages = function () {
            const state = window.DICOM_VIEWER.STATE;
            const totalImages = state.currentSeriesImages?.length || 0;

            if (totalImages === 0) {
                this.totalPages = 1;
                this.currentPage = 1;
                this.imagesPerPage = 4;
                return;
            }

            // Count actual viewports
            const viewportContainer = document.getElementById('viewport-container');
            let viewportCount = viewportContainer?.querySelectorAll('.viewport').length || 4;
            viewportCount = Math.max(1, Math.min(25, viewportCount));

            this.imagesPerPage = viewportCount;
            this.totalPages = Math.ceil(totalImages / this.imagesPerPage);

            if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
            if (this.currentPage < 1) this.currentPage = 1;

            const needsPagination = this.totalPages > 1;
            if (needsPagination && !this.isEnabled) this.enable();
            else if (!needsPagination && this.isEnabled) this.disable();

            if (this.isEnabled) this.updatePageIndicator();
        };

        // Override goToPage
        pageNavigator.goToPage = async function (pageNum) {
            this.calculatePages();
            if (pageNum < 1 || pageNum > this.totalPages || pageNum === this.currentPage) return;
            this.currentPage = pageNum;
            await this.loadCurrentPageImages();
            this.updatePageIndicator();
        };

        // Listen for layout changes
        document.addEventListener('layoutChanged', () => {
            setTimeout(() => {
                const viewports = document.querySelectorAll('.viewport');
                pageNavigator.imagesPerPage = viewports.length || 4;
                pageNavigator.currentPage = 1;
                pageNavigator.calculatePages();

                const state = window.DICOM_VIEWER.STATE;
                if (state.currentSeriesImages?.length > 0) {
                    pageNavigator.loadCurrentPageImages();
                }

                // Re-setup selection on new viewports
                setupAllViewportSelectionHandlers();
            }, 400);
        });

        log('✅ Pagination fixed');
    }

    // =========================================================================
    // FIX 2: VIEWPORT SELECTION (Windows-style)
    // =========================================================================

    function fixViewportSelection() {
        log('Fixing viewport selection...');

        // Clear any existing selection state
        selectedViewports.clear();

        // Setup handlers on existing viewports
        setupAllViewportSelectionHandlers();

        // Override setActiveViewport to use our selection system
        const viewportManager = window.DICOM_VIEWER.MANAGERS?.viewportManager;
        if (viewportManager) {
            viewportManager.setActiveViewport = function (viewport) {
                if (!viewport) return;

                // Just update the internal reference, don't change visual selection
                this.activeViewport = viewport;
                window.activeViewport = viewport;
                window.DICOM_VIEWER.STATE.activeViewport = viewport;
            };
        }

        // Override toggleViewportSelection for Ctrl+Click
        const viewportActionsManager = window.DICOM_VIEWER.MANAGERS?.viewportActionsManager;
        if (viewportActionsManager) {
            viewportActionsManager.toggleViewportSelection = function (viewport) {
                toggleSelection(viewport, true);
            };

            // Override getSelectedViewports - THIS IS CRITICAL FOR TOOLS
            viewportActionsManager.getSelectedViewports = function () {
                const result = Array.from(selectedViewports);
                log(`getSelectedViewports called, returning ${result.length} viewports`);
                return result;
            };

            // Also store reference for direct access
            viewportActionsManager.selectedViewports = selectedViewports;
        }

        // CRITICAL: Hook into cornerstoneTools to use our selection
        // This ensures zoom/pan/etc work on selected viewports only
        if (window.cornerstoneTools) {
            // Override any multi-viewport tool behavior
            const originalGetEnabledElement = cornerstone.getEnabledElement;

            // Make sure getSelectedViewports is available globally
            window.getSelectedViewports = function () {
                return Array.from(selectedViewports);
            };
        }

        // Watch for new viewports
        const observer = new MutationObserver((mutations) => {
            let hasNewViewports = false;
            mutations.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (node.nodeType === 1) {
                        if (node.classList?.contains('viewport')) hasNewViewports = true;
                        if (node.querySelectorAll?.('.viewport').length > 0) hasNewViewports = true;
                    }
                });
            });
            if (hasNewViewports) {
                setTimeout(setupAllViewportSelectionHandlers, 200);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });

        log('✅ Viewport selection fixed');
    }

    function setupAllViewportSelectionHandlers() {
        const viewports = document.querySelectorAll('.viewport');

        viewports.forEach((viewport, index) => {
            // Skip if already setup
            if (viewport.dataset.selectionV5 === 'true') return;
            viewport.dataset.selectionV5 = 'true';

            // Hide the ACTIVE indicator
            const activeIndicator = viewport.querySelector('.active-status-indicator');
            if (activeIndicator) activeIndicator.style.display = 'none';

            // Store Ctrl state on mousedown (before click fires)
            let ctrlWasPressed = false;

            viewport.addEventListener('mousedown', (e) => {
                // Capture Ctrl state immediately on mousedown
                ctrlWasPressed = e.ctrlKey || e.metaKey;
                log(`Mousedown on viewport ${index + 1}, Ctrl: ${ctrlWasPressed}`);
            }, true);

            // Handle click with the captured Ctrl state
            viewport.addEventListener('click', (e) => {
                // Ignore if clicking on controls
                if (e.target.closest('.viewport-drag-handle')) return;
                if (e.target.closest('button')) return;

                // IMPORTANT: Don't interfere if text annotation tool is active
                if (window.DICOM_VIEWER?.MANAGERS?.textAnnotationTool?.isActive) {
                    log(`Text annotation tool is active, allowing event to propagate`);
                    return; // Let text tool handle the click
                }

                // Use the Ctrl state captured at mousedown, OR check again
                const ctrlPressed = ctrlWasPressed || e.ctrlKey || e.metaKey;

                log(`Click on viewport ${index + 1}, Ctrl: ${ctrlPressed}, current selection: ${selectedViewports.size}`);

                // Prevent other handlers from interfering
                e.stopPropagation();

                selectViewport(viewport, ctrlPressed);

                // Reset for next click
                ctrlWasPressed = false;
            }, true);

            // Also setup on the canvas inside (cornerstone renders here)
            const setupCanvasClick = () => {
                const canvas = viewport.querySelector('canvas');
                if (canvas && canvas.dataset.clickV5 !== 'true') {
                    canvas.dataset.clickV5 = 'true';

                    canvas.addEventListener('mousedown', (e) => {
                        ctrlWasPressed = e.ctrlKey || e.metaKey;
                    }, true);

                    canvas.addEventListener('click', (e) => {
                        // IMPORTANT: Don't interfere if text annotation tool is active
                        if (window.DICOM_VIEWER?.MANAGERS?.textAnnotationTool?.isActive) {
                            log(`Text annotation tool is active on canvas, allowing event to propagate`);
                            return; // Let text tool handle the click
                        }

                        const ctrlPressed = ctrlWasPressed || e.ctrlKey || e.metaKey;
                        log(`Canvas click on viewport ${index + 1}, Ctrl: ${ctrlPressed}`);
                        e.stopPropagation();
                        selectViewport(viewport, ctrlPressed);
                        ctrlWasPressed = false;
                    }, true);
                }
            };

            setupCanvasClick();

            // Watch for canvas being added later
            new MutationObserver(setupCanvasClick).observe(viewport, { childList: true, subtree: true });
        });

        // Initial: select first viewport if nothing is selected
        if (selectedViewports.size === 0 && viewports.length > 0) {
            selectViewport(viewports[0], false);
        } else {
            // Re-apply visual styles to already-selected viewports
            viewports.forEach(vp => {
                if (selectedViewports.has(vp.id)) {
                    applySelectedStyle(vp);
                }
            });
        }
    }

    function selectViewport(viewport, ctrlPressed) {
        // CRITICAL: Use viewport.id for selection (compatible with viewport-actions-manager)
        const viewportId = viewport.id;
        
        log(`=== selectViewport ===`);
        log(`  Ctrl pressed: ${ctrlPressed}`);
        log(`  Current selection size: ${selectedViewports.size}`);
        log(`  Viewport ID: ${viewportId}`);
        log(`  Viewport already selected: ${selectedViewports.has(viewportId)}`);

        if (ctrlPressed) {
            // =====================================================
            // CTRL+CLICK: Toggle this viewport in multi-selection
            // =====================================================
            if (selectedViewports.has(viewportId)) {
                // Already selected - remove from selection
                selectedViewports.delete(viewportId);
                applyDeselectedStyle(viewport);
                log(`  → Removed from selection (Ctrl+Click on selected)`);
            } else {
                // Not selected - add to selection (keep others)
                selectedViewports.add(viewportId);
                applySelectedStyle(viewport);
                log(`  → Added to selection (Ctrl+Click on unselected)`);
            }
        } else {
            // =====================================================
            // NORMAL CLICK: Check if clicking on already-selected viewport in multi-select
            // =====================================================
            // If multiple viewports selected and clicking on one of them, just set active
            // (Don't deselect - allows tools to work on all selected)
            if (selectedViewports.size > 1 && selectedViewports.has(viewportId)) {
                log(`  → Clicking on already-selected viewport in multi-select - keeping selection`);
            } else {
                // First, deselect ALL viewports
                document.querySelectorAll('.viewport').forEach(vp => {
                    if (selectedViewports.has(vp.id)) {
                        selectedViewports.delete(vp.id);
                        applyDeselectedStyle(vp);
                    }
                });

                // Then select only this one
                selectedViewports.add(viewportId);
                applySelectedStyle(viewport);
                log(`  → Single selection (normal click)`);
            }
        }

        log(`  Final selection size: ${selectedViewports.size}`);

        // Update the active viewport reference (for tools that use it)
        const viewportManager = window.DICOM_VIEWER.MANAGERS?.viewportManager;
        if (viewportManager) {
            viewportManager.activeViewport = viewport;
            window.activeViewport = viewport;
            window.DICOM_VIEWER.STATE.activeViewport = viewport;
        }

        // Sync selection with STATE
        updateSelectionIndicator();
    }

    function updateSelectionIndicator() {
        // REMOVED: Toast indicator - yellow border provides sufficient visual feedback
        // The indicator was causing confusion and is not needed
        let indicator = document.getElementById('multi-select-indicator-v5');
        if (indicator) {
            indicator.style.display = 'none';
        }
        
        // Keep STATE in sync
        if (window.DICOM_VIEWER && window.DICOM_VIEWER.STATE) {
            window.DICOM_VIEWER.STATE.selectedViewports = selectedViewports;
        }
    }

    function toggleSelection(viewport, addToSelection) {
        const viewportId = viewport.id;
        if (addToSelection) {
            if (selectedViewports.has(viewportId)) {
                selectedViewports.delete(viewportId);
                applyDeselectedStyle(viewport);
            } else {
                selectedViewports.add(viewportId);
                applySelectedStyle(viewport);
            }
            updateSelectionIndicator(); // Keep STATE in sync
        } else {
            selectViewport(viewport, false);
        }
    }

    function applySelectedStyle(viewport) {
        viewport.classList.add('viewport-selected-v5');
        viewport.style.border = '3px solid #ffc107';
        viewport.style.boxShadow = '0 0 20px rgba(255, 193, 7, 0.7)';

        // Hide ACTIVE text
        const activeIndicator = viewport.querySelector('.active-status-indicator');
        if (activeIndicator) activeIndicator.style.display = 'none';
    }

    function applyDeselectedStyle(viewport) {
        viewport.classList.remove('viewport-selected-v5');
        viewport.classList.remove('active');

        const isMPR = viewport.classList.contains('mpr-view');
        viewport.style.border = isMPR ? '1px solid #28a745' : '1px solid #444';
        viewport.style.boxShadow = '';

        // Hide ACTIVE text
        const activeIndicator = viewport.querySelector('.active-status-indicator');
        if (activeIndicator) activeIndicator.style.display = 'none';
    }

    // =========================================================================
    // FIX 3: VIEWPORT BADGES (No Flickering)
    // =========================================================================

    function fixViewportBadges() {
        log('Fixing viewport badges (no flicker)...');

        // Initial update after delay
        setTimeout(updateBadgesIfChanged, 3000);

        // Periodic check - but ONLY update if state changed
        setInterval(() => {
            if (!badgeUpdatePending) {
                badgeUpdatePending = true;
                requestAnimationFrame(() => {
                    updateBadgesIfChanged();
                    badgeUpdatePending = false;
                });
            }
        }, 5000);

        // Expose refresh function
        window.DICOM_VIEWER.refreshAllViewportBadges = updateBadgesIfChanged;

        log('✅ Viewport badges fixed');
    }

    function updateBadgesIfChanged() {
        const state = window.DICOM_VIEWER?.STATE;
        if (!state?.currentSeriesImages) return;

        // Build current state
        const currentState = buildBadgeState();

        // Compare with cache
        if (currentState === badgeStateCache) {
            return; // No change, don't touch DOM
        }

        log('Badge state changed, updating DOM...');
        badgeStateCache = currentState;

        // Parse state and update badges
        const stateMap = new Map();
        if (currentState) {
            currentState.split('|').forEach(entry => {
                const [fileId, vpNum] = entry.split(':');
                if (fileId && vpNum) {
                    if (!stateMap.has(fileId)) stateMap.set(fileId, []);
                    stateMap.get(fileId).push(parseInt(vpNum));
                }
            });
        }

        // Clear all badges first
        document.querySelectorAll('.vp-badge-container-v5').forEach(c => c.remove());

        // Show all VIEW badges
        document.querySelectorAll('.viewport-badges').forEach(el => el.style.display = '');

        // Add new badges
        stateMap.forEach((vpNums, fileId) => {
            addBadgeToThumbnail(fileId, vpNums);
        });
    }

    function buildBadgeState() {
        const state = window.DICOM_VIEWER?.STATE;
        if (!state?.currentSeriesImages) return '';

        const entries = [];
        const viewports = document.querySelectorAll('.viewport');

        viewports.forEach((viewport, index) => {
            try {
                const enabledElement = cornerstone.getEnabledElement(viewport);
                if (!enabledElement?.image) return;

                const imageId = enabledElement.image.imageId;

                for (const img of state.currentSeriesImages) {
                    const matches = imageId.includes(img.id) ||
                        (img.orthancInstanceId && imageId.includes(img.orthancInstanceId)) ||
                        (img.instanceId && imageId.includes(img.instanceId));

                    if (matches) {
                        entries.push(`${img.id}:${index + 1}`);
                        break;
                    }
                }
            } catch (e) { }
        });

        return entries.sort().join('|');
    }

    function addBadgeToThumbnail(fileId, vpNums) {
        const seriesItem = document.querySelector(`.series-item[data-file-id="${fileId}"]`);
        if (!seriesItem) return;

        const thumbnailDiv = seriesItem.querySelector('.series-thumbnail');
        if (!thumbnailDiv) return;

        thumbnailDiv.style.position = 'relative';

        // Create container
        const container = document.createElement('div');
        container.className = 'vp-badge-container-v5';
        container.style.cssText = `
            position: absolute;
            top: 4px;
            left: 4px;
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            z-index: 20;
            pointer-events: none;
        `;

        // Add badges
        vpNums.forEach(vpNum => {
            const badge = document.createElement('span');
            badge.className = 'vp-badge-v5';
            badge.style.cssText = `
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                color: white;
                font-size: 10px;
                font-weight: 700;
                padding: 3px 7px;
                border-radius: 4px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.6);
                text-shadow: 0 1px 2px rgba(0,0,0,0.4);
                display: inline-block;
                line-height: 1;
            `;
            badge.textContent = `VP${vpNum}`;
            container.appendChild(badge);
        });

        thumbnailDiv.appendChild(container);

        // Hide VIEW badge
        const viewportBadges = seriesItem.querySelector('.viewport-badges');
        if (viewportBadges) viewportBadges.style.display = 'none';
    }

    // =========================================================================
    // FIX 4: DRAG AND DROP
    // =========================================================================

    function fixDragDrop() {
        log('Fixing drag-drop...');

        setupAllDropTargets();

        // Watch for new viewports
        const observer = new MutationObserver((mutations) => {
            let hasNewViewports = false;
            mutations.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (node.nodeType === 1) {
                        if (node.classList?.contains('viewport')) hasNewViewports = true;
                        if (node.querySelectorAll?.('.viewport').length > 0) hasNewViewports = true;
                    }
                });
            });
            if (hasNewViewports) {
                setTimeout(setupAllDropTargets, 200);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });

        log('✅ Drag-drop fixed');
    }

    function setupAllDropTargets() {
        document.querySelectorAll('.viewport').forEach((viewport, index) => {
            setupDropTarget(viewport, index + 1);
        });
    }

    function setupDropTarget(viewport, num) {
        if (viewport.dataset.dropV5 === 'true') return;
        viewport.dataset.dropV5 = 'true';

        // Canvas forwarding
        const setupCanvas = () => {
            const canvas = viewport.querySelector('canvas');
            if (!canvas || canvas.dataset.fwdV5 === 'true') return;
            canvas.dataset.fwdV5 = 'true';

            canvas.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                viewport.style.boxShadow = '0 0 30px rgba(13, 110, 253, 0.9)';
                viewport.style.border = '3px dashed #0d6efd';
            }, { capture: true });

            canvas.addEventListener('dragleave', (e) => {
                if (!viewport.contains(e.relatedTarget)) {
                    resetDropStyle(viewport);
                }
            });

            canvas.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                resetDropStyle(viewport);
                handleDrop(viewport, e);
            }, { capture: true });
        };

        setupCanvas();
        new MutationObserver(setupCanvas).observe(viewport, { childList: true, subtree: true });

        // Viewport events
        viewport.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            viewport.style.boxShadow = '0 0 30px rgba(13, 110, 253, 0.9)';
            viewport.style.border = '3px dashed #0d6efd';
        }, { capture: true });

        viewport.addEventListener('dragleave', (e) => {
            if (!viewport.contains(e.relatedTarget)) {
                resetDropStyle(viewport);
            }
        });

        viewport.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            resetDropStyle(viewport);
            handleDrop(viewport, e);
        }, { capture: true });
    }

    function resetDropStyle(viewport) {
        if (selectedViewports.has(viewport.id)) {
            applySelectedStyle(viewport);
        } else {
            applyDeselectedStyle(viewport);
        }
    }

    function handleDrop(viewport, e) {
        let dragData = window.DICOM_DRAG_DATA;
        if (!dragData) {
            try {
                const text = e.dataTransfer.getData('text/plain');
                if (text) dragData = JSON.parse(text);
            } catch (err) { }
        }

        if (dragData && window.DICOM_VIEWER.EventHandlers) {
            if (dragData.type === 'series-image') {
                window.DICOM_VIEWER.EventHandlers.handleSeriesImageDrop(viewport, dragData);
            } else if (dragData.type === 'viewport-image') {
                window.DICOM_VIEWER.EventHandlers.handleViewportImageDrop(viewport, dragData);
            }
        }
    }

    // =========================================================================
    // STYLES
    // =========================================================================

    function injectStyles() {
        const style = document.createElement('style');
        style.id = 'dicom-fix-v5-styles';
        style.textContent = `
            /* Hide ACTIVE text indicator */
            .active-status-indicator {
                display: none !important;
            }
            
            /* Selected viewport - Yellow border */
            .viewport-selected-v5 {
                border: 3px solid #ffc107 !important;
                box-shadow: 0 0 20px rgba(255, 193, 7, 0.7) !important;
            }
            
            /* Override any blue active styling */
            .viewport.active {
                border: 1px solid #444 !important;
                box-shadow: none !important;
            }
            
            .viewport.active.viewport-selected-v5 {
                border: 3px solid #ffc107 !important;
                box-shadow: 0 0 20px rgba(255, 193, 7, 0.7) !important;
            }
            
            /* Drop visual feedback */
            .viewport.drag-over {
                border: 3px dashed #0d6efd !important;
                box-shadow: 0 0 30px rgba(13, 110, 253, 0.9) !important;
            }
            
            /* Empty viewport indicator */
            .empty-viewport-indicator, .empty-viewport-indicator * {
                pointer-events: none !important;
            }
            
            /* Badge container - no animations */
            .vp-badge-container-v5 {
                position: absolute !important;
                top: 4px !important;
                left: 4px !important;
                z-index: 20 !important;
            }
            
            /* Hide VIEW when badge present */
            .series-item:has(.vp-badge-container-v5) .viewport-badges {
                display: none !important;
            }
            
            /* Green left border when has badge */
            .series-item:has(.vp-badge-container-v5) {
                border-left: 3px solid #28a745 !important;
            }
        `;
        document.head.appendChild(style);
    }

    // =========================================================================
    // START
    // =========================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => waitForDependencies(initialize));
    } else {
        waitForDependencies(initialize);
    }

})();
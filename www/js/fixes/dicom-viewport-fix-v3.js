/**
 * DICOM Viewer Fix v3.0: Empty Viewport Drag-Drop + Sidebar Viewport Badges
 * WITH TERMINAL LOGGING FOR ELECTRON
 * 
 * INSTALLATION:
 * 1. Save this file to: www/js/fixes/dicom-viewport-fix-v3.js
 * 2. Create the fixes folder if it doesn't exist
 * 3. Add to index.html BEFORE </body>:
 *    <script src="js/fixes/dicom-viewport-fix-v3.js"></script>
 */

(function () {
    'use strict';

    // Terminal logging - works in Electron with npm start
    const log = (...args) => {
        const msg = ['[DragDropFix]', ...args].join(' ');
        console.log(msg);
    };

    const warn = (...args) => console.warn('[DragDropFix WARNING]', ...args);
    const error = (...args) => console.error('[DragDropFix ERROR]', ...args);

    log('='.repeat(60));
    log('🔧 DICOM Viewport Fix v3.0 Loading...');
    log('='.repeat(60));

    // Wait for dependencies
    function waitForDependencies(callback, maxAttempts = 20) {
        let attempts = 0;

        const check = () => {
            attempts++;

            const ready = window.DICOM_VIEWER &&
                window.cornerstone &&
                window.DICOM_VIEWER.EventHandlers &&
                window.DICOM_VIEWER.STATE;

            if (ready) {
                log('✅ All dependencies ready!');
                callback();
            } else if (attempts < maxAttempts) {
                setTimeout(check, 500);
            } else {
                error('Dependencies not found after max attempts!');
            }
        };

        check();
    }

    // Main initialization
    function initialize() {
        log('Starting initialization...');

        fixEventHandlers();
        fixViewportDropTargets();
        setupViewportBadgeSystem();
        setupMutationObserver();

        log('='.repeat(60));
        log('✅ DICOM Viewport Fix v3.0 Fully Initialized');
        log('='.repeat(60));
    }

    /**
     * FIX 1: Override event handlers for better empty viewport support
     */
    function fixEventHandlers() {
        log('Fixing event handlers...');

        const eventHandlers = window.DICOM_VIEWER.EventHandlers;

        // Override ensureViewportEnabled with robust version
        eventHandlers.ensureViewportEnabled = async function (viewport) {
            log('ensureViewportEnabled called for:', viewport.id || viewport.dataset.viewportName);

            for (let attempt = 1; attempt <= 5; attempt++) {
                log(`  Attempt ${attempt}/5...`);

                // Check if already enabled
                try {
                    const enabled = cornerstone.getEnabledElement(viewport);
                    if (enabled) {
                        log('  ✅ Already enabled');
                        return true;
                    }
                } catch (e) { }

                // Check dimensions
                if (viewport.offsetWidth === 0 || viewport.offsetHeight === 0) {
                    log('  ⚠️ No dimensions, waiting...');
                    await new Promise(r => setTimeout(r, 100));
                    continue;
                }

                // Remove blocking elements
                const emptyIndicator = viewport.querySelector('.empty-viewport-indicator');
                if (emptyIndicator) {
                    log('  Removing empty indicator...');
                    emptyIndicator.remove();
                }

                // Try to enable
                try {
                    log('  Calling cornerstone.enable()...');
                    cornerstone.enable(viewport);
                    await new Promise(r => setTimeout(r, 100));

                    // Verify
                    try {
                        cornerstone.getEnabledElement(viewport);
                        log('  ✅ Enabled successfully!');
                        return true;
                    } catch (verifyErr) {
                        log('  Verification failed, retrying...');
                    }
                } catch (enableErr) {
                    warn(`  Enable failed: ${enableErr.message}`);
                }

                await new Promise(r => setTimeout(r, 150 * attempt));
            }

            error('Failed to enable viewport after all attempts');
            return false;
        };

        // Override handleSeriesImageDrop
        eventHandlers.handleSeriesImageDrop = async function (viewport, data) {
            log('');
            log('='.repeat(50));
            log('handleSeriesImageDrop CALLED');
            log('='.repeat(50));
            log('Viewport:', viewport.id || viewport.dataset.viewportName);
            log('Data:', JSON.stringify(data));

            const state = window.DICOM_VIEWER.STATE;
            const imageIndex = parseInt(data.imageIndex);

            log(`Image index: ${imageIndex}, Total: ${state.currentSeriesImages?.length || 0}`);

            // Validate
            if (isNaN(imageIndex) || imageIndex < 0 ||
                !state.currentSeriesImages ||
                imageIndex >= state.currentSeriesImages.length) {
                error('Invalid image index or no images!');
                this.showMessage('Invalid image');
                return;
            }

            const loadingDiv = this.showViewportLoading(viewport);

            try {
                // STEP 1: Remove empty indicator
                log('STEP 1: Remove empty indicator');
                const emptyIndicator = viewport.querySelector('.empty-viewport-indicator');
                if (emptyIndicator) {
                    emptyIndicator.remove();
                    log('  Removed');
                }
                viewport.dataset.isEmpty = 'false';

                // STEP 2: Wait for dimensions if needed
                log('STEP 2: Check dimensions');
                if (viewport.offsetWidth === 0 || viewport.offsetHeight === 0) {
                    log('  Waiting for dimensions...');
                    await new Promise(r => setTimeout(r, 150));
                }
                log(`  Dimensions: ${viewport.offsetWidth}x${viewport.offsetHeight}`);

                // STEP 3: Enable viewport
                log('STEP 3: Enable viewport');
                const enableSuccess = await this.ensureViewportEnabled(viewport);
                if (!enableSuccess) {
                    throw new Error('Could not enable viewport');
                }

                // STEP 4: Get image info and build ID
                log('STEP 4: Build image ID');
                const imageInfo = state.currentSeriesImages[imageIndex];
                let imageId = await this.buildImageId(imageInfo, data.fileId);
                log(`  Image ID: ${imageId ? imageId.substring(0, 80) + '...' : 'NULL'}`);

                if (!imageId) {
                    throw new Error('Could not build image ID');
                }

                // STEP 5: Load image
                log('STEP 5: Load image');
                const image = await cornerstone.loadImage(imageId);
                log(`  Loaded: ${image.width}x${image.height}`);

                // STEP 6: Display image
                log('STEP 6: Display image');
                await cornerstone.displayImage(viewport, image);

                try { cornerstone.fitToWindow(viewport); } catch (e) { }
                cornerstone.updateImage(viewport);

                // Set active
                if (window.DICOM_VIEWER.MANAGERS.viewportManager) {
                    window.DICOM_VIEWER.MANAGERS.viewportManager.setActiveViewport(viewport);
                }

                this.hideViewportLoading(loadingDiv);

                // Get viewport number
                const viewports = document.querySelectorAll('.viewport');
                const viewportNum = Array.from(viewports).indexOf(viewport) + 1;

                log('='.repeat(50));
                log(`✅ SUCCESS! Image ${imageIndex + 1} -> Viewport ${viewportNum}`);
                log('='.repeat(50));

                this.showMessage(`Image ${imageIndex + 1} loaded in Viewport ${viewportNum}`);

                // Update badge
                updateViewportBadgeOnThumbnail(imageInfo.id, viewportNum);

                viewport.classList.add('drop-success');
                setTimeout(() => viewport.classList.remove('drop-success'), 500);

            } catch (err) {
                error('DROP FAILED:', err.message);
                this.hideViewportLoading(loadingDiv);
                this.showMessage('Failed: ' + err.message);
                createEmptyIndicator(viewport);
            }
        };

        log('✅ Event handlers fixed');
    }

    /**
     * FIX 2: Setup all viewports as proper drop targets
     */
    function fixViewportDropTargets() {
        log('Fixing viewport drop targets...');

        const viewports = document.querySelectorAll('.viewport');
        log(`Found ${viewports.length} viewports`);

        viewports.forEach((viewport, index) => {
            setupViewportDropTarget(viewport, index + 1);
        });

        log('✅ Viewport drop targets fixed');
    }

    /**
     * Setup a single viewport as drop target
     */
    function setupViewportDropTarget(viewport, viewportNum) {
        const vpName = viewport.id || viewport.dataset.viewportName || `viewport-${viewportNum}`;

        if (viewport.dataset.dropFixApplied === 'true') return;
        viewport.dataset.dropFixApplied = 'true';
        viewport.style.position = 'relative';

        // Setup canvas event forwarding
        setupCanvasForwarding(viewport, vpName);

        // Viewport drop handlers with capture phase
        viewport.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'copy';

            viewport.classList.add('drag-over');
            viewport.style.boxShadow = '0 0 30px rgba(13, 110, 253, 0.9), inset 0 0 50px rgba(13, 110, 253, 0.2)';
            viewport.style.border = '3px dashed #0d6efd';
        }, { capture: true });

        viewport.addEventListener('dragleave', (e) => {
            if (!viewport.contains(e.relatedTarget)) {
                viewport.classList.remove('drag-over');
                viewport.style.boxShadow = '';
                viewport.style.border = '';
            }
        });

        viewport.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();

            log(`[${vpName}] DROP event!`);

            viewport.classList.remove('drag-over');
            viewport.style.boxShadow = '';
            viewport.style.border = '';

            let dragData = window.DICOM_DRAG_DATA;
            if (!dragData) {
                try {
                    const textData = e.dataTransfer.getData('text/plain');
                    if (textData && textData.startsWith('{')) {
                        dragData = JSON.parse(textData);
                    }
                } catch (err) { }
            }

            if (!dragData) {
                error('No valid drag data!');
                return;
            }

            log(`Processing: ${dragData.type}`);

            if (dragData.type === 'series-image') {
                window.DICOM_VIEWER.EventHandlers.handleSeriesImageDrop(viewport, dragData);
            } else if (dragData.type === 'viewport-image') {
                window.DICOM_VIEWER.EventHandlers.handleViewportImageDrop(viewport, dragData);
            }
        }, { capture: true });

        log(`  ${vpName} configured`);
    }

    /**
     * Setup canvas event forwarding
     */
    function setupCanvasForwarding(viewport, vpName) {
        const setup = () => {
            const canvas = viewport.querySelector('canvas');
            if (!canvas || canvas.dataset.dropForwardingV3 === 'true') return;

            canvas.dataset.dropForwardingV3 = 'true';
            log(`  Canvas forwarding for ${vpName}`);

            canvas.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                viewport.classList.add('drag-over');
                viewport.style.boxShadow = '0 0 30px rgba(13, 110, 253, 0.9)';
                viewport.style.border = '3px dashed #0d6efd';
            }, { capture: true });

            canvas.addEventListener('dragleave', (e) => {
                if (!viewport.contains(e.relatedTarget)) {
                    viewport.classList.remove('drag-over');
                    viewport.style.boxShadow = '';
                    viewport.style.border = '';
                }
            });

            canvas.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();

                log(`  Canvas DROP -> ${vpName}`);

                viewport.classList.remove('drag-over');
                viewport.style.boxShadow = '';
                viewport.style.border = '';

                let dragData = window.DICOM_DRAG_DATA;
                if (!dragData) {
                    try {
                        const textData = e.dataTransfer.getData('text/plain');
                        if (textData) dragData = JSON.parse(textData);
                    } catch (err) { }
                }

                if (dragData && window.DICOM_VIEWER.EventHandlers) {
                    if (dragData.type === 'series-image') {
                        window.DICOM_VIEWER.EventHandlers.handleSeriesImageDrop(viewport, dragData);
                    } else if (dragData.type === 'viewport-image') {
                        window.DICOM_VIEWER.EventHandlers.handleViewportImageDrop(viewport, dragData);
                    }
                }
            }, { capture: true });
        };

        setup();

        // Observe for canvas changes
        const observer = new MutationObserver(() => setup());
        observer.observe(viewport, { childList: true, subtree: true });
    }

    /**
     * FIX 3: Viewport badge system
     */
    function setupViewportBadgeSystem() {
        log('Setting up viewport badge system...');

        window.DICOM_VIEWER.refreshAllViewportBadges = refreshAllBadges;

        setTimeout(refreshAllBadges, 2000);
        setInterval(refreshAllBadges, 4000);

        document.addEventListener('cornerstoneimagerendered', () => {
            setTimeout(refreshAllBadges, 200);
        });

        log('✅ Badge system ready');
    }

    /**
     * Update badge on thumbnail
     */
    function updateViewportBadgeOnThumbnail(fileId, viewportNum) {
        const seriesItem = document.querySelector(`.series-item[data-file-id="${fileId}"]`);
        if (!seriesItem) return;

        // Find thumbnail
        const thumbnailDiv = seriesItem.querySelector('.series-thumbnail');
        if (!thumbnailDiv) return;

        thumbnailDiv.style.position = 'relative';

        // Get or create badge container
        let badgeContainer = thumbnailDiv.querySelector('.vp-badge-container');
        if (!badgeContainer) {
            badgeContainer = document.createElement('div');
            badgeContainer.className = 'vp-badge-container';
            badgeContainer.style.cssText = `
                position: absolute;
                top: 4px;
                left: 4px;
                display: flex;
                gap: 3px;
                flex-wrap: wrap;
                z-index: 20;
                pointer-events: none;
            `;
            thumbnailDiv.appendChild(badgeContainer);
        }

        // Check if badge exists
        if (!badgeContainer.querySelector(`[data-vp="${viewportNum}"]`)) {
            const badge = document.createElement('span');
            badge.className = 'vp-badge';
            badge.dataset.vp = viewportNum;
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
            badge.textContent = `VP${viewportNum}`;
            badgeContainer.appendChild(badge);
        }

        // Hide old VIEW badge
        const viewportBadges = seriesItem.querySelector('.viewport-badges');
        if (viewportBadges) viewportBadges.style.display = 'none';
    }

    /**
     * Refresh all badges
     */
    function refreshAllBadges() {
        const state = window.DICOM_VIEWER?.STATE;
        if (!state?.currentSeriesImages) return;

        // Clear existing badges
        document.querySelectorAll('.vp-badge-container').forEach(c => c.innerHTML = '');

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
                        updateViewportBadgeOnThumbnail(img.id, index + 1);
                        break;
                    }
                }
            } catch (e) { }
        });
    }

    /**
     * Create empty indicator
     */
    function createEmptyIndicator(viewport) {
        const existing = viewport.querySelector('.empty-viewport-indicator');
        if (existing) existing.remove();

        const indicator = document.createElement('div');
        indicator.className = 'empty-viewport-indicator';
        indicator.style.cssText = `
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(26, 26, 46, 0.95);
            pointer-events: none;
            z-index: 5;
        `;
        indicator.innerHTML = `
            <i class="bi bi-plus-circle" style="font-size: 40px; color: #0d6efd; opacity: 0.7;"></i>
            <span style="font-size: 13px; color: #888; margin-top: 10px;">Drop image here</span>
        `;
        viewport.appendChild(indicator);
        viewport.dataset.isEmpty = 'true';
    }

    /**
     * Mutation observer for new viewports
     */
    function setupMutationObserver() {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType !== 1) return;

                    if (node.classList?.contains('viewport') && !node.dataset.dropFixApplied) {
                        const viewports = document.querySelectorAll('.viewport');
                        setupViewportDropTarget(node, Array.from(viewports).indexOf(node) + 1);
                    }

                    node.querySelectorAll?.('.viewport').forEach((vp) => {
                        if (!vp.dataset.dropFixApplied) {
                            const viewports = document.querySelectorAll('.viewport');
                            setupViewportDropTarget(vp, Array.from(viewports).indexOf(vp) + 1);
                        }
                    });
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    // Inject CSS
    const style = document.createElement('style');
    style.textContent = `
        .viewport.drag-over {
            border: 3px dashed #0d6efd !important;
            box-shadow: 0 0 30px rgba(13, 110, 253, 0.9), inset 0 0 50px rgba(13, 110, 253, 0.15) !important;
        }
        
        .viewport.drop-success {
            animation: dropSuccessPulse 0.5s ease-out;
        }
        
        @keyframes dropSuccessPulse {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.8); }
            50% { box-shadow: 0 0 25px 15px rgba(40, 167, 69, 0.5); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        
        .empty-viewport-indicator, .empty-viewport-indicator * {
            pointer-events: none !important;
        }
        
        .vp-badge-container {
            position: absolute !important;
            top: 4px !important;
            left: 4px !important;
            z-index: 20 !important;
        }
        
        .vp-badge {
            animation: badgePop 0.3s ease;
        }
        
        @keyframes badgePop {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .series-item:has(.vp-badge) .viewport-badges {
            display: none !important;
        }
        
        .series-item:has(.vp-badge) {
            border-left: 3px solid #28a745 !important;
        }
    `;
    document.head.appendChild(style);

    // Start
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => waitForDependencies(initialize));
    } else {
        waitForDependencies(initialize);
    }

})();
/**
 * Custom Grid Layout Manager
 * Handles dynamic grid layouts with Excel-style selector
 * Supports 1x1 up to 5x5 grids (max 25 viewports)
 * Mobile responsive with max 5 viewports on mobile
 */

window.DICOM_VIEWER.CustomGridLayoutManager = class {
    constructor() {
        this.maxGridSize = 5; // 5x5 = 25 viewports max
        this.mobileMaxViewports = 5;
        this.currentCustomGrid = null;
        this.initialized = false;
    }

    initialize() {
        if (this.initialized) return;

        console.log('Initializing Custom Grid Layout Manager');
        this.createGridSelector();
        this.setupEventListeners();
        this.initialized = true;
    }

    /**
     * Create Excel-style grid selector UI
     */
    createGridSelector() {
        const layoutControlsContainer = document.querySelector('.mpr-controls .controls-group-left .control-group');

        if (!layoutControlsContainer) {
            console.error('Layout controls container not found');
            return;
        }

        // Create custom grid button
        const customGridBtn = document.createElement('button');
        customGridBtn.type = 'button';
        customGridBtn.className = 'btn btn-sm btn-secondary';
        customGridBtn.id = 'customGridBtn';
        customGridBtn.innerHTML = '<i class="bi bi-grid-3x3-gap"></i>';
        customGridBtn.title = 'Custom Grid Layout';

        // Add to existing button group
        const btnGroup = layoutControlsContainer.querySelector('.btn-group');
        if (btnGroup) {
            btnGroup.appendChild(customGridBtn);
        }

        // Create grid selector popup
        this.createGridSelectorPopup();
    }

    /**
     * Create the Excel-style grid selector popup
     */
    createGridSelectorPopup() {
        const popup = document.createElement('div');
        popup.id = 'gridSelectorPopup';
        popup.className = 'grid-selector-popup';
        popup.style.cssText = `
            position: fixed;
            display: none;
            background: #1c2128;
            border: 2px solid #0d6efd;
            border-radius: 8px;
            padding: 15px;
            z-index: 9999;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        `;

        // Create title
        const title = document.createElement('div');
        title.className = 'grid-selector-title';
        title.textContent = 'Select Grid Size';
        title.style.cssText = 'color: #fff; font-weight: bold; margin-bottom: 10px; text-align: center;';
        popup.appendChild(title);

        // Create grid container
        const gridContainer = document.createElement('div');
        gridContainer.id = 'gridSelectorGrid';
        gridContainer.className = 'grid-selector-grid';
        gridContainer.style.cssText = `
            display: grid;
            grid-template-columns: repeat(${this.maxGridSize}, 30px);
            grid-template-rows: repeat(${this.maxGridSize}, 30px);
            gap: 2px;
            margin-bottom: 10px;
        `;

        // Create grid cells
        for (let row = 0; row < this.maxGridSize; row++) {
            for (let col = 0; col < this.maxGridSize; col++) {
                const cell = document.createElement('div');
                cell.className = 'grid-cell';
                cell.dataset.row = row + 1;
                cell.dataset.col = col + 1;
                cell.style.cssText = `
                    background: #2d3748;
                    border: 1px solid #4a5568;
                    cursor: pointer;
                    transition: all 0.2s;
                `;

                cell.addEventListener('mouseenter', (e) => this.highlightCells(e));
                cell.addEventListener('click', (e) => this.selectGrid(e));

                gridContainer.appendChild(cell);
            }
        }

        popup.appendChild(gridContainer);

        // Create selection label
        const label = document.createElement('div');
        label.id = 'gridSelectionLabel';
        label.className = 'grid-selection-label';
        label.textContent = 'Hover to preview';
        label.style.cssText = 'color: #0d6efd; text-align: center; font-size: 14px; font-weight: bold;';
        popup.appendChild(label);

        document.body.appendChild(popup);
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        const customGridBtn = document.getElementById('customGridBtn');
        if (customGridBtn) {
            customGridBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleGridSelector();
            });
        }

        // Close popup when clicking outside
        document.addEventListener('click', (e) => {
            const popup = document.getElementById('gridSelectorPopup');
            const customGridBtn = document.getElementById('customGridBtn');

            if (popup && !popup.contains(e.target) && e.target !== customGridBtn) {
                popup.style.display = 'none';
            }
        });
    }

    /**
     * Toggle grid selector popup visibility
     */
    toggleGridSelector() {
        const popup = document.getElementById('gridSelectorPopup');
        const button = document.getElementById('customGridBtn');

        if (!popup || !button) return;

        const isVisible = popup.style.display === 'block';

        if (isVisible) {
            popup.style.display = 'none';
        } else {
            // Position popup near button
            const rect = button.getBoundingClientRect();
            popup.style.left = `${rect.left}px`;
            popup.style.top = `${rect.bottom + 10}px`;
            popup.style.display = 'block';
        }
    }

    /**
     * Highlight cells on hover
     */
    highlightCells(event) {
        const cell = event.target;
        const rows = parseInt(cell.dataset.row);
        const cols = parseInt(cell.dataset.col);

        // Check mobile viewport count limit
        const totalViewports = rows * cols;
        const isMobile = window.innerWidth < 768;

        if (isMobile && totalViewports > this.mobileMaxViewports) {
            this.updateSelectionLabel(`Mobile max: ${this.mobileMaxViewports} viewports`);
            return;
        }

        const allCells = document.querySelectorAll('.grid-cell');

        allCells.forEach(c => {
            const cellRow = parseInt(c.dataset.row);
            const cellCol = parseInt(c.dataset.col);

            if (cellRow <= rows && cellCol <= cols) {
                c.style.background = '#0d6efd';
                c.style.borderColor = '#0d6efd';
            } else {
                c.style.background = '#2d3748';
                c.style.borderColor = '#4a5568';
            }
        });

        this.updateSelectionLabel(`${rows} × ${cols} (${totalViewports} viewports)`);
    }

    /**
     * Update selection label
     */
    updateSelectionLabel(text) {
        const label = document.getElementById('gridSelectionLabel');
        if (label) {
            label.textContent = text;
        }
    }

    /**
     * Select grid and apply layout
     */
    selectGrid(event) {
        const cell = event.target;
        const rows = parseInt(cell.dataset.row);
        const cols = parseInt(cell.dataset.col);
        const totalViewports = rows * cols;

        // Check mobile limit
        const isMobile = window.innerWidth < 768;
        if (isMobile && totalViewports > this.mobileMaxViewports) {
            alert(`Mobile devices are limited to ${this.mobileMaxViewports} viewports`);
            return;
        }

        console.log(`Selected grid: ${rows}x${cols} (${totalViewports} viewports)`);

        // Hide popup
        const popup = document.getElementById('gridSelectorPopup');
        if (popup) popup.style.display = 'none';

        // Apply custom grid layout
        this.applyCustomGrid(rows, cols);
    }

    /**
     * Apply custom grid layout
     */
    applyCustomGrid(rows, cols) {
        const viewportManager = window.DICOM_VIEWER.MANAGERS.viewportManager;
        if (!viewportManager) {
            console.error('Viewport manager not found');
            return;
        }

        this.currentCustomGrid = { rows, cols };

        // Generate viewport names
        const viewportNames = [];
        for (let i = 0; i < rows * cols; i++) {
            viewportNames.push(`viewport-${i + 1}`);
        }

        // Add custom layout to layouts
        const layoutKey = `custom-${rows}x${cols}`;
        viewportManager.layouts[layoutKey] = {
            rows: rows,
            cols: cols,
            viewports: viewportNames
        };

        // Create viewports using switchLayout to ensure image preservation
        // viewportManager.createViewports(layoutKey);
        viewportManager.switchLayout(layoutKey);

        // Update CSS for responsive grid
        this.updateGridCSS(rows, cols);

        // Update button states
        this.updateLayoutButtonStates();

        // CRITICAL: Dispatch layout changed event to recalculate pages
        // This ensures the page navigator updates when switching layouts
        document.dispatchEvent(new CustomEvent('layoutChanged', {
            detail: {
                rows,
                cols,
                spots: rows * cols,
                source: 'applyCustomGrid'
            }
        }));

        // DIRECT CALL: Force page navigator to recalculate after viewports are created
        // This is more reliable than events which may have timing issues
        setTimeout(() => {
            const pageNavigator = window.DICOM_VIEWER?.MANAGERS?.pageNavigator;
            if (pageNavigator) {
                console.log('Direct call to pageNavigator.refresh() after layout change');
                pageNavigator.currentPage = 1; // Reset to first page
                pageNavigator.refresh();
            }
        }, 400); // Wait for viewports to be fully created

        console.log(`Applied custom grid: ${rows}x${cols}`);
    }

    /**
     * Update CSS for custom grid layout
     */
    updateGridCSS(rows, cols) {
        const container = document.getElementById('viewport-container');
        if (!container) return;

        const spots = rows * cols;

        // Remove all layout classes
        container.className = container.className
            .split(' ')
            .filter(c => !c.startsWith('layout-'))
            .join(' ');

        // Add new layout-spots class if it exists, otherwise apply inline styles
        const spotsClass = `layout-spots-${spots}`;
        container.classList.add(spotsClass);

        // Apply grid template as fallback
        container.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
        container.style.gridTemplateRows = `repeat(${rows}, 1fr)`;
        container.style.gap = '2px';

        // Portrait layout detection: more rows than columns = portrait
        // For portrait layouts, constrain the width to give each viewport a portrait aspect ratio
        const isPortraitLayout = rows > cols;
        if (isPortraitLayout) {
            // Calculate appropriate width based on number of columns
            // Fewer columns = narrower container
            const widthPercent = Math.min(80, 25 * cols); // 25% per column, max 80%
            container.style.maxWidth = `${widthPercent}%`;
            container.style.margin = '0 auto'; // Center the container
            container.classList.add('portrait-layout');
        } else {
            // Landscape or square layout - use full width
            container.style.maxWidth = '';
            container.style.margin = '';
            container.classList.remove('portrait-layout');
        }

        // Update dropdown text if it exists
        const dropdownText = document.getElementById('layoutDropdownText');
        if (dropdownText) {
            dropdownText.textContent = spots + (spots === 1 ? ' Spot' : ' Spots');
        }

        // Update dropdown active state
        document.querySelectorAll('.layout-dropdown-menu .dropdown-item').forEach(item => {
            item.classList.toggle('active', parseInt(item.dataset.spots) === spots);
        });

        // Save preference
        localStorage.setItem('layoutSpots', spots.toString());
    }

    /**
     * Update layout button states
     */
    updateLayoutButtonStates() {
        // Deactivate all layout buttons
        document.querySelectorAll('[data-layout]').forEach(btn => {
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-secondary');
        });

        // Activate custom grid button
        const customBtn = document.getElementById('customGridBtn');
        if (customBtn) {
            customBtn.classList.remove('btn-secondary');
            customBtn.classList.add('btn-primary');
        }
    }

    /**
     * Calculate optimal grid for given number of images
     * Automatically adapts to screen orientation
     */
    calculateOptimalGrid(imageCount) {
        if (imageCount <= 0) return { rows: 1, cols: 1 };

        const isMobile = window.innerWidth < 768;
        const maxViewports = isMobile ? this.mobileMaxViewports : (this.maxGridSize * this.maxGridSize);

        if (imageCount > maxViewports) {
            imageCount = maxViewports;
        }

        // Check orientation
        const isLandscape = window.innerWidth > window.innerHeight;
        const isPortrait = window.innerHeight > window.innerWidth;

        // Predefined optimal layouts for common image counts
        const portraitOptimal = {
            2: { rows: 2, cols: 1 },
            3: { rows: 3, cols: 1 },
            4: { rows: 2, cols: 2 },
            5: { rows: 3, cols: 2 },
            6: { rows: 3, cols: 2 },
            8: { rows: 4, cols: 2 },
            9: { rows: 3, cols: 3 },
            10: { rows: 5, cols: 2 },
            12: { rows: 4, cols: 3 },
            15: { rows: 5, cols: 3 }
        };

        const landscapeOptimal = {
            2: { rows: 1, cols: 2 },
            3: { rows: 1, cols: 3 },
            4: { rows: 2, cols: 2 },
            5: { rows: 2, cols: 3 },
            6: { rows: 2, cols: 3 },
            8: { rows: 2, cols: 4 },
            9: { rows: 3, cols: 3 },
            10: { rows: 2, cols: 5 },
            12: { rows: 3, cols: 4 },
            15: { rows: 3, cols: 5 }
        };

        // Check for predefined optimal layouts
        if (isPortrait && portraitOptimal[imageCount]) {
            return portraitOptimal[imageCount];
        }
        if (isLandscape && landscapeOptimal[imageCount]) {
            return landscapeOptimal[imageCount];
        }

        // Calculate square root for fallback
        const sqrt = Math.sqrt(imageCount);
        let cols = Math.ceil(sqrt);
        let rows = Math.ceil(imageCount / cols);

        // Optimize for screen orientation
        if (isLandscape && rows > cols) {
            [rows, cols] = [cols, rows]; // Swap to prefer landscape
        }
        if (isPortrait && cols > rows) {
            [rows, cols] = [cols, rows]; // Swap to prefer portrait
        }

        // Limit to max grid size
        cols = Math.min(cols, this.maxGridSize);
        rows = Math.min(rows, this.maxGridSize);

        return { rows, cols };
    }

    /**
     * Get optimal layout for printing based on paper orientation
     * @param {number} spots - Number of viewport spots
     * @param {string} paperOrientation - 'portrait' or 'landscape'
     * @returns {Object} Layout configuration
     */
    getOptimalLayoutForPrint(spots, paperOrientation = 'portrait') {
        const isPortrait = paperOrientation === 'portrait';

        // Optimal layouts for printing (based on medical imaging standards)
        // Portrait paper: more rows than columns
        // Landscape paper: more columns than rows
        const printLayouts = {
            portrait: {
                1: { rows: 1, cols: 1, layout: '1x1' },
                2: { rows: 2, cols: 1, layout: '2x1' },
                3: { rows: 3, cols: 1, layout: '3x1' },
                4: { rows: 2, cols: 2, layout: '2x2' },
                5: { rows: 3, cols: 2, layout: '3x2' },
                6: { rows: 3, cols: 2, layout: '3x2' },
                8: { rows: 4, cols: 2, layout: '4x2' },
                9: { rows: 3, cols: 3, layout: '3x3' },
                12: { rows: 4, cols: 3, layout: '4x3' },
                15: { rows: 5, cols: 3, layout: '5x3' }
            },
            landscape: {
                1: { rows: 1, cols: 1, layout: '1x1' },
                2: { rows: 1, cols: 2, layout: '1x2' },
                3: { rows: 1, cols: 3, layout: '1x3' },
                4: { rows: 2, cols: 2, layout: '2x2' },
                5: { rows: 2, cols: 3, layout: '2x3' },
                6: { rows: 2, cols: 3, layout: '2x3' },
                8: { rows: 2, cols: 4, layout: '2x4' },
                9: { rows: 3, cols: 3, layout: '3x3' },
                12: { rows: 3, cols: 4, layout: '3x4' },
                15: { rows: 3, cols: 5, layout: '3x5' }
            }
        };

        const layouts = isPortrait ? printLayouts.portrait : printLayouts.landscape;

        // Find exact match or closest lower
        if (layouts[spots]) {
            return layouts[spots];
        }

        // Find closest available layout
        const availableSpots = Object.keys(layouts).map(Number).sort((a, b) => a - b);
        const closestSpot = availableSpots.reduce((prev, curr) => {
            return (curr <= spots && curr > prev) ? curr : prev;
        }, 1);

        return layouts[closestSpot] || layouts[1];
    }

    /**
     * Get current screen orientation
     * @returns {string} 'portrait' or 'landscape'
     */
    getScreenOrientation() {
        return window.innerHeight > window.innerWidth ? 'portrait' : 'landscape';
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.DICOM_VIEWER.MANAGERS.customGridManager) {
            window.DICOM_VIEWER.MANAGERS.customGridManager = new window.DICOM_VIEWER.CustomGridLayoutManager();
            window.DICOM_VIEWER.MANAGERS.customGridManager.initialize();
        }
    });
} else {
    if (!window.DICOM_VIEWER.MANAGERS) {
        window.DICOM_VIEWER.MANAGERS = {};
    }
    window.DICOM_VIEWER.MANAGERS.customGridManager = new window.DICOM_VIEWER.CustomGridLayoutManager();
}

/**
 * Print Counter Badge Component
 * Real-time badge showing print count with auto-refresh
 *
 * Features:
 * - Displays today's print count in navigation
 * - Auto-refreshes using polling (every 10 seconds)
 * - Animates when new prints are detected
 * - Shows detailed breakdown on hover
 * - Works across all pages (patients, studies, viewer)
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.PrintCounterBadge = class {
    constructor(options = {}) {
        this.containerId = options.containerId || 'print-counter-badge-container';
        this.pollInterval = options.pollInterval || 10000; // 10 seconds
        this.showDetails = options.showDetails !== false;
        this.period = options.period || 'today';

        this.lastPrintId = 0;
        this.lastTimestamp = 0;
        this.pollTimer = null;
        this.isInitialized = false;

        this.init();
    }

    async init() {
        // Create badge container if it doesn't exist
        this.createBadgeElement();

        // Initial fetch with small delay to ensure DOM is ready
        setTimeout(async () => {
            await this.fetchAndUpdate();
        }, 500);

        // Start polling
        this.startPolling();

        // Listen for print events from PrintManager
        this.listenForPrintEvents();

        this.isInitialized = true;
        console.log('PrintCounterBadge initialized');
    }

    createBadgeElement() {
        let container = document.getElementById(this.containerId);

        if (!container) {
            // Try to find navbar and add badge there
            const navbar = document.querySelector('.navbar-custom, .navbar-super, nav.navbar');
            if (navbar) {
                const navContainer = navbar.querySelector('.d-flex') || navbar.querySelector('.container-fluid > .d-flex');
                if (navContainer) {
                    container = document.createElement('div');
                    container.id = this.containerId;
                    container.className = 'print-counter-wrapper me-3';

                    // Insert before the user info or admin menu
                    const adminMenu = navContainer.querySelector('#adminMenu');
                    const userInfo = navContainer.querySelector('.text-light, span');
                    if (adminMenu) {
                        navContainer.insertBefore(container, adminMenu);
                    } else if (userInfo) {
                        navContainer.insertBefore(container, userInfo);
                    } else {
                        navContainer.appendChild(container);
                    }
                }
            }
        } else {
            // Container exists, just add wrapper class
            container.className = 'print-counter-wrapper';
        }

        if (!container) {
            console.warn('Print counter badge container not found');
            return;
        }

        console.log('Print counter badge: Creating badge in container', container.id);

        // Create badge HTML
        container.innerHTML = `
            <div class="print-counter-badge" id="print-counter-badge" title="Today's prints">
                <div class="print-counter-icon">
                    <i class="bi bi-printer-fill"></i>
                </div>
                <div class="print-counter-number" id="print-counter-number">
                    <span class="count">0</span>
                </div>
                <div class="print-counter-dropdown" id="print-counter-dropdown">
                    <div class="dropdown-header">
                        <i class="bi bi-printer me-2"></i>Print Statistics
                    </div>
                    <div class="dropdown-body" id="print-counter-details">
                        <div class="stat-row">
                            <span>Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Add styles
        this.addStyles();

        // Add click/hover handlers - use wrapper for hover to include dropdown area
        const badge = container.querySelector('.print-counter-badge');
        const wrapper = container;

        if (wrapper && badge) {
            // Use wrapper for hover events so dropdown stays open when mouse moves to it
            wrapper.addEventListener('mouseenter', () => this.showDropdown());
            wrapper.addEventListener('mouseleave', () => {
                // Delay hiding to allow mouse to move to dropdown
                this._hideTimeout = setTimeout(() => this.hideDropdown(), 150);
            });

            // Cancel hide timeout if entering wrapper again
            wrapper.addEventListener('mouseenter', () => {
                if (this._hideTimeout) {
                    clearTimeout(this._hideTimeout);
                    this._hideTimeout = null;
                }
            });

            badge.addEventListener('click', () => this.toggleDropdown());
        }

        this.container = container;
    }

    addStyles() {
        if (document.getElementById('print-counter-styles')) return;

        const styles = document.createElement('style');
        styles.id = 'print-counter-styles';
        styles.textContent = `
            .print-counter-wrapper {
                position: relative;
                display: inline-block;
                z-index: 1000; /* Ensure dropdown shows above other elements */
            }

            .print-counter-badge {
                display: flex;
                align-items: center;
                gap: 6px;
                background: linear-gradient(135deg, rgba(249, 115, 22, 0.15), rgba(234, 88, 12, 0.25));
                border: 1px solid rgba(249, 115, 22, 0.3);
                border-radius: 25px;
                padding: 6px 14px;
                cursor: pointer;
                transition: all 0.3s ease;
                position: relative;
            }

            .print-counter-badge:hover {
                background: linear-gradient(135deg, rgba(249, 115, 22, 0.25), rgba(234, 88, 12, 0.35));
                border-color: rgba(249, 115, 22, 0.5);
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(249, 115, 22, 0.2);
            }

            .print-counter-icon {
                color: #f97316;
                font-size: 1rem;
                display: flex;
                align-items: center;
            }

            .print-counter-number {
                font-weight: 600;
                color: #fff;
                font-size: 0.9rem;
                min-width: 20px;
                text-align: center;
            }

            .print-counter-number .count {
                transition: all 0.3s ease;
            }

            .print-counter-badge.updating .count {
                animation: pulse-count 0.5s ease;
            }

            @keyframes pulse-count {
                0% { transform: scale(1); }
                50% { transform: scale(1.3); color: #22c55e; }
                100% { transform: scale(1); }
            }

            .print-counter-badge.new-print {
                animation: badge-glow 0.6s ease;
            }

            @keyframes badge-glow {
                0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
                50% { box-shadow: 0 0 15px 5px rgba(34, 197, 94, 0.4); }
                100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
            }

            .print-counter-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                margin-top: 8px;
                background: rgba(30, 35, 60, 0.98);
                border: 1px solid rgba(255, 255, 255, 0.15);
                border-radius: 12px;
                min-width: 240px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
                z-index: 10000; /* Very high z-index to show above everything */
                opacity: 0;
                visibility: hidden;
                transform: translateY(-10px);
                transition: all 0.2s ease;
                overflow: hidden;
                pointer-events: none; /* Prevent blocking other elements when hidden */
            }

            .print-counter-dropdown.show {
                opacity: 1;
                visibility: visible;
                transform: translateY(0);
                pointer-events: auto; /* Enable interactions when visible */
            }

            .dropdown-header {
                background: linear-gradient(135deg, rgba(249, 115, 22, 0.2), rgba(234, 88, 12, 0.3));
                padding: 12px 16px;
                font-weight: 600;
                color: #f97316;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                font-size: 0.9rem;
            }

            .dropdown-body {
                padding: 12px 16px;
            }

            .stat-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
                font-size: 0.85rem;
            }

            .stat-row:last-child {
                border-bottom: none;
            }

            .stat-row .label {
                color: #adb5bd;
            }

            .stat-row .value {
                font-weight: 600;
                color: #fff;
            }

            .stat-row .value.success {
                color: #22c55e;
            }

            .stat-row .value.warning {
                color: #eab308;
            }

            .stat-row .value.danger {
                color: #ef4444;
            }

            .stat-row .value.info {
                color: #3b82f6;
            }

            .stat-total {
                margin-top: 8px;
                padding-top: 12px;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
            }

            .stat-total .label {
                font-weight: 600;
                color: #fff;
            }

            .stat-total .value {
                font-size: 1.1rem;
                background: linear-gradient(135deg, #f97316, #ea580c);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
            }

            /* Mobile responsiveness */
            @media (max-width: 768px) {
                .print-counter-badge {
                    padding: 4px 10px;
                }

                .print-counter-dropdown {
                    right: -50px;
                    min-width: 200px;
                }
            }
            
            /* Force parent containers to allow dropdown overflow */
            nav.navbar,
            .navbar,
            .navbar-custom,
            .navbar-super,
            .navbar .container-fluid,
            .navbar .d-flex,
            .navbar-custom .container-fluid,
            .navbar-custom .d-flex,
            .navbar-super .container-fluid,
            .navbar-super .d-flex {
                overflow: visible !important;
            }
            
            /* Ensure navbar has high z-index so dropdown overlaps content below */
            nav.navbar,
            .navbar,
            .navbar-custom,
            .navbar-super {
                position: relative !important;
                z-index: 1100 !important;
            }
        `;
        document.head.appendChild(styles);
    }

    async fetchAndUpdate() {
        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const url = `${basePath}/api/print/count.php?period=${this.period}&since=${this.lastTimestamp}`;

            const response = await fetch(url, {
                credentials: 'same-origin' // Ensure cookies are sent for session auth
            });

            if (!response.ok) {
                // Don't show error for auth issues - user may not be logged in yet
                if (response.status === 401) {
                    return;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                this.updateBadge(data);
                this.lastTimestamp = data.timestamp;

                // Check for new prints
                if (this.lastPrintId > 0 && data.last_print_id > this.lastPrintId) {
                    this.triggerNewPrintAnimation();
                }
                this.lastPrintId = data.last_print_id;
            }
        } catch (error) {
            console.error('Failed to fetch print count:', error);
        }
    }

    updateBadge(data) {
        const countEl = document.getElementById('print-counter-number');
        const detailsEl = document.getElementById('print-counter-details');
        const badge = document.getElementById('print-counter-badge');

        if (!countEl) return;

        const counts = data.counts || {};
        const oldCount = parseInt(countEl.querySelector('.count')?.textContent || '0');
        const newCount = counts.total || 0;

        // Update main count with animation if changed
        if (newCount !== oldCount) {
            badge?.classList.add('updating');
            countEl.innerHTML = `<span class="count">${newCount}</span>`;
            setTimeout(() => badge?.classList.remove('updating'), 500);
        }

        // Update dropdown details
        if (detailsEl) {
            detailsEl.innerHTML = `
                <div class="stat-row">
                    <span class="label"><i class="bi bi-check-circle me-2"></i>Completed</span>
                    <span class="value success">${counts.completed || 0}</span>
                </div>
                <div class="stat-row">
                    <span class="label"><i class="bi bi-hourglass-split me-2"></i>Pending</span>
                    <span class="value warning">${counts.pending || 0}</span>
                </div>
                <div class="stat-row">
                    <span class="label"><i class="bi bi-x-circle me-2"></i>Failed</span>
                    <span class="value danger">${counts.failed || 0}</span>
                </div>
                <div class="stat-row">
                    <span class="label"><i class="bi bi-file-earmark me-2"></i>Total Pages</span>
                    <span class="value info">${counts.pages || 0}</span>
                </div>
                <div class="stat-row stat-total">
                    <span class="label"><i class="bi bi-currency-rupee me-2"></i>Revenue</span>
                    <span class="value">${this.formatCurrency(counts.cost || 0)}</span>
                </div>
            `;
        }

        // Update title
        if (badge) {
            const periodLabel = this.period === 'today' ? "Today's" :
                this.period === 'week' ? "This Week's" :
                    this.period === 'month' ? "This Month's" : "Total";
            badge.title = `${periodLabel} Prints: ${newCount}`;
        }
    }

    formatCurrency(amount) {
        return new Intl.NumberFormat('en-IN', {
            style: 'currency',
            currency: 'INR',
            maximumFractionDigits: 0
        }).format(amount);
    }

    triggerNewPrintAnimation() {
        const badge = document.getElementById('print-counter-badge');
        if (badge) {
            badge.classList.add('new-print');
            setTimeout(() => badge.classList.remove('new-print'), 600);
        }
    }

    showDropdown() {
        const dropdown = document.getElementById('print-counter-dropdown');
        if (dropdown) {
            dropdown.classList.add('show');
        }
    }

    hideDropdown() {
        const dropdown = document.getElementById('print-counter-dropdown');
        if (dropdown) {
            dropdown.classList.remove('show');
        }
    }

    toggleDropdown() {
        const dropdown = document.getElementById('print-counter-dropdown');
        if (dropdown) {
            dropdown.classList.toggle('show');
        }
    }

    startPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
        }
        this.pollTimer = setInterval(() => this.fetchAndUpdate(), this.pollInterval);
    }

    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    listenForPrintEvents() {
        // Listen for custom print events dispatched by PrintManager
        document.addEventListener('printJobQueued', () => {
            // Immediate refresh when a print is queued
            setTimeout(() => this.fetchAndUpdate(), 1000);
        });

        document.addEventListener('printJobCompleted', () => {
            // Refresh when print completes
            setTimeout(() => this.fetchAndUpdate(), 500);
        });

        // Also listen for visibility changes to refresh when tab becomes active
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.fetchAndUpdate();
            }
        });
    }

    // Manual refresh method (can be called externally)
    refresh() {
        return this.fetchAndUpdate();
    }

    // Set the period and refresh
    setPeriod(period) {
        this.period = period;
        return this.fetchAndUpdate();
    }

    // Destroy the component
    destroy() {
        this.stopPolling();
        if (this.container) {
            this.container.remove();
        }
    }
};

// Auto-initialize on DOM ready if not already initialized
document.addEventListener('DOMContentLoaded', () => {
    // Check if we're on a page that should show the print counter
    const shouldShow = document.querySelector('.navbar-custom, .navbar-super, nav.navbar');
    if (shouldShow && !window.DICOM_VIEWER.printCounterBadge) {
        window.DICOM_VIEWER.printCounterBadge = new window.DICOM_VIEWER.PrintCounterBadge();
    }
});

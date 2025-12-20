/**
 * Interactive App Tour
 * Provides guided tours through the application with element highlighting
 */

class AppTour {
    constructor(options = {}) {
        this.steps = [];
        this.currentStep = 0;
        this.overlay = null;
        this.tooltip = null;
        this.isActive = false;
        this.onComplete = options.onComplete || (() => { });
        this.onSkip = options.onSkip || (() => { });

        this.createStyles();
    }

    createStyles() {
        if (document.getElementById('app-tour-styles')) return;

        const style = document.createElement('style');
        style.id = 'app-tour-styles';
        style.textContent = `
            .tour-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.75);
                z-index: 9998;
                transition: opacity 0.3s ease;
            }
            
            .tour-highlight {
                position: relative;
                z-index: 9999 !important;
                box-shadow: 0 0 0 4px #0d6efd, 0 0 0 8px rgba(13, 110, 253, 0.3) !important;
                border-radius: 8px;
            }
            
            .tour-tooltip {
                position: fixed;
                z-index: 10000;
                background: linear-gradient(135deg, #1a1f3a, #0a0e27);
                border: 2px solid #0d6efd;
                border-radius: 16px;
                padding: 24px;
                max-width: 400px;
                min-width: 300px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
                animation: tourFadeIn 0.3s ease;
            }
            
            @keyframes tourFadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .tour-tooltip-title {
                font-size: 1.2rem;
                font-weight: 600;
                color: #fff;
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .tour-tooltip-title i {
                color: #0d6efd;
            }
            
            .tour-tooltip-content {
                color: rgba(255, 255, 255, 0.8);
                line-height: 1.6;
                margin-bottom: 20px;
            }
            
            .tour-tooltip-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .tour-progress {
                display: flex;
                gap: 6px;
            }
            
            .tour-progress-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.3);
                transition: all 0.3s;
            }
            
            .tour-progress-dot.active {
                background: #0d6efd;
                transform: scale(1.2);
            }
            
            .tour-progress-dot.completed {
                background: #22c55e;
            }
            
            .tour-buttons {
                display: flex;
                gap: 8px;
            }
            
            .tour-btn {
                padding: 8px 16px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                border: none;
                transition: all 0.2s;
            }
            
            .tour-btn-skip {
                background: transparent;
                color: rgba(255, 255, 255, 0.5);
            }
            
            .tour-btn-skip:hover {
                color: rgba(255, 255, 255, 0.8);
            }
            
            .tour-btn-prev {
                background: rgba(255, 255, 255, 0.1);
                color: #fff;
            }
            
            .tour-btn-prev:hover {
                background: rgba(255, 255, 255, 0.2);
            }
            
            .tour-btn-next {
                background: linear-gradient(135deg, #0d6efd, #0056b3);
                color: #fff;
            }
            
            .tour-btn-next:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(13, 110, 253, 0.4);
            }
            
            .tour-btn-finish {
                background: linear-gradient(135deg, #22c55e, #16a34a);
                color: #fff;
            }
            
            .tour-arrow {
                position: absolute;
                width: 12px;
                height: 12px;
                background: #1a1f3a;
                border: 2px solid #0d6efd;
                transform: rotate(45deg);
            }
            
            .tour-arrow-top { top: -8px; border-bottom: none; border-right: none; }
            .tour-arrow-bottom { bottom: -8px; border-top: none; border-left: none; }
            .tour-arrow-left { left: -8px; border-bottom: none; border-right: none; }
            .tour-arrow-right { right: -8px; border-top: none; border-left: none; }
        `;
        document.head.appendChild(style);
    }

    setSteps(steps) {
        this.steps = steps.filter(step => {
            // Skip steps for elements that don't exist
            if (step.element) {
                const el = document.querySelector(step.element);
                return el !== null;
            }
            return true; // Keep steps without specific elements (like intro/outro)
        });
        return this;
    }

    start() {
        if (this.steps.length === 0) {
            console.warn('No tour steps defined');
            return;
        }

        this.isActive = true;
        this.currentStep = 0;
        this.createOverlay();
        this.showStep(0);
    }

    createOverlay() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'tour-overlay';
        document.body.appendChild(this.overlay);
    }

    showStep(index) {
        if (index < 0 || index >= this.steps.length) return;

        this.currentStep = index;
        const step = this.steps[index];

        // Remove previous highlight
        document.querySelectorAll('.tour-highlight').forEach(el => {
            el.classList.remove('tour-highlight');
        });

        // Remove previous tooltip
        if (this.tooltip) {
            this.tooltip.remove();
        }

        // Highlight element if specified
        let targetEl = null;
        if (step.element) {
            targetEl = document.querySelector(step.element);
            if (targetEl) {
                targetEl.classList.add('tour-highlight');
                targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Create tooltip
        this.createTooltip(step, targetEl);
    }

    createTooltip(step, targetEl) {
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'tour-tooltip';

        const isLast = this.currentStep === this.steps.length - 1;
        const isFirst = this.currentStep === 0;

        this.tooltip.innerHTML = `
            <div class="tour-tooltip-title">
                <i class="bi ${step.icon || 'bi-lightbulb'}"></i>
                ${step.title}
            </div>
            <div class="tour-tooltip-content">
                ${step.content}
            </div>
            <div class="tour-tooltip-footer">
                <div class="tour-progress">
                    ${this.steps.map((_, i) => `
                        <div class="tour-progress-dot ${i < this.currentStep ? 'completed' : ''} ${i === this.currentStep ? 'active' : ''}"></div>
                    `).join('')}
                </div>
                <div class="tour-buttons">
                    <button class="tour-btn tour-btn-skip" onclick="window.appTour.skip()">Skip Tour</button>
                    ${!isFirst ? '<button class="tour-btn tour-btn-prev" onclick="window.appTour.prev()">Back</button>' : ''}
                    ${isLast
                ? '<button class="tour-btn tour-btn-finish" onclick="window.appTour.finish()">Finish</button>'
                : '<button class="tour-btn tour-btn-next" onclick="window.appTour.next()">Next</button>'
            }
                </div>
            </div>
        `;

        document.body.appendChild(this.tooltip);
        this.positionTooltip(targetEl, step.position || 'auto');
    }

    positionTooltip(targetEl, position) {
        if (!targetEl) {
            // Center on screen
            const rect = this.tooltip.getBoundingClientRect();
            this.tooltip.style.left = `${(window.innerWidth - rect.width) / 2}px`;
            this.tooltip.style.top = `${(window.innerHeight - rect.height) / 2}px`;
            return;
        }

        const targetRect = targetEl.getBoundingClientRect();
        const tooltipRect = this.tooltip.getBoundingClientRect();
        const padding = 15;

        let top, left;

        // Auto-position based on available space
        if (position === 'auto') {
            const spaceBelow = window.innerHeight - targetRect.bottom;
            const spaceAbove = targetRect.top;
            const spaceRight = window.innerWidth - targetRect.right;
            const spaceLeft = targetRect.left;

            if (spaceBelow >= tooltipRect.height + padding) {
                position = 'bottom';
            } else if (spaceAbove >= tooltipRect.height + padding) {
                position = 'top';
            } else if (spaceRight >= tooltipRect.width + padding) {
                position = 'right';
            } else {
                position = 'left';
            }
        }

        switch (position) {
            case 'bottom':
                top = targetRect.bottom + padding;
                left = targetRect.left + (targetRect.width - tooltipRect.width) / 2;
                break;
            case 'top':
                top = targetRect.top - tooltipRect.height - padding;
                left = targetRect.left + (targetRect.width - tooltipRect.width) / 2;
                break;
            case 'right':
                top = targetRect.top + (targetRect.height - tooltipRect.height) / 2;
                left = targetRect.right + padding;
                break;
            case 'left':
                top = targetRect.top + (targetRect.height - tooltipRect.height) / 2;
                left = targetRect.left - tooltipRect.width - padding;
                break;
        }

        // Keep within viewport
        left = Math.max(10, Math.min(left, window.innerWidth - tooltipRect.width - 10));
        top = Math.max(10, Math.min(top, window.innerHeight - tooltipRect.height - 10));

        this.tooltip.style.left = `${left}px`;
        this.tooltip.style.top = `${top}px`;
    }

    next() {
        if (this.currentStep < this.steps.length - 1) {
            this.showStep(this.currentStep + 1);
        }
    }

    prev() {
        if (this.currentStep > 0) {
            this.showStep(this.currentStep - 1);
        }
    }

    skip() {
        this.cleanup();
        this.onSkip();
    }

    finish() {
        this.cleanup();
        this.onComplete();
    }

    cleanup() {
        this.isActive = false;

        document.querySelectorAll('.tour-highlight').forEach(el => {
            el.classList.remove('tour-highlight');
        });

        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }

        if (this.tooltip) {
            this.tooltip.remove();
            this.tooltip = null;
        }
    }
}

// Global tour instance
window.AppTour = AppTour;

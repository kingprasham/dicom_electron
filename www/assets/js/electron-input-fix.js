/**
 * Electron Input Fix - JavaScript Component
 * Ensures input fields work properly in Electron environment
 */

(function () {
    'use strict';

    console.log('🔧 Initializing Electron Input Fix...');

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInputFix);
    } else {
        initInputFix();
    }

    function initInputFix() {
        // Fix all existing inputs
        fixAllInputs();

        // Watch for dynamically added inputs
        observeNewInputs();

        // Add global event listeners
        addGlobalListeners();

        console.log('✅ Electron Input Fix initialized');
    }

    function fixAllInputs() {
        const inputs = document.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            fixSingleInput(input);
        });
        console.log(`Fixed ${inputs.length} input elements`);
    }

    function fixSingleInput(input) {
        // Remove any interfering attributes
        input.removeAttribute('readonly');

        // Ensure proper attributes
        input.style.pointerEvents = 'auto';
        input.style.userSelect = 'text';
        input.style.webkitUserSelect = 'text';

        // Add focus handler
        input.addEventListener('focus', function () {
            this.style.pointerEvents = 'auto';
            this.style.userSelect = 'text';
        }, { passive: true });

        // Prevent drag interference
        input.addEventListener('dragstart', function (e) {
            e.preventDefault();
            return false;
        });

        // Force enable on click
        input.addEventListener('click', function () {
            this.removeAttribute('readonly');
            this.focus();
        }, { passive: true });
    }

    function observeNewInputs() {
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) { // Element node
                        if (node.matches && node.matches('input, textarea, select')) {
                            fixSingleInput(node);
                        }
                        // Check children
                        const inputs = node.querySelectorAll && node.querySelectorAll('input, textarea, select');
                        if (inputs) {
                            inputs.forEach(fixSingleInput);
                        }
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    function addGlobalListeners() {
        // Prevent any global event from blocking input
        document.addEventListener('mousedown', function (e) {
            if (e.target.matches('input, textarea, select')) {
                e.stopPropagation();
            }
        }, true);

        document.addEventListener('click', function (e) {
            if (e.target.matches('input, textarea, select')) {
                e.stopPropagation();
            }
        }, true);

        // Fix focus issues
        document.addEventListener('focus', function (e) {
            if (e.target.matches('input, textarea, select')) {
                e.target.style.pointerEvents = 'auto';
                e.target.style.userSelect = 'text';
            }
        }, true);
    }

})();

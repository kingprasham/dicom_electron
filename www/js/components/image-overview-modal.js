/**
 * Image Overview Modal
 * Shows all series images in a thumbnail grid for quick overview and navigation
 * User can click any thumbnail to jump to that image in the viewer
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.ImageOverviewModal = class {
    constructor() {
        this.modal = null;
        this.thumbnailCache = new Map();
        this.init();
    }

    init() {
        this.setupButton();
        this.setupKeyboardShortcut();
    }

    setupButton() {
        const btn = document.getElementById('viewAllImagesBtn');
        if (btn) {
            btn.addEventListener('click', () => this.showOverview());
        }
    }

    setupKeyboardShortcut() {
        // Alt+A to show overview
        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key === 'a') {
                e.preventDefault();
                this.showOverview();
            }
        });
    }

    async showOverview() {
        const state = window.DICOM_VIEWER.STATE;
        const images = state.currentSeriesImages || [];

        if (images.length === 0) {
            this.showToast('No images loaded', 'warning');
            return;
        }

        // Remove existing modal if present
        const existingModal = document.getElementById('imageOverviewModal');
        if (existingModal) existingModal.remove();

        // Calculate optimal grid based on image count
        const gridConfig = this.calculateOptimalGrid(images.length);

        // Get patient info for header
        const currentImage = images[0] || {};
        const patientName = currentImage.patient_name || currentImage.patientName || 'Unknown Patient';
        const patientId = currentImage.patient_id || currentImage.patientId || '';
        const studyDesc = currentImage.study_description || currentImage.studyDescription || 'DICOM Study';

        // Create modal HTML
        const modalHTML = `
            <div class="modal fade" id="imageOverviewModal" tabindex="-1" data-bs-backdrop="static">
                <div class="modal-dialog modal-fullscreen">
                    <div class="modal-content bg-dark">
                        <div class="modal-header border-secondary py-2" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);">
                            <div class="d-flex align-items-center gap-3">
                                <i class="bi bi-images fs-4 text-warning"></i>
                                <div>
                                    <h5 class="modal-title mb-0 text-light">All Images Overview</h5>
                                    <small class="text-muted">${patientName} | ${studyDesc} | ${images.length} images</small>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-warning text-dark">Click any image to view</span>
                                <select class="form-select form-select-sm" id="overviewGridSize" style="width: auto; background: #2a2d3a; color: #fff; border-color: #444;">
                                    <option value="4" ${gridConfig.cols === 4 ? 'selected' : ''}>4 per row</option>
                                    <option value="5" ${gridConfig.cols === 5 ? 'selected' : ''}>5 per row</option>
                                    <option value="6" ${gridConfig.cols === 6 ? 'selected' : ''}>6 per row</option>
                                    <option value="8" ${gridConfig.cols === 8 ? 'selected' : ''}>8 per row</option>
                                    <option value="10" ${gridConfig.cols === 10 ? 'selected' : ''}>10 per row</option>
                                </select>
                                <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">
                                    <i class="bi bi-x-lg"></i> Close
                                </button>
                            </div>
                        </div>
                        <div class="modal-body p-3" id="overviewContent" style="overflow-y: auto; background: #1a1a1a;">
                            <div class="text-center py-5">
                                <div class="spinner-border text-warning" role="status"></div>
                                <p class="mt-2 text-muted">Loading thumbnails...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style id="imageOverviewStyles">
                #imageOverviewModal .thumbnail-grid {
                    display: grid;
                    grid-template-columns: repeat(${gridConfig.cols}, 1fr);
                    gap: 8px;
                    padding: 8px;
                }

                #imageOverviewModal .thumbnail-item {
                    position: relative;
                    background: #000;
                    border: 2px solid #333;
                    border-radius: 6px;
                    overflow: hidden;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    aspect-ratio: 1;
                }

                #imageOverviewModal .thumbnail-item:hover {
                    border-color: #ffc107;
                    box-shadow: 0 0 15px rgba(255, 193, 7, 0.4);
                    transform: scale(1.02);
                    z-index: 10;
                }

                #imageOverviewModal .thumbnail-item.current {
                    border-color: #0d6efd;
                    box-shadow: 0 0 15px rgba(13, 110, 253, 0.5);
                }

                #imageOverviewModal .thumbnail-item img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                    background: #000;
                }

                #imageOverviewModal .thumbnail-number {
                    position: absolute;
                    bottom: 4px;
                    right: 4px;
                    background: rgba(0, 0, 0, 0.8);
                    color: #00ff00;
                    font-size: 10px;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-family: 'Consolas', monospace;
                }

                #imageOverviewModal .thumbnail-current-badge {
                    position: absolute;
                    top: 4px;
                    left: 4px;
                    background: #0d6efd;
                    color: #fff;
                    font-size: 9px;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-weight: bold;
                }

                #imageOverviewModal .thumbnail-loading {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #666;
                    font-size: 24px;
                }
            </style>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modal = new bootstrap.Modal(document.getElementById('imageOverviewModal'));
        this.modal = modal;
        modal.show();

        // Setup grid size change handler
        const gridSizeSelect = document.getElementById('overviewGridSize');
        gridSizeSelect.addEventListener('change', () => {
            const cols = parseInt(gridSizeSelect.value);
            document.querySelector('#imageOverviewModal .thumbnail-grid').style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
        });

        // Load thumbnails
        await this.loadThumbnails(images, state.currentImageIndex || 0);
    }

    calculateOptimalGrid(imageCount) {
        // Calculate optimal grid based on screen size and image count
        if (imageCount <= 16) return { cols: 4 };
        if (imageCount <= 25) return { cols: 5 };
        if (imageCount <= 36) return { cols: 6 };
        if (imageCount <= 64) return { cols: 8 };
        return { cols: 10 };
    }

    async loadThumbnails(images, currentIndex) {
        const container = document.getElementById('overviewContent');
        const state = window.DICOM_VIEWER.STATE;

        // Create thumbnail grid
        let html = '<div class="thumbnail-grid">';

        for (let i = 0; i < images.length; i++) {
            const isCurrent = i === currentIndex;
            html += `
                <div class="thumbnail-item ${isCurrent ? 'current' : ''}" data-index="${i}" title="Image ${i + 1}">
                    <div class="thumbnail-loading"><i class="bi bi-hourglass-split"></i></div>
                    ${isCurrent ? '<span class="thumbnail-current-badge">CURRENT</span>' : ''}
                    <span class="thumbnail-number">${i + 1}</span>
                </div>
            `;
        }

        html += '</div>';
        container.innerHTML = html;

        // Add click handlers
        container.querySelectorAll('.thumbnail-item').forEach(item => {
            item.addEventListener('click', () => {
                const index = parseInt(item.dataset.index);
                this.goToImage(index);
            });
        });

        // Load thumbnail images progressively
        const viewport = document.querySelector('.viewport');

        for (let i = 0; i < images.length; i++) {
            try {
                const imageId = images[i].imageId;
                if (!imageId) continue;

                // Check cache first
                if (this.thumbnailCache.has(imageId)) {
                    this.displayThumbnail(i, this.thumbnailCache.get(imageId));
                    continue;
                }

                // Load and capture thumbnail
                const image = await cornerstone.loadImage(imageId);

                // Create temporary canvas for thumbnail
                const canvas = document.createElement('canvas');
                const thumbnailSize = 200;
                canvas.width = thumbnailSize;
                canvas.height = thumbnailSize;

                // Use cornerstone to render to our canvas
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#000';
                ctx.fillRect(0, 0, thumbnailSize, thumbnailSize);

                // Simple rendering - just draw the pixel data
                if (image.getPixelData) {
                    const pixelData = image.getPixelData();
                    const width = image.width;
                    const height = image.height;

                    // Create imageData
                    const imageData = ctx.createImageData(thumbnailSize, thumbnailSize);
                    const scale = Math.min(thumbnailSize / width, thumbnailSize / height);
                    const offsetX = (thumbnailSize - width * scale) / 2;
                    const offsetY = (thumbnailSize - height * scale) / 2;

                    // Apply window/level and scale
                    const wc = image.windowCenter || 127;
                    const ww = image.windowWidth || 256;
                    const minVal = wc - ww / 2;
                    const maxVal = wc + ww / 2;

                    for (let y = 0; y < thumbnailSize; y++) {
                        for (let x = 0; x < thumbnailSize; x++) {
                            const srcX = Math.floor((x - offsetX) / scale);
                            const srcY = Math.floor((y - offsetY) / scale);

                            let pixelValue = 0;
                            if (srcX >= 0 && srcX < width && srcY >= 0 && srcY < height) {
                                const srcIdx = srcY * width + srcX;
                                const raw = pixelData[srcIdx] || 0;
                                pixelValue = Math.max(0, Math.min(255, ((raw - minVal) / (maxVal - minVal)) * 255));
                            }

                            const idx = (y * thumbnailSize + x) * 4;
                            imageData.data[idx] = pixelValue;
                            imageData.data[idx + 1] = pixelValue;
                            imageData.data[idx + 2] = pixelValue;
                            imageData.data[idx + 3] = 255;
                        }
                    }

                    ctx.putImageData(imageData, 0, 0);
                }

                const dataUrl = canvas.toDataURL('image/jpeg', 0.7);
                this.thumbnailCache.set(imageId, dataUrl);
                this.displayThumbnail(i, dataUrl);

            } catch (err) {
                console.error(`Error loading thumbnail ${i}:`, err);
                this.displayThumbnailError(i);
            }
        }
    }

    displayThumbnail(index, dataUrl) {
        const item = document.querySelector(`#imageOverviewModal .thumbnail-item[data-index="${index}"]`);
        if (item) {
            const loading = item.querySelector('.thumbnail-loading');
            if (loading) loading.remove();

            const img = document.createElement('img');
            img.src = dataUrl;
            img.alt = `Image ${index + 1}`;
            item.insertBefore(img, item.firstChild);
        }
    }

    displayThumbnailError(index) {
        const item = document.querySelector(`#imageOverviewModal .thumbnail-item[data-index="${index}"]`);
        if (item) {
            const loading = item.querySelector('.thumbnail-loading');
            if (loading) {
                loading.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i>';
            }
        }
    }

    goToImage(index) {
        const state = window.DICOM_VIEWER.STATE;

        // Update current image
        if (state.currentSeriesImages && state.currentSeriesImages[index]) {
            state.currentImageIndex = index;

            // Load the image in the viewport
            const viewport = document.querySelector('.viewport');
            const imageId = state.currentSeriesImages[index].imageId;

            if (viewport && imageId) {
                cornerstone.loadImage(imageId).then(image => {
                    cornerstone.displayImage(viewport, image);

                    // Update image counter
                    const imageCounter = document.getElementById('imageCounter');
                    if (imageCounter) {
                        imageCounter.textContent = `${index + 1} / ${state.currentSeriesImages.length}`;
                    }

                    // Update slider
                    const slider = document.getElementById('imageSlider');
                    if (slider) {
                        slider.value = index;
                    }
                });
            }
        }

        // Close modal
        if (this.modal) {
            this.modal.hide();
        }
    }

    showToast(message, type = 'info') {
        // Simple toast notification
        const toast = document.createElement('div');
        toast.className = `position-fixed bottom-0 end-0 p-3`;
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            <div class="toast show bg-${type === 'warning' ? 'warning' : 'primary'} text-${type === 'warning' ? 'dark' : 'white'}">
                <div class="toast-body">${message}</div>
            </div>
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    if (!window.DICOM_VIEWER.MANAGERS) {
        window.DICOM_VIEWER.MANAGERS = {};
    }
    window.DICOM_VIEWER.MANAGERS.imageOverviewModal = new window.DICOM_VIEWER.ImageOverviewModal();
    console.log('✓ Image Overview Modal initialized');
});

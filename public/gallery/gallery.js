/**
 * Enhanced Gallery JavaScript
 * Handles gallery interactions with sidebar layout and advanced features
 */

/**
 * Improved Slideshow Implementation
 */
class ImprovedSlideshow {
    constructor(gallery) {
        this.gallery = gallery;
        this.isActive = false;
        this.currentTimer = null;
        this.videoEndHandler = null;
        this.currentVideo = null;
        
        // Configuration
        this.config = {
            imageDisplayTime: 4000,    // 4 seconds for images
            minVideoTime: 3000,        // Minimum 3 seconds for videos
            maxVideoTime: 120000,      // Maximum 2 minutes for videos
            postVideoDelay: 1500,      // Delay after video ends
            manualNavCooldown: 2000    // Cooldown after manual navigation
        };
    }
    
    start() {
        if (this.gallery.displayedFiles.length <= 1) {
            this.gallery.showNotification('Need at least 2 files for slideshow', 'info');
            return false;
        }
        
        this.isActive = true;
        this.updateUI(true);
        this.gallery.showNotification('Slideshow started', 'info');
        this.scheduleNext();
        return true;
    }
    
    stop() {
        this.isActive = false;
        this.clearAllTimers();
        this.cleanupVideo();
        this.updateUI(false);
        
        // Don't pause current video - let user continue watching
        this.gallery.showNotification('Slideshow stopped', 'info');
    }
    
    toggle() {
        if (this.isActive) {
            this.stop();
        } else {
            this.start();
        }
    }
    
    /**
     * Handle manual navigation during slideshow
     */
    onManualNavigation() {
        if (!this.isActive) return;
        
        // Clear current timers but keep slideshow active
        this.clearAllTimers();
        this.cleanupVideo();
        
        // Resume slideshow after cooldown
        this.currentTimer = setTimeout(() => {
            if (this.isActive) {
                this.scheduleNext();
            }
        }, this.config.manualNavCooldown);
    }
    
    /**
     * Schedule the next slide advance based on current media type
     */
    scheduleNext() {
        if (!this.isActive) return;
        
        this.clearAllTimers();
        
        const currentFile = this.gallery.displayedFiles[this.gallery.currentFileIndex];
        if (!currentFile) return;
        
        if (currentFile.type === 'video') {
            this.handleVideoSlide();
        } else {
            this.handleImageSlide();
        }
    }
    
    /**
     * Handle slideshow timing for images
     */
    handleImageSlide() {
        this.currentTimer = setTimeout(() => {
            if (this.isActive) {
                this.advance();
            }
        }, this.config.imageDisplayTime);
    }
    
    /**
     * Handle slideshow timing for videos
     */
    handleVideoSlide() {
        const video = document.querySelector('#modalMedia video');
        if (!video) {
            // No video element, treat as image
            this.handleImageSlide();
            return;
        }
        
        this.currentVideo = video;
        
        // Try to auto-play video
        this.attemptVideoAutoplay(video);
        
        // Set up video end handler
        this.videoEndHandler = () => {
            if (this.isActive && this.currentVideo === video) {
                this.currentTimer = setTimeout(() => {
                    if (this.isActive) {
                        this.advance();
                    }
                }, this.config.postVideoDelay);
            }
        };
        
        video.addEventListener('ended', this.videoEndHandler, { once: true });
        
        // Fallback timer for very long videos
        this.currentTimer = setTimeout(() => {
            if (this.isActive && this.currentVideo === video && !video.ended) {
                console.log('Video exceeded max time, advancing slideshow');
                this.advance();
            }
        }, this.config.maxVideoTime);
        
        // Minimum display timer (in case video is very short or fails to load)
        setTimeout(() => {
            if (this.isActive && this.currentVideo === video && video.ended) {
                // Video ended quickly, ensure we advance
                this.videoEndHandler();
            }
        }, this.config.minVideoTime);
    }
    
    /**
     * Attempt to autoplay video with graceful fallbacks
     */
    async attemptVideoAutoplay(video) {
        try {
            // First try unmuted autoplay
            video.muted = false;
            await video.play();
            console.log('Video autoplay successful (unmuted)');
        } catch (error) {
            try {
                // Fallback to muted autoplay
                console.log('Unmuted autoplay failed, trying muted');
                video.muted = true;
                await video.play();
                console.log('Video autoplay successful (muted)');
            } catch (mutedError) {
                // If both fail, show controls and let user play manually
                console.log('All autoplay attempts failed');
                video.controls = true;
                video.muted = false; // Reset to unmuted for manual play
            }
        }
    }
    
    /**
     * Advance to next slide
     */
    advance() {
        if (!this.isActive) return;
        
        this.clearAllTimers();
        this.cleanupVideo();
        this.gallery.nextImage();
        // Note: scheduleNext() will be called by the gallery's updateModalContent
    }
    
    /**
     * Clear all active timers
     */
    clearAllTimers() {
        if (this.currentTimer) {
            clearTimeout(this.currentTimer);
            this.currentTimer = null;
        }
    }
    
    /**
     * Clean up video event listeners
     */
    cleanupVideo() {
        if (this.currentVideo && this.videoEndHandler) {
            this.currentVideo.removeEventListener('ended', this.videoEndHandler);
        }
        this.currentVideo = null;
        this.videoEndHandler = null;
    }
    
    /**
     * Update UI elements
     */
    updateUI(isActive) {
        const slideshowBtn = document.getElementById('modalSlideshow');
        const slideshowIndicator = document.getElementById('slideshowIndicator');
        
        if (slideshowBtn) {
            const span = slideshowBtn.querySelector('span');
            const text = isActive ? 'Stop Slideshow' : 'Slideshow';
            
            if (span) {
                span.textContent = text;
            } else {
                slideshowBtn.textContent = text;
            }
            
            slideshowBtn.classList.toggle('active', isActive);
        }
        
        if (slideshowIndicator) {
            slideshowIndicator.style.display = isActive ? 'block' : 'none';
        }
    }
}

class Gallery {
    constructor() {
        this.currentYear = new Date().getFullYear();
        this.files = [];
        this.displayedFiles = []; // this.files sorted per the active sortMode; drives modal/slideshow navigation
        this.selectedFile = null;
        this.currentFileIndex = 0;
        this.currentUserId = window.GALLERY_CONFIG?.currentUserId || 0;
        this.isAdmin = window.GALLERY_CONFIG?.isAdmin || false;
        this.users = []; // For admin upload on behalf functionality
        this.isReordering = false; // Prevent concurrent reorder operations
        
        // Upload queue management
        this.uploadQueue = [];
        this.isUploading = false;
        this.maxFileSize = 50 * 1024 * 1024; // 50MB in bytes
        
        // Store references to event handlers for proper cleanup
        this.dragHandlers = null;
        
        // Initialize improved slideshow
        this.slideshow = new ImprovedSlideshow(this);
        
        this.viewMode = 'grid';
        this.sortMode = 'custom'; // Default to custom ordering
        
        this.init();
    }
    
    async init() {
        await this.loadYears();
        await this.loadFiles(this.currentYear);
        this.setupEventListeners();
        
        // Load users for admin upload on behalf functionality
        if (this.isAdmin) {
            await this.loadUsers();
            this.setupUploadOnBehalf();
        }
    }
    
    async loadYears() {
        try {
            const response = await fetch('api.php?action=years');
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            await this.renderYearsList(data.years);
        } catch (error) {
            this.showNotification('Failed to load years: ' + error.message, 'error');
        }
    }
    
    async loadUsers() {
        try {
            const response = await fetch('api.php?action=users');
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.users = data.users || [];
        } catch (error) {
            console.error('Failed to load users:', error.message);
            this.users = [];
        }
    }
    
    setupUploadOnBehalf() {
        const container = document.getElementById('uploadOnBehalfContainer');
        const select = document.getElementById('uploadOnBehalfSelect');
        
        if (!container || !select) return;
        
        // Show the container for admins
        container.style.display = 'block';
        
        // Populate the select with users
        select.innerHTML = '';
        
        // Add current user as first option (default)
        const currentUserOption = document.createElement('option');
        currentUserOption.value = this.currentUserId;
        currentUserOption.textContent = 'Myself (default)';
        currentUserOption.selected = true;
        select.appendChild(currentUserOption);
        
        // Add other users
        this.users.forEach(user => {
            if (user.id !== this.currentUserId) {
                const option = document.createElement('option');
                option.value = user.id;
                option.textContent = user.display_name;
                select.appendChild(option);
            }
        });
        
        // Update button text when selection changes
        select.addEventListener('change', () => {
            this.updateUploadButton();
        });
    }
    
    async renderYearsList(years) {
        const yearsList = document.getElementById('yearsList');
        
        if (years.length === 0) {
            yearsList.innerHTML = `
                <div style="text-align: center; color: #666; padding: 2rem;">
                    <p>No gallery years found</p>
                </div>
            `;
            return;
        }
        
        // Get file counts for each year
        const yearItems = await Promise.all(years.map(async (year) => {
            try {
                const response = await fetch(`api.php?action=files&year=${year}`);
                const data = await response.json();
                const files = data.files || [];
                
                const totalCount = files.length;
                const imageCount = files.filter(f => f.type === 'image').length;
                const videoCount = files.filter(f => f.type === 'video').length;
                const totalSize = files.reduce((sum, f) => sum + f.size, 0);
                
                return {
                    year,
                    totalCount,
                    imageCount,
                    videoCount,
                    totalSize: this.formatFileSize(totalSize)
                };
            } catch (error) {
                return {
                    year,
                    totalCount: 0,
                    imageCount: 0,
                    videoCount: 0,
                    totalSize: '0 B'
                };
            }
        }));
        
        yearsList.innerHTML = yearItems.map(item => `
            <div class="year-item ${item.year === this.currentYear ? 'active' : ''}" 
                 data-year="${item.year}">
                <div class="year-number">${item.year}</div>
                <div class="year-stats">
                    <div class="year-count">${item.totalCount} files</div>
                    <div class="year-size">${item.totalSize}</div>
                    ${item.totalCount > 0 ? `
                        <div style="font-size: 0.7rem; opacity: 0.8;">
                            ${item.imageCount}📷 ${item.videoCount}🎥
                        </div>
                    ` : ''}
                </div>
            </div>
        `).join('');
    }
    
    async loadFiles(year) {
        this.showLoading();
        
        try {
            const response = await fetch(`api.php?action=files&year=${year}`);
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.files = data.files;
            this.currentYear = year;
            this.currentFileIndex = 0;
            
            this.updateCurrentYearInfo();
            this.updateYearButtons();
            this.renderGallery();
            this.updateGalleryTitle();
            
        } catch (error) {
            this.showNotification('Failed to load files: ' + error.message, 'error');
            this.hideLoading();
        }
    }
    
    updateCurrentYearInfo() {
        const currentYearEl = document.getElementById('currentYear');
        const currentYearStatsEl = document.getElementById('currentYearStats');
        
        if (currentYearEl) {
            currentYearEl.textContent = this.currentYear;
        }
        
        if (currentYearStatsEl) {
            const imageCount = this.files.filter(f => f.type === 'image').length;
            const videoCount = this.files.filter(f => f.type === 'video').length;
            const totalSize = this.files.reduce((sum, f) => sum + f.size, 0);
            
            currentYearStatsEl.innerHTML = `
                <div>${this.files.length} files total</div>
                <div>${imageCount} images, ${videoCount} videos</div>
                <div>Total: ${this.formatFileSize(totalSize)}</div>
            `;
        }
    }
    
    updateGalleryTitle() {
        const titleEl = document.getElementById('galleryYearTitle');
        if (titleEl) {
            titleEl.textContent = this.currentYear;
        }
    }
    
    renderGallery() {
        const grid = document.getElementById('galleryGrid');
        const loading = document.querySelector('.loading');
        
        if (loading) {
            loading.remove();
        }
        
        if (this.files.length === 0) {
            grid.innerHTML = `
                <div class="empty-gallery" style="grid-column: 1 / -1;">
                    <h3>No memories yet for ${this.escapeHtml(this.currentYear.toString())}</h3>
                    <p>Be the first to upload photos or videos from your playa adventures!</p>
                    <p style="margin-top: 1rem; font-size: 0.9rem; opacity: 0.8;">
                        Share your art, camps, sunrises, and all the magical moments that make Burning Man special.
                    </p>
                </div>
            `;
            return;
        }
        
        // Apply sorting
        const sortedFiles = this.sortFiles([...this.files]);
        // Keep track of the order actually rendered so the modal/slideshow can
        // navigate through images in the order the user sees them
        this.displayedFiles = sortedFiles;

        grid.innerHTML = sortedFiles.map((file, index) => {
            // Find original index by filename to avoid issues with object references after sorting
            const originalIndex = this.files.findIndex(f => f.filename === file.filename);
            return `
            <div class="gallery-item ${this.sortMode === 'custom' ? 'draggable' : ''}" 
                 data-filename="${this.escapeHtml(file.filename)}" 
                 data-index="${index}"
                 data-original-index="${originalIndex}"
                 ${this.sortMode === 'custom' ? 'draggable="true"' : ''}>
                <div class="gallery-item-content">
                    ${this.renderFileContent(file)}
                    ${file.type === 'video' ? '<div class="video-indicator">Video</div>' : ''}
                    ${this.sortMode === 'custom' ? '<div class="drag-handle">⋮⋮</div>' : ''}
                    <div class="gallery-item-overlay">
                        <div class="gallery-item-info">
                            <div class="file-meta">
                                ${this.escapeHtml(this.formatFileSize(file.size))} • ${this.escapeHtml(this.formatDate(file.modified))}
                                ${file.uploaderName ? ` • <span class="uploader-info">by ${this.escapeHtml(file.uploaderName)}</span>` : ''}
                            </div>
                        </div>
                        <div class="gallery-item-actions">
                            <button class="action-btn info-btn" data-filename="${this.escapeHtml(file.filename)}">
                                View
                            </button>
                            ${this.canDeleteFile(file) ? `
                                <button class="action-btn delete-btn" data-filename="${this.escapeHtml(file.filename)}">
                                    Delete
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
        }).join('');
        
        // Setup drag and drop for custom ordering
        if (this.sortMode === 'custom') {
            this.setupDragAndDrop();
        }
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    sortFiles(files) {
        // Files are already ordered by their fractional index from the server
        // We can still allow different sort modes for display
        switch (this.sortMode) {
            case 'newest':
                return files.sort((a, b) => b.modified - a.modified);
            case 'oldest':
                return files.sort((a, b) => a.modified - b.modified);
            case 'name':
                return files.sort((a, b) => a.originalName.localeCompare(b.originalName));
            case 'custom':
            default:
                // Use the fractional ordering from the server
                return files.sort((a, b) => a.ordering.localeCompare(b.ordering));
        }
    }
    
    renderFileContent(file) {
        if (file.type === 'image') {
            const src = file.thumbnail || file.url;
            const img = document.createElement('img');
            img.src = src;
            img.alt = 'Gallery image';
            img.loading = 'lazy';
            return img.outerHTML;
        } else {
            // For videos, use a poster image approach to avoid downloading video data
            const video = document.createElement('video');
            video.preload = 'none'; // Changed from 'metadata' to 'none'
            video.muted = true;
            video.controls = false; // Remove controls from grid view
            
            // Use poster image if available
            if (file.poster) {
                video.poster = file.poster;
            } else {
                // Create a placeholder poster using canvas
                const canvas = document.createElement('canvas');
                canvas.width = 300;
                canvas.height = 200;
                const ctx = canvas.getContext('2d');
                
                // Draw gradient background
                const gradient = ctx.createLinearGradient(0, 0, 300, 200);
                gradient.addColorStop(0, '#1a1a1a');
                gradient.addColorStop(1, '#333333');
                ctx.fillStyle = gradient;
                ctx.fillRect(0, 0, 300, 200);
                
                // Draw play button
                ctx.fillStyle = '#ff6b35';
                ctx.beginPath();
                ctx.moveTo(120, 80);
                ctx.lineTo(180, 100);
                ctx.lineTo(120, 120);
                ctx.closePath();
                ctx.fill();
                
                // Add text
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 14px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('Click to load video', 150, 150);
                
                video.poster = canvas.toDataURL();
            }
            
            // Set data attribute for lazy loading
            video.setAttribute('data-video-src', file.url);
            video.classList.add('video-placeholder');
            
            return video.outerHTML;
        }
    }
    
    canDeleteFile(file) {
        // Users can delete files they uploaded
        return file.userId === this.currentUserId;
    }
    
    getDisplayName(file) {
        // Use the original name from the server response if available
        if (typeof file === 'object' && file.originalName) {
            return file.originalName;
        }
        
        // Fall back to extracting from filename string
        const filename = typeof file === 'string' ? file : file.filename;
        const parts = filename.split('_');
        if (parts.length >= 3) {
            // Remove extension from the display name and re-add it
            const nameWithExt = parts[2];
            return nameWithExt;
        }
        return filename;
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    
    formatDate(timestamp) {
        return new Date(timestamp * 1000).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }
    
    updateYearButtons() {
        document.querySelectorAll('.year-item').forEach(item => {
            item.classList.toggle('active', parseInt(item.dataset.year) === this.currentYear);
        });
        
        // Show/hide upload controls based on current year
        const currentYear = new Date().getFullYear();
        const uploadSection = document.querySelector('.upload-section');
        if (uploadSection) {
            uploadSection.style.display = this.currentYear === currentYear ? 'block' : 'none';
        }
    }
    
    setupEventListeners() {
        // Year navigation
        const yearsList = document.getElementById('yearsList');
        if (yearsList) {
            yearsList.addEventListener('click', (e) => {
                const yearItem = e.target.closest('.year-item');
                if (yearItem) {
                    const year = parseInt(yearItem.dataset.year);
                    this.loadFiles(year);
                }
            });
        }
        
        // File input change - handle multiple files
        const fileInput = document.getElementById('fileInput');
        if (fileInput) {
            let lastFileSelectionTime = 0;
            const debounceTime = 500; // 500ms debounce
            
            const handleFileChange = (files, source) => {
                const now = Date.now();
                console.log(`File input ${source} event triggered`);
                console.log('Selected files:', files.length);
                
                // Debounce multiple rapid calls
                if (now - lastFileSelectionTime < debounceTime && files.length > 0) {
                    console.log('Debouncing duplicate file selection');
                    return;
                }
                
                if (files.length > 0) {
                    lastFileSelectionTime = now;
                    this.handleFileSelection(files);
                } else {
                    // Clear upload queue if no files
                    this.uploadQueue = [];
                    this.renderUploadQueue();
                    this.updateUploadButton();
                }
            };
            
            fileInput.addEventListener('change', (e) => {
                const files = Array.from(e.target.files || []);
                handleFileChange(files, 'change');
            });
            
            // Additional mobile support - but debounced
            fileInput.addEventListener('input', (e) => {
                const files = Array.from(e.target.files || []);
                handleFileChange(files, 'input');
            });
            
            // Mobile fallback - check after focus loss
            fileInput.addEventListener('blur', () => {
                setTimeout(() => {
                    if (fileInput.files && fileInput.files.length > 0) {
                        const files = Array.from(fileInput.files);
                        handleFileChange(files, 'blur');
                    }
                }, 100);
            });
        }
        
        // File input label click handler for mobile
        const fileInputLabel = document.querySelector('.file-input-label');
        if (fileInputLabel && fileInput) {
            fileInputLabel.addEventListener('click', (e) => {
                console.log('File input label clicked');
                // Ensure the file input is triggered on mobile
                e.preventDefault();
                fileInput.click();
            });
        }
        
        // Upload button
        const uploadButton = document.getElementById('uploadButton');
        if (uploadButton) {
            uploadButton.addEventListener('click', () => {
                this.startUpload();
            });
        }
        
        // Gallery item clicks (for modal)
        const galleryGrid = document.getElementById('galleryGrid');
        if (galleryGrid) {
            galleryGrid.addEventListener('click', (e) => {
                const item = e.target.closest('.gallery-item');
                if (item && !e.target.closest('.gallery-item-actions')) {
                    const index = parseInt(item.dataset.index);
                    this.openModal(index);
                }
                
                // Handle video placeholder clicks
                const videoPlaceholder = e.target.closest('.video-placeholder');
                if (videoPlaceholder && !e.target.closest('.gallery-item-actions')) {
                    e.stopPropagation();
                    this.loadVideoOnDemand(videoPlaceholder);
                }
            });
            
            // Action buttons
            galleryGrid.addEventListener('click', (e) => {
                if (e.target.classList.contains('delete-btn')) {
                    e.stopPropagation();
                    this.deleteFile(e.target.dataset.filename);
                } else if (e.target.classList.contains('info-btn')) {
                    e.stopPropagation();
                    const item = e.target.closest('.gallery-item');
                    const index = parseInt(item.dataset.index);
                    this.openModal(index);
                }
            });
        }
        
        // Sort select
        const sortSelect = document.getElementById('sortSelect');
        if (sortSelect) {
            sortSelect.addEventListener('change', (e) => {
                this.sortMode = e.target.value;
                this.renderGallery();
            });
        }
        
        // Modal controls
        this.setupModalEventListeners();
        
        // Compression modal
        this.setupCompressionModalEventListeners();
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (document.getElementById('galleryModal')?.classList.contains('show')) {
                switch (e.key) {
                    case 'Escape':
                        this.closeModal();
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        this.previousImage();
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        this.nextImage();
                        break;
                    case ' ':
                    case 'Spacebar':
                        e.preventDefault();
                        this.toggleSlideshow();
                        break;
                    case 's':
                    case 'S':
                        e.preventDefault();
                        this.toggleSlideshow();
                        break;
                }
            }
            
            if (document.getElementById('compressionModal')?.classList.contains('show')) {
                if (e.key === 'Escape') {
                    this.closeCompressionModal();
                }
            }
        });
    }
    
    setupModalEventListeners() {
        const modal = document.getElementById('galleryModal');
        
        // Close modal
        document.getElementById('modalClose').addEventListener('click', () => {
            this.closeModal();
        });
        
        // Click outside to close
        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.classList.contains('modal-overlay')) {
                this.closeModal();
            }
        });
        
        // Navigation buttons
        document.getElementById('modalPrev').addEventListener('click', () => {
            this.previousImage();
        });
        
        document.getElementById('modalNext').addEventListener('click', () => {
            this.nextImage();
        });
        
        // Slideshow button
        document.getElementById('modalSlideshow').addEventListener('click', () => {
            this.toggleSlideshow();
        });
        
        // Download button
        document.getElementById('modalDownload').addEventListener('click', () => {
            this.downloadCurrentFile();
        });
        
        // Touch gesture support for mobile
        this.setupTouchGestures(modal);
    }
    
    /**
     * Setup touch gestures for mobile modal navigation
     */
    setupTouchGestures(modal) {
        let startX = 0;
        let startY = 0;
        let isDragging = false;
        
        const modalMedia = document.getElementById('modalMedia');
        
        const handleTouchStart = (e) => {
            if (e.touches.length === 1) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
                isDragging = false;
            }
        };
        
        const handleTouchMove = (e) => {
            if (e.touches.length === 1) {
                const currentX = e.touches[0].clientX;
                const currentY = e.touches[0].clientY;
                const deltaX = Math.abs(currentX - startX);
                const deltaY = Math.abs(currentY - startY);
                
                // Only consider horizontal swipes
                if (deltaX > 10 && deltaX > deltaY) {
                    isDragging = true;
                    e.preventDefault(); // Prevent scrolling
                }
            }
        };
        
        const handleTouchEnd = (e) => {
            if (isDragging && e.changedTouches.length === 1) {
                const endX = e.changedTouches[0].clientX;
                const deltaX = endX - startX;
                
                // Minimum swipe distance
                if (Math.abs(deltaX) > 50) {
                    if (deltaX > 0) {
                        // Swipe right - previous image
                        this.previousImage();
                    } else {
                        // Swipe left - next image
                        this.nextImage();
                    }
                }
            }
            isDragging = false;
        };
        
        // Add touch event listeners
        if (modalMedia) {
            modalMedia.addEventListener('touchstart', handleTouchStart, { passive: false });
            modalMedia.addEventListener('touchmove', handleTouchMove, { passive: false });
            modalMedia.addEventListener('touchend', handleTouchEnd, { passive: false });
        }
    }
    
    /**
     * Handle file selection and validation
     */
    handleFileSelection(files) {
        console.log('Processing', files.length, 'files');
        this.uploadQueue = [];
        
        files.forEach((file, index) => {
            const fileItem = {
                id: Date.now() + index,
                file: file,
                name: file.name,
                size: file.size,
                type: file.type,
                status: 'pending',
                error: null
            };
            
            // Validate file
            this.validateFile(fileItem);
            this.uploadQueue.push(fileItem);
        });
        
        this.renderUploadQueue();
        this.updateUploadButton();
        
        // Show confirmation notification
        if (this.uploadQueue.length > 0) {
            const validCount = this.uploadQueue.filter(item => 
                item.status === 'ready' || item.status === 'warning'
            ).length;
            
            if (validCount > 0) {
                this.showNotification(
                    `${validCount} file${validCount === 1 ? '' : 's'} selected and ready to upload`, 
                    'success'
                );
            }
        }
    }
    
    /**
     * Validate a single file
     */
    validateFile(fileItem) {
        const file = fileItem.file;
        
        // Check file type
        const allowedTypes = [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo'
        ];
        
        if (!allowedTypes.includes(file.type)) {
            fileItem.status = 'error';
            fileItem.error = 'File type not supported';
            return;
        }
        
        // Check file size
        if (file.size > this.maxFileSize) {
            fileItem.status = 'error';
            fileItem.error = 'File too large';
            fileItem.showCompressionHelp = true;
            return;
        }
        
        // Warn for large files (over 20MB)
        if (file.size > 20 * 1024 * 1024) {
            fileItem.status = 'warning';
            fileItem.error = 'Large file - consider compressing';
        } else {
            fileItem.status = 'ready';
        }
    }
    
    /**
     * Render the upload queue UI
     */
    renderUploadQueue() {
        const container = document.getElementById('selectedFiles');
        if (!container) return;
        
        if (this.uploadQueue.length === 0) {
            container.innerHTML = '';
            return;
        }
        
        container.innerHTML = this.uploadQueue.map(item => `
            <div class="file-item ${item.status}" data-id="${item.id}">
                <div class="file-info">
                    <div class="file-name" title="${this.escapeHtml(item.name)}">${this.escapeHtml(item.name)}</div>
                    <div class="file-meta">
                        <span class="${this.getFileSizeClass(item.size)}">${this.formatFileSize(item.size)}</span>
                        <span>${this.getFileTypeDisplay(item.type)}</span>
                    </div>
                </div>
                <div class="file-status ${item.status}" ${item.showCompressionHelp ? `onclick="gallery.showCompressionHelp('${item.type}')"` : ''}>
                    ${this.getStatusText(item)}
                </div>
                <div class="file-actions">
                    <button class="file-remove" onclick="gallery.removeFromQueue(${item.id})" title="Remove">×</button>
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Get file size class for styling
     */
    getFileSizeClass(size) {
        if (size > this.maxFileSize) return 'file-size-huge';
        if (size > 20 * 1024 * 1024) return 'file-size-large';
        return '';
    }
    
    /**
     * Get display text for file type
     */
    getFileTypeDisplay(type) {
        if (type.startsWith('image/')) return '📷 Image';
        if (type.startsWith('video/')) return '🎥 Video';
        return 'File';
    }
    
    /**
     * Get status text for file item
     */
    getStatusText(item) {
        switch (item.status) {
            case 'ready': return '✓ Ready';
            case 'warning': return '⚠ Large';
            case 'error': 
                if (item.showCompressionHelp) {
                    return '❌ Too Big (Help)';
                }
                return '❌ Error';
            case 'uploading': return '⏳ Uploading';
            case 'success': return '✅ Done';
            default: return item.error || 'Unknown';
        }
    }
    
    /**
     * Remove file from upload queue
     */
    removeFromQueue(fileId) {
        this.uploadQueue = this.uploadQueue.filter(item => item.id !== fileId);
        this.renderUploadQueue();
        this.updateUploadButton();
    }
    
    /**
     * Update upload button state
     */
    updateUploadButton() {
        const button = document.getElementById('uploadButton');
        if (!button) return;
        
        const validFiles = this.uploadQueue.filter(item => item.status === 'ready' || item.status === 'warning');
        
        if (validFiles.length === 0 || this.isUploading) {
            button.disabled = true;
            button.textContent = this.isUploading ? 'Uploading...' : 'Upload Selected';
        } else {
            button.disabled = false;
            
            // Check if admin is uploading on behalf of someone
            let buttonText = `Upload ${validFiles.length} File${validFiles.length === 1 ? '' : 's'}`;
            
            if (this.isAdmin) {
                const uploadOnBehalfSelect = document.getElementById('uploadOnBehalfSelect');
                if (uploadOnBehalfSelect && uploadOnBehalfSelect.value && uploadOnBehalfSelect.value !== this.currentUserId.toString()) {
                    const selectedUser = this.users.find(u => u.id.toString() === uploadOnBehalfSelect.value);
                    if (selectedUser) {
                        buttonText += ` for ${selectedUser.display_name}`;
                    }
                }
            }
            
            button.textContent = buttonText;
        }
        
        button.classList.toggle('uploading', this.isUploading);
    }
    
    /**
     * Start the upload process
     */
    async startUpload() {
        if (this.isUploading) return;
        
        const validFiles = this.uploadQueue.filter(item => item.status === 'ready' || item.status === 'warning');
        if (validFiles.length === 0) return;
        
        this.isUploading = true;
        this.updateUploadButton();
        this.showUploadProgress();
        
        let successCount = 0;
        let errorCount = 0;
        
        for (let i = 0; i < validFiles.length; i++) {
            const fileItem = validFiles[i];
            this.updateProgressCount(i + 1, validFiles.length);
            
            try {
                fileItem.status = 'uploading';
                this.renderUploadQueue();
                
                await this.uploadSingleFile(fileItem);
                fileItem.status = 'success';
                successCount++;
            } catch (error) {
                fileItem.status = 'error';
                fileItem.error = error.message;
                errorCount++;
            }
            
            this.renderUploadQueue();
            this.updateProgressBar((i + 1) / validFiles.length * 100);
        }
        
        this.isUploading = false;
        this.hideUploadProgress();
        this.updateUploadButton();
        
        // Show results
        if (successCount > 0) {
            this.showNotification(`${successCount} file${successCount === 1 ? '' : 's'} uploaded successfully! 🎉`, 'success');
            
            // Clear successful uploads from queue after a delay
            setTimeout(() => {
                this.uploadQueue = this.uploadQueue.filter(item => item.status !== 'success');
                this.renderUploadQueue();
                this.updateUploadButton();
            }, 2000);
            
            // Reload gallery
            await this.loadFiles(this.currentYear);
            await this.loadYears();
        }
        
        if (errorCount > 0) {
            this.showNotification(`${errorCount} file${errorCount === 1 ? '' : 's'} failed to upload`, 'error');
        }
        
        // Reset file input
        const fileInput = document.getElementById('fileInput');
        if (fileInput) {
            fileInput.value = '';
        }
    }
    
    /**
     * Upload a single file
     */
    async uploadSingleFile(fileItem) {
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('year', this.currentYear);
        formData.append('file', fileItem.file);
        
        // Add upload on behalf parameter for admins
        if (this.isAdmin) {
            const uploadOnBehalfSelect = document.getElementById('uploadOnBehalfSelect');
            if (uploadOnBehalfSelect && uploadOnBehalfSelect.value) {
                formData.append('uploadOnBehalf', uploadOnBehalfSelect.value);
            }
        }
        
        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        return data;
    }
    
    /**
     * Show compression help modal
     */
    showCompressionHelp(fileType) {
        const modal = document.getElementById('compressionModal');
        const imageSection = document.getElementById('imageCompressionTips');
        const videoSection = document.getElementById('videoCompressionTips');
        
        // Show appropriate section
        if (fileType.startsWith('image/')) {
            imageSection.style.display = 'block';
            videoSection.style.display = 'none';
        } else if (fileType.startsWith('video/')) {
            imageSection.style.display = 'none';
            videoSection.style.display = 'block';
        } else {
            imageSection.style.display = 'block';
            videoSection.style.display = 'block';
        }
        
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    /**
     * Close compression help modal
     */
    closeCompressionModal() {
        const modal = document.getElementById('compressionModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    /**
     * Setup compression modal event listeners
     */
    setupCompressionModalEventListeners() {
        const modal = document.getElementById('compressionModal');
        const closeBtn = document.getElementById('compressionModalClose');
        
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                this.closeCompressionModal();
            });
        }
        
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeCompressionModal();
                }
            });
        }
    }
    
    /**
     * Show upload progress
     */
    showUploadProgress() {
        const progressContainer = document.getElementById('uploadProgress');
        if (progressContainer) {
            progressContainer.style.display = 'block';
            this.updateProgressBar(0);
        }
    }
    
    /**
     * Hide upload progress
     */
    hideUploadProgress() {
        const progressContainer = document.getElementById('uploadProgress');
        if (progressContainer) {
            setTimeout(() => {
                progressContainer.style.display = 'none';
            }, 1000);
        }
    }
    
    /**
     * Update progress bar
     */
    updateProgressBar(percent) {
        const progressFill = document.getElementById('progressBarFill');
        if (progressFill) {
            progressFill.style.width = percent + '%';
        }
    }
    
    /**
     * Update progress count
     */
    updateProgressCount(current, total) {
        const progressCount = document.getElementById('progressCount');
        if (progressCount) {
            progressCount.textContent = `${current}/${total}`;
        }
    }
    
    /**
     * Load video on demand when user clicks on placeholder
     */
    loadVideoOnDemand(videoElement) {
        const videoSrc = videoElement.getAttribute('data-video-src');
        if (!videoSrc) return;
        
        // Show loading indicator
        videoElement.style.opacity = '0.5';
        
        // Create and add source element
        const source = document.createElement('source');
        source.src = videoSrc;
        source.type = 'video/mp4';
        
        source.addEventListener('loadedmetadata', () => {
            videoElement.style.opacity = '1';
            videoElement.classList.remove('video-placeholder');
            videoElement.preload = 'metadata';
        });
        
        source.addEventListener('error', () => {
            videoElement.style.opacity = '1';
            this.showNotification('Failed to load video', 'error');
        });
        
        videoElement.appendChild(source);
        videoElement.removeAttribute('data-video-src');
    }
    
    async uploadFile() {
        // This method is deprecated - use startUpload() instead
        console.warn('uploadFile() is deprecated, use startUpload() instead');
        return this.startUpload();
    }
    
    async deleteFile(filename) {
        if (!confirm('Are you sure you want to delete this file? This action cannot be undone.')) {
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('year', this.currentYear);
            formData.append('filename', filename);
            
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.showNotification('File deleted successfully', 'success');
            await this.loadFiles(this.currentYear);
            await this.loadYears(); // Update counts
            
        } catch (error) {
            this.showNotification('Delete failed: ' + error.message, 'error');
        }
    }
    
    openModal(index) {
        if (!this.displayedFiles || this.displayedFiles.length === 0) return;

        this.currentFileIndex = index;
        const file = this.displayedFiles[index];

        const modal = document.getElementById('galleryModal');
        const modalMedia = document.getElementById('modalMedia');
        const modalFilename = document.getElementById('modalFilename');
        const modalMetadata = document.getElementById('modalMetadata');
        const modalCounter = document.getElementById('modalCounter');

        // Update filename and metadata (using textContent to prevent XSS)
        modalFilename.textContent = this.getDisplayName(file.filename);
        modalMetadata.textContent = `${this.formatFileSize(file.size)} • ${this.formatDate(file.modified)}`;
        modalCounter.textContent = `${index + 1} of ${this.displayedFiles.length}`;
        
        // Load media content safely
        modalMedia.innerHTML = '';
        if (file.type === 'image') {
            const img = document.createElement('img');
            img.src = file.url;
            img.alt = 'Gallery image';
            modalMedia.appendChild(img);
        } else {
            const video = document.createElement('video');
            video.controls = true;
            video.autoplay = true;
            video.muted = true;
            video.preload = 'metadata';
            
            const source = document.createElement('source');
            source.src = file.url;
            source.type = 'video/mp4';
            
            video.appendChild(source);
            modalMedia.appendChild(video);
        }
        
        // Show/hide navigation buttons
        const prevBtn = document.getElementById('modalPrev');
        const nextBtn = document.getElementById('modalNext');
        prevBtn.style.display = this.displayedFiles.length > 1 ? 'block' : 'none';
        nextBtn.style.display = this.displayedFiles.length > 1 ? 'block' : 'none';
        
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    closeModal() {
        const modal = document.getElementById('galleryModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
        
        // Stop any playing video
        const video = modal.querySelector('video');
        if (video) {
            video.pause();
            video.currentTime = 0; // Reset video to beginning
        }
        
        // Stop slideshow when closing modal
        this.slideshow.stop();
    }
    
    previousImage() {
        if (this.displayedFiles.length <= 1) return;

        // Notify slideshow of manual navigation
        this.slideshow.onManualNavigation();

        this.currentFileIndex = (this.currentFileIndex - 1 + this.displayedFiles.length) % this.displayedFiles.length;
        this.updateModalContent();
    }

    nextImage() {
        if (this.displayedFiles.length <= 1) return;

        // Notify slideshow of manual navigation
        this.slideshow.onManualNavigation();

        this.currentFileIndex = (this.currentFileIndex + 1) % this.displayedFiles.length;
        this.updateModalContent();
    }

    updateModalContent() {
        const file = this.displayedFiles[this.currentFileIndex];
        const modalMedia = document.getElementById('modalMedia');
        const modalFilename = document.getElementById('modalFilename');
        const modalMetadata = document.getElementById('modalMetadata');
        const modalCounter = document.getElementById('modalCounter');
        
        // Update content with fade effect
        modalMedia.style.opacity = '0.5';
        
        setTimeout(() => {
            modalFilename.textContent = this.getDisplayName(file.filename);
            modalMetadata.textContent = `${this.formatFileSize(file.size)} • ${this.formatDate(file.modified)}`;
            modalCounter.textContent = `${this.currentFileIndex + 1} of ${this.displayedFiles.length}`;
            
            modalMedia.innerHTML = '';
            
            if (file.type === 'image') {
                const img = document.createElement('img');
                img.src = file.url;
                img.alt = 'Gallery image';
                img.onload = () => {
                    modalMedia.style.opacity = '1';
                    // Schedule next slide if slideshow is active
                    this.slideshow.scheduleNext();
                };
                modalMedia.appendChild(img);
            } else {
                const video = document.createElement('video');
                video.controls = true;
                video.preload = 'metadata';
                video.muted = true; // Start muted, slideshow will handle autoplay
                
                const source = document.createElement('source');
                source.src = file.url;
                source.type = 'video/mp4';
                
                video.onloadedmetadata = () => {
                    modalMedia.style.opacity = '1';
                    // Schedule next slide if slideshow is active
                    this.slideshow.scheduleNext();
                };
                
                video.appendChild(source);
                modalMedia.appendChild(video);
            }
            
            // Fallback fade-in
            setTimeout(() => {
                if (modalMedia.style.opacity !== '1') {
                    modalMedia.style.opacity = '1';
                    this.slideshow.scheduleNext();
                }
            }, 2000);
            
        }, 150);
    }
    
    toggleSlideshow() {
        this.slideshow.toggle();
    }
    
    downloadCurrentFile() {
        const file = this.displayedFiles[this.currentFileIndex];
        if (!file) return;
        
        const link = document.createElement('a');
        link.href = file.url;
        link.download = file.filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    showLoading() {
        const grid = document.getElementById('galleryGrid');
        grid.innerHTML = '<div class="loading" style="grid-column: 1 / -1;">Loading memories...</div>';
    }
    
    hideLoading() {
        const loading = document.querySelector('.loading');
        if (loading) {
            loading.remove();
        }
    }
    
    showProgress(percent) {
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');
        
        if (percent > 0) {
            progressBar.style.display = 'block';
            progressFill.style.width = percent + '%';
        }
    }
    
    hideProgress() {
        const progressBar = document.getElementById('progressBar');
        setTimeout(() => {
            progressBar.style.display = 'none';
        }, 500);
    }
    
    showNotification(message, type = 'success') {
        const container = document.getElementById('notificationContainer');
        
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        // Add icon based on type
        let icon = '';
        switch (type) {
            case 'success': icon = '✓'; break;
            case 'error': icon = '✗'; break;
            case 'info': icon = 'ℹ'; break;
        }
        
        notification.innerHTML = `
            <span style="margin-right: 8px;">${icon}</span>
            <span>${message}</span>
        `;
        
        container.appendChild(notification);
        
        // Show notification
        setTimeout(() => notification.classList.add('show'), 100);
        
        // Hide notification
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 4000);
    }
    
    setupDragAndDrop() {
        const grid = document.getElementById('galleryGrid');
        
        // Remove any existing event listeners first
        if (this.dragHandlers) {
            grid.removeEventListener('dragstart', this.dragHandlers.dragstart);
            grid.removeEventListener('dragover', this.dragHandlers.dragover);
            grid.removeEventListener('dragenter', this.dragHandlers.dragenter);
            grid.removeEventListener('dragleave', this.dragHandlers.dragleave);
            grid.removeEventListener('drop', this.dragHandlers.drop);
            grid.removeEventListener('dragend', this.dragHandlers.dragend);
        }
        
        let draggedElement = null;
        let draggedIndex = null;
        
        // Create event handler functions
        const dragstartHandler = (e) => {
            // Prevent dragging during reorder operations
            if (this.isReordering) {
                e.preventDefault();
                return;
            }
            
            if (e.target.classList.contains('gallery-item') && e.target.draggable) {
                draggedElement = e.target;
                draggedIndex = parseInt(e.target.dataset.originalIndex);
                e.target.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/html', e.target.outerHTML);
            }
        };
        
        const dragoverHandler = (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        };
        
        const dragenterHandler = (e) => {
            e.preventDefault();
            const target = e.target.closest('.gallery-item');
            if (target && target !== draggedElement && target.draggable) {
                target.classList.add('drag-over');
            }
        };
        
        const dragleaveHandler = (e) => {
            const target = e.target.closest('.gallery-item');
            if (target && !target.contains(e.relatedTarget)) {
                target.classList.remove('drag-over');
            }
        };
        
        const dropHandler = (e) => {
            e.preventDefault();
            
            // Prevent drop during reorder operations
            if (this.isReordering) {
                return;
            }
            
            const dropTarget = e.target.closest('.gallery-item');
            if (dropTarget && dropTarget !== draggedElement && dropTarget.draggable) {
                const dropIndex = parseInt(dropTarget.dataset.originalIndex);
                this.reorderFile(draggedIndex, dropIndex);
            }
            
            // Clean up visual indicators
            document.querySelectorAll('.gallery-item').forEach(item => {
                item.classList.remove('dragging', 'drag-over');
            });
        };
        
        const dragendHandler = (e) => {
            // Clean up visual indicators
            document.querySelectorAll('.gallery-item').forEach(item => {
                item.classList.remove('dragging', 'drag-over');
            });
            draggedElement = null;
            draggedIndex = null;
        };
        
        // Store references for cleanup
        this.dragHandlers = {
            dragstart: dragstartHandler,
            dragover: dragoverHandler,
            dragenter: dragenterHandler,
            dragleave: dragleaveHandler,
            drop: dropHandler,
            dragend: dragendHandler
        };
        
        // Add event listeners
        grid.addEventListener('dragstart', this.dragHandlers.dragstart);
        grid.addEventListener('dragover', this.dragHandlers.dragover);
        grid.addEventListener('dragenter', this.dragHandlers.dragenter);
        grid.addEventListener('dragleave', this.dragHandlers.dragleave);
        grid.addEventListener('drop', this.dragHandlers.drop);
        grid.addEventListener('dragend', this.dragHandlers.dragend);
    }
    
    async reorderFile(fromIndex, toIndex) {
        if (fromIndex === toIndex) return;
        
        // Prevent concurrent reorder operations
        if (this.isReordering) {
            this.showNotification('Please wait for current reorder to complete', 'warning');
            return;
        }
        
        const file = this.files[fromIndex];
        if (!file) {
            this.showNotification('File not found', 'error');
            return;
        }
        
        try {
            this.isReordering = true;
            
            const formData = new FormData();
            formData.append('action', 'reorder');
            formData.append('year', this.currentYear);
            formData.append('filename', file.filename);
            formData.append('position', toIndex);
            
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Update the filename in our local data immediately
            if (data.newFilename && data.newFilename !== file.filename) {
                file.filename = data.newFilename;
            }
            
            this.showNotification('File reordered successfully', 'success');
            
            // Reload files to get updated ordering
            await this.loadFiles(this.currentYear);
            
        } catch (error) {
            console.error('Reorder error:', error);
            this.showNotification('Reorder failed: ' + error.message, 'error');
            // Reload files on error to ensure we have correct state
            await this.loadFiles(this.currentYear);
        } finally {
            this.isReordering = false;
        }
    }
}

// Initialize gallery when page loads
document.addEventListener('DOMContentLoaded', () => {
    window.gallery = new Gallery();
});
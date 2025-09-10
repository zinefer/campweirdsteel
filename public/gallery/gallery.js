/**
 * Enhanced Gallery JavaScript
 * Handles gallery interactions with sidebar layout and advanced features
 */

class Gallery {
    constructor() {
        this.currentYear = new Date().getFullYear();
        this.files = [];
        this.selectedFile = null;
        this.currentFileIndex = 0;
        this.slideShowInterval = null;
        this.isSlideShowActive = false;
        this.viewMode = 'grid';
        this.sortMode = 'custom'; // Default to custom ordering
        this.currentUserId = window.GALLERY_CONFIG?.currentUserId || 0;
        this.isReordering = false; // Prevent concurrent reorder operations
        
        // Store references to event handlers for proper cleanup
        this.dragHandlers = null;
        
        this.init();
    }
    
    async init() {
        await this.loadYears();
        await this.loadFiles(this.currentYear);
        this.setupEventListeners();
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
                    <h3>No memories yet for ${this.currentYear}</h3>
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
        
        grid.innerHTML = sortedFiles.map((file, index) => `
            <div class="gallery-item ${this.sortMode === 'custom' ? 'draggable' : ''}" 
                 data-filename="${file.filename}" 
                 data-index="${index}"
                 data-original-index="${this.files.indexOf(file)}"
                 ${this.sortMode === 'custom' ? 'draggable="true"' : ''}>
                <div class="gallery-item-content">
                    ${this.renderFileContent(file)}
                    ${file.type === 'video' ? '<div class="video-indicator">Video</div>' : ''}
                    ${this.sortMode === 'custom' ? '<div class="drag-handle">⋮⋮</div>' : ''}
                    <div class="gallery-item-overlay">
                        <div class="gallery-item-info">
                            <!--<div class="file-name">${file.originalName || this.getDisplayName(file.filename)}</div>-->
                            <div class="file-meta">
                                ${this.formatFileSize(file.size)} • ${this.formatDate(file.modified)}
                                ${file.uploaderName ? ` • <span class="uploader-info">by ${file.uploaderName}</span>` : ''}
                            </div>
                        </div>
                        <div class="gallery-item-actions">
                            <button class="action-btn info-btn" data-filename="${file.filename}">
                                View
                            </button>
                            ${this.canDeleteFile(file) ? `
                                <button class="action-btn delete-btn" data-filename="${file.filename}">
                                    Delete
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
        
        // Setup drag and drop for custom ordering
        if (this.sortMode === 'custom') {
            this.setupDragAndDrop();
        }
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
            return `<img src="${src}" alt="Gallery image" loading="lazy">`;
        } else {
            return `
                <video preload="metadata" muted>
                    <source src="${file.url}" type="video/mp4">
                </video>
            `;
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
        document.getElementById('yearsList').addEventListener('click', (e) => {
            const yearItem = e.target.closest('.year-item');
            if (yearItem) {
                const year = parseInt(yearItem.dataset.year);
                this.loadFiles(year);
            }
        });
        
        // File input change
        document.getElementById('fileInput').addEventListener('change', (e) => {
            const file = e.target.files[0];
            const fileNameEl = document.getElementById('selectedFileName');
            const uploadBtn = document.getElementById('uploadButton');
            
            if (file) {
                fileNameEl.textContent = file.name;
                fileNameEl.style.color = '#ff6b35';
                uploadBtn.disabled = false;
            } else {
                fileNameEl.textContent = 'No file selected';
                fileNameEl.style.color = '#ccc';
                uploadBtn.disabled = true;
            }
        });
        
        // Upload button
        document.getElementById('uploadButton').addEventListener('click', () => {
            this.uploadFile();
        });
        
        // Gallery item clicks (for modal)
        document.getElementById('galleryGrid').addEventListener('click', (e) => {
            const item = e.target.closest('.gallery-item');
            if (item && !e.target.closest('.gallery-item-actions')) {
                const index = parseInt(item.dataset.index);
                this.openModal(index);
            }
        });
        
        // Action buttons
        document.getElementById('galleryGrid').addEventListener('click', (e) => {
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
        
        // Sort select
        document.getElementById('sortSelect').addEventListener('change', (e) => {
            this.sortMode = e.target.value;
            this.renderGallery();
        });
        
        // Modal controls
        this.setupModalEventListeners();
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (document.getElementById('galleryModal').classList.contains('show')) {
                switch (e.key) {
                    case 'Escape':
                        this.closeModal();
                        break;
                    case 'ArrowLeft':
                        this.previousImage();
                        break;
                    case 'ArrowRight':
                        this.nextImage();
                        break;
                    case ' ':
                        e.preventDefault();
                        this.toggleSlideshow();
                        break;
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
    }
    
    async uploadFile() {
        const fileInput = document.getElementById('fileInput');
        const file = fileInput.files[0];
        
        if (!file) {
            this.showNotification('Please select a file first', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('year', this.currentYear);
        formData.append('file', file);
        
        const uploadButton = document.getElementById('uploadButton');
        const originalText = uploadButton.textContent;
        uploadButton.disabled = true;
        uploadButton.textContent = 'Uploading...';
        
        this.showProgress(0);
        
        try {
            const xhr = new XMLHttpRequest();
            
            // Track upload progress
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    this.showProgress(percentComplete);
                }
            });
            
            const response = await new Promise((resolve, reject) => {
                xhr.onload = () => {
                    if (xhr.status === 200) {
                        resolve(xhr.responseText);
                    } else {
                        reject(new Error('Upload failed'));
                    }
                };
                xhr.onerror = () => reject(new Error('Network error'));
                xhr.open('POST', 'api.php');
                xhr.send(formData);
            });
            
            const data = JSON.parse(response);
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.showNotification('File uploaded successfully! 🎉', 'success');
            
            // Reset form
            fileInput.value = '';
            document.getElementById('selectedFileName').textContent = 'No file selected';
            document.getElementById('selectedFileName').style.color = '#ccc';
            
            // Reload gallery and years (in case this was the first file for this year)
            await this.loadFiles(this.currentYear);
            await this.loadYears();
            
        } catch (error) {
            this.showNotification('Upload failed: ' + error.message, 'error');
        } finally {
            uploadButton.disabled = false;
            uploadButton.textContent = originalText;
            this.hideProgress();
        }
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
        if (!this.files || this.files.length === 0) return;
        
        this.currentFileIndex = index;
        const file = this.files[index];
        
        const modal = document.getElementById('galleryModal');
        const modalMedia = document.getElementById('modalMedia');
        const modalFilename = document.getElementById('modalFilename');
        const modalMetadata = document.getElementById('modalMetadata');
        const modalCounter = document.getElementById('modalCounter');
        
        // Update filename and metadata
        modalFilename.textContent = this.getDisplayName(file.filename);
        modalMetadata.textContent = `${this.formatFileSize(file.size)} • ${this.formatDate(file.modified)}`;
        modalCounter.textContent = `${index + 1} of ${this.files.length}`;
        
        // Load media content
        if (file.type === 'image') {
            modalMedia.innerHTML = `<img src="${file.url}" alt="Gallery image">`;
        } else {
            modalMedia.innerHTML = `
                <video controls autoplay muted>
                    <source src="${file.url}" type="video/mp4">
                </video>
            `;
        }
        
        // Show/hide navigation buttons
        const prevBtn = document.getElementById('modalPrev');
        const nextBtn = document.getElementById('modalNext');
        prevBtn.style.display = this.files.length > 1 ? 'block' : 'none';
        nextBtn.style.display = this.files.length > 1 ? 'block' : 'none';
        
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
        }
        
        // Stop slideshow
        this.stopSlideshow();
    }
    
    previousImage() {
        if (this.files.length <= 1) return;
        
        this.currentFileIndex = (this.currentFileIndex - 1 + this.files.length) % this.files.length;
        this.updateModalContent();
    }
    
    nextImage() {
        if (this.files.length <= 1) return;
        
        this.currentFileIndex = (this.currentFileIndex + 1) % this.files.length;
        this.updateModalContent();
    }
    
    updateModalContent() {
        const file = this.files[this.currentFileIndex];
        const modalMedia = document.getElementById('modalMedia');
        const modalFilename = document.getElementById('modalFilename');
        const modalMetadata = document.getElementById('modalMetadata');
        const modalCounter = document.getElementById('modalCounter');
        
        // Update content with fade effect
        modalMedia.style.opacity = '0.5';
        
        setTimeout(() => {
            modalFilename.textContent = this.getDisplayName(file.filename);
            modalMetadata.textContent = `${this.formatFileSize(file.size)} • ${this.formatDate(file.modified)}`;
            modalCounter.textContent = `${this.currentFileIndex + 1} of ${this.files.length}`;
            
            if (file.type === 'image') {
                modalMedia.innerHTML = `<img src="${file.url}" alt="Gallery image">`;
            } else {
                modalMedia.innerHTML = `
                    <video controls autoplay muted>
                        <source src="${file.url}" type="video/mp4">
                    </video>
                `;
            }
            
            modalMedia.style.opacity = '1';
        }, 150);
    }
    
    toggleSlideshow() {
        if (this.isSlideShowActive) {
            this.stopSlideshow();
        } else {
            this.startSlideshow();
        }
    }
    
    startSlideshow() {
        if (this.files.length <= 1) return;
        
        this.isSlideShowActive = true;
        const slideshowBtn = document.getElementById('modalSlideshow');
        slideshowBtn.textContent = 'Stop Slideshow';
        
        this.slideShowInterval = setInterval(() => {
            this.nextImage();
        }, 3000); // 3 seconds per image
    }
    
    stopSlideshow() {
        this.isSlideShowActive = false;
        const slideshowBtn = document.getElementById('modalSlideshow');
        slideshowBtn.textContent = 'Slideshow';
        
        if (this.slideShowInterval) {
            clearInterval(this.slideShowInterval);
            this.slideShowInterval = null;
        }
    }
    
    downloadCurrentFile() {
        const file = this.files[this.currentFileIndex];
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
        if (!file || !this.canDeleteFile(file)) {
            this.showNotification('You can only reorder your own files', 'error');
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
    new Gallery();
});

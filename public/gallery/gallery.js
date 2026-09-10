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
     * Autoplay the current video. With sound where the browser allows it; see
     * Gallery.playVideo().
     */
    async attemptVideoAutoplay(video) {
        // The viewer has usually started it already; don't restart it.
        if (!video.paused) return;
        await this.gallery.playVideo(video);
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
            slideshowIndicator.hidden = !isActive;
        }
    }
}

class Gallery {
    static SORT_MODES = ['custom', 'newest', 'oldest', 'name'];
    static DEFAULT_SORT = 'oldest';
    static SORT_STORAGE_KEY = 'cws.gallery.sortMode';
    static DETAILS_STORAGE_KEY = 'cws.gallery.showDetails';

    constructor() {
        this.currentYear = null; // resolved from the server's year list in init()
        this.uploadYear = new Date().getFullYear();
        this.files = [];
        this.displayedFiles = []; // this.files sorted per the active sortMode; drives modal/slideshow navigation
        this.yearSummaries = [];
        this.selectedFile = null;
        this.currentFileIndex = 0;
        this.currentUserId = window.GALLERY_CONFIG?.currentUserId || 0;
        this.isAdmin = window.GALLERY_CONFIG?.isAdmin || false;
        this.users = []; // For admin upload on behalf functionality
        this.isReordering = false; // Prevent concurrent reorder operations

        // Upload queue management
        this.uploadQueue = [];
        this.isUploading = false;
        // Video gets a bigger allowance than stills because it is sliced into
        // chunks on the way up and re-encoded on the way in, so neither the
        // request size nor the stored size is what the camera produced.
        // These have to agree with MAX_FILE_SIZE / MAX_VIDEO_SIZE in
        // includes/gallery/config.php, which is what actually enforces them.
        this.maxImageSize = 50 * 1024 * 1024;   // 50MB
        this.maxVideoSize = 500 * 1024 * 1024;  // 500MB
        this.chunkSize = 5 * 1024 * 1024;       // 5MB, re-confirmed by upload_init
        this.chunkRetries = 3;

        // Videos still being re-encoded are polled for until they are ready
        this.processingPoll = null;

        // Store references to event handlers for proper cleanup
        this.dragHandlers = null;

        // Initialize improved slideshow
        this.slideshow = new ImprovedSlideshow(this);

        // Rearranging is an explicit mode, not the default sort. Dragging used
        // to be live the moment the page loaded, which made it very easy to
        // rearrange the whole camp's archive while trying to scroll it.
        this.rearranging = false;
        // Oldest first is the default view: the archive reads as the week did.
        // Whatever is picked instead is remembered, so a reload doesn't throw
        // the choice away.
        this.sortMode = this.loadSortMode();

        // Uploader / size / date under each tile. Off by default so the grid
        // is just the pictures; remembered like the sort.
        this.showDetails = this.loadShowDetails();

        // Where a hover preview had got to, so opening the video picks up
        // from the same frame instead of jumping back to the start.
        this.resumeAt = 0;

        // Element that had focus before the modal opened, so it can be restored
        this.modalReturnFocus = null;

        this.trackNavHeight();
        this.init();
    }

    /**
     * Publish the fixed nav's real height as --nav-h. The layout's top padding
     * and the sticky sidebar hang off it; a hard-coded 70px left a gap under
     * the nav, and a different one on mobile, where the nav is shorter.
     */
    trackNavHeight() {
        const nav = document.getElementById('navbar');
        if (!nav) return;

        const set = () => document.documentElement.style.setProperty(
            '--nav-h', `${nav.getBoundingClientRect().height}px`
        );
        set();
        if ('ResizeObserver' in window) {
            new ResizeObserver(set).observe(nav);
        }
    }

    loadShowDetails() {
        try {
            return localStorage.getItem(Gallery.DETAILS_STORAGE_KEY) === '1';
        } catch (e) { /* storage unavailable */ }
        return false;
    }

    saveShowDetails(show) {
        try {
            localStorage.setItem(Gallery.DETAILS_STORAGE_KEY, show ? '1' : '0');
        } catch (e) { /* storage unavailable */ }
    }

    /**
     * Play a video with sound. A click or key press that got us here counts
     * as permission, so this normally just works; when the browser still
     * refuses (e.g. a slideshow left running), fall back to playing muted
     * rather than not at all. The controls are there to unmute.
     */
    async playVideo(video) {
        video.muted = false;
        try {
            await video.play();
        } catch (error) {
            if (error.name !== 'NotAllowedError') return;
            video.muted = true;
            try {
                await video.play();
            } catch (e) { /* leave it paused; the controls still work */ }
        }
    }

    async init() {
        await this.loadYears();

        // Open on the newest year that actually has photos in it. Defaulting
        // to the calendar year meant that from January until the first upload
        // of the next burn, the gallery opened empty — and the year list now
        // always carries the current year so uploads stay reachable, so
        // "newest in the list" would have the same problem.
        const populated = this.yearSummaries.find(s => s.count > 0);
        this.currentYear = (populated || this.yearSummaries[0])?.year ?? this.uploadYear;

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
            // One cheap request. This used to fan out into a full `files`
            // request per year — each of which ran an EXIF read per image and
            // an ffmpeg call per video — purely to print counts in the sidebar.
            const response = await fetch('api.php?action=years');
            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            this.yearSummaries = data.summaries || [];
            this.renderYearsList(this.yearSummaries);
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
        container.hidden = false;
        
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
    
    renderYearsList(summaries) {
        const yearsList = document.getElementById('yearsList');
        if (!yearsList) return;

        if (summaries.length === 0) {
            yearsList.innerHTML = '<p class="years-empty">No years yet. The first upload creates one.</p>';
            return;
        }

        // Buttons, not divs: these were unreachable by keyboard.
        yearsList.innerHTML = summaries.map(item => `
            <button type="button" class="year-item ${item.year === this.currentYear ? 'active' : ''}"
                    data-year="${item.year}"
                    aria-current="${item.year === this.currentYear ? 'true' : 'false'}">
                <span class="year-number">${item.year}</span>
                <span class="year-stats">
                    <span class="year-count">${item.count} ${item.count === 1 ? 'file' : 'files'}</span>
                    <span class="year-size">${this.formatFileSize(item.bytes)}</span>
                    ${item.count > 0 ? `<span class="year-mix">${item.images} photo${item.images === 1 ? '' : 's'}, ${item.videos} video${item.videos === 1 ? '' : 's'}</span>` : ''}
                </span>
            </button>
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

            this.updateYearButtons();
            this.renderGallery();
            this.updateGalleryTitle();
            this.syncProcessingPoll();

        } catch (error) {
            this.showNotification('Failed to load files: ' + error.message, 'error');
            this.hideLoading();
        }
    }

    /**
     * Keep an eye on videos that are still being re-encoded.
     *
     * The transcode happens in a detached process with nothing to push from,
     * so the only way the tile ever stops saying "Converting" is if we ask
     * again. Polls only while something is actually outstanding, and stops as
     * soon as the last one lands.
     */
    syncProcessingPoll() {
        const stillWorking = this.files.some(file => file.processing);

        if (!stillWorking) {
            if (this.processingPoll) {
                clearInterval(this.processingPoll);
                this.processingPoll = null;
            }
            return;
        }

        if (this.processingPoll) return; // Already watching

        this.processingPoll = setInterval(() => {
            // A backgrounded tab does not need to keep asking; it will
            // refresh when it comes back into view.
            if (document.hidden) return;
            this.refreshFilesQuietly();
        }, 5000);
    }

    /**
     * Re-read the current year without the loading spinner.
     *
     * loadFiles() tears the grid down and rebuilds it, which is right for a
     * year change and very wrong every five seconds — the page would flicker
     * and lose the user's scroll position while a video encodes.
     */
    async refreshFilesQuietly() {
        try {
            const response = await fetch(`api.php?action=files&year=${this.currentYear}`);
            const data = await response.json();
            if (data.error) return;

            const wasProcessing = new Set(this.files.filter(f => f.processing).map(f => f.filename));
            const priorNames = new Set(this.files.map(f => f.filename));
            this.files = data.files;

            // Compare the sets, not their sizes. A transcode renames .mov to
            // .mp4, so if one video finished while another started converting
            // the counts would match while every tile for the finished file
            // still pointed at a filename that no longer exists.
            const stillProcessing = new Set(this.files.filter(f => f.processing).map(f => f.filename));
            const finished = [...wasProcessing].filter(name => !stillProcessing.has(name));
            const renamed = this.files.some(f => !priorNames.has(f.filename));

            if (finished.length > 0 || renamed || stillProcessing.size !== wasProcessing.size) {
                this.renderGallery();
            }

            if (finished.length > 0) {
                this.showNotification(
                    `${finished.length} video${finished.length === 1 ? ' is' : 's are'} ready to watch`,
                    'success'
                );
            }

            this.syncProcessingPoll();
        } catch (error) {
            // A failed poll is not worth bothering anyone about; the next one
            // will pick it up.
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
            this.displayedFiles = [];
            grid.innerHTML = `
                <div class="empty-gallery">
                    <h3>Nothing here yet for ${this.escapeHtml(String(this.currentYear))}</h3>
                    <p>Photos and videos you upload show up here for the rest of camp.</p>
                    <p class="empty-gallery-note">Anything from the playa works — art, builds, sunrises, the walk back at 6am.</p>
                </div>
            `;
            return;
        }

        // Apply sorting. Keep track of the order actually rendered so the modal
        // and slideshow navigate in the order the person sees.
        const sortedFiles = this.sortFiles([...this.files]);
        this.displayedFiles = sortedFiles;

        grid.innerHTML = sortedFiles.map((file, index) => {
            // A video mid-transcode is about to change name and content, so
            // it can be looked at but not opened, dragged or deleted — every
            // one of those would race the worker rewriting the file.
            const converting = !!file.processing;
            const manageable = this.canManageFile(file) && !converting;
            const draggable = this.rearranging && manageable;
            const name = this.getDisplayName(file);
            const openLabel = file.uploaderName
                ? `Open ${name}, uploaded by ${file.uploaderName}`
                : `Open ${name}`;

            const caption = (this.showDetails || draggable) ? `
                    <figcaption class="g-tile-cap">
                        ${this.showDetails ? `<span class="g-tile-text">
                            ${file.uploaderName ? `<span class="g-tile-by">${this.escapeHtml(file.uploaderName)}</span>` : ''}
                            <span class="g-tile-meta">${this.escapeHtml(this.formatDate(file.modified))} · ${this.escapeHtml(this.formatFileSize(file.size))}</span>
                        </span>` : ''}
                        ${draggable ? `<span class="g-tile-actions">
                            <button type="button" class="g-tile-grip" aria-label="${this.escapeHtml('Reorder ' + name)}" title="Drag to reorder">⋮⋮</button>
                        </span>` : ''}
                    </figcaption>` : '';

            return `
            <figure class="g-tile${converting ? ' is-converting' : ''}"
                    style="--ratio: ${this.aspectRatio(file)}"
                    data-filename="${this.escapeHtml(file.filename)}"
                    data-index="${index}"
                    ${draggable ? 'draggable="true"' : ''}>
                <div class="g-tile-inner">
                    ${this.rearranging ? `<span class="g-tile-pos" aria-hidden="true">${index + 1}</span>` : ''}
                    ${converting ? `
                    <div class="g-tile-open is-converting-body" aria-label="${this.escapeHtml(name + ' is being converted')}">
                        <span class="g-tile-noposter" aria-hidden="true"></span>
                        <span class="g-tile-badge">Converting…</span>
                    </div>` : `
                    <button type="button"
                            class="g-tile-open${file.type === 'video' ? ' is-video' : ''}"
                            data-index="${index}"
                            aria-label="${this.escapeHtml(openLabel)}">
                        ${this.renderFileContent(file)}
                        ${file.type === 'video' ? '<span class="g-tile-badge">Video</span>' : ''}
                    </button>`}
                    ${caption}
                </div>
            </figure>`;
        }).join('');

        if (this.rearranging) {
            this.setupDragAndDrop();
        }
    }

    /**
     * Width/height ratio for a tile, so photos keep the shape they were shot
     * in. Everything used to be force-cropped to a square by
     * `aspect-ratio: 1` + `object-fit: cover`.
     */
    aspectRatio(file) {
        const w = Number(file.width);
        const h = Number(file.height);
        if (w > 0 && h > 0) {
            // Clamp the extremes so one panorama can't hog a whole row, or
            // one tall screenshot shrink to a sliver.
            return Math.min(3, Math.max(0.4, w / h)).toFixed(4);
        }
        return '1.5'; // 3:2 — the commonest photo shape, and a better guess than a square
    }
    
    /**
     * Escape for interpolation into markup, including inside quoted
     * attributes. The previous textContent/innerHTML round-trip left quotes
     * untouched, which is unsafe for the aria-label and title attributes this
     * is used in.
     */
    escapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    /**
     * The sort is a per-person view preference, so it lives in localStorage
     * rather than on the server. Storage can be unavailable (private windows,
     * cookies blocked), in which case the default just applies every time.
     */
    loadSortMode() {
        try {
            const saved = localStorage.getItem(Gallery.SORT_STORAGE_KEY);
            if (saved && Gallery.SORT_MODES.includes(saved)) return saved;
        } catch (e) { /* storage unavailable */ }
        return Gallery.DEFAULT_SORT;
    }

    saveSortMode(mode) {
        try {
            localStorage.setItem(Gallery.SORT_STORAGE_KEY, mode);
        } catch (e) { /* storage unavailable */ }
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
    
    /**
     * The media inside a tile.
     *
     * Videos render as their poster image only — no <video> element in the
     * grid at all. The grid used to build a <video> per tile (plus a
     * canvas-drawn poster when none existed) and load the source in place on
     * click, which competed with the click that opens the viewer. The viewer
     * is where video plays; the grid just shows a frame.
     *
     * alt is empty by design: the button wrapping this carries the accessible
     * name, so a description here would be announced twice.
     */
    renderFileContent(file) {
        const src = file.type === 'image'
            ? (file.thumbnail || file.url)
            : file.poster;

        if (!src) {
            return '<span class="g-tile-noposter" aria-hidden="true"></span>';
        }

        const img = document.createElement('img');
        img.src = src;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        return img.outerHTML;
    }

    /**
     * Whether the current user may delete or reorder this file. Owner or admin
     * — the same rule the server enforces.
     */
    canManageFile(file) {
        return this.isAdmin || file.userId === this.currentUserId;
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

    /**
     * The value a datetime-local input wants: local wall-clock time, no zone.
     *
     * toISOString() would be UTC, which is how an evening photo ends up
     * offering to edit itself as the following morning.
     */
    formatDateTimeLocal(timestamp) {
        const d = new Date(timestamp * 1000);
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
            + `T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    /**
     * The uploader / size / date line under the filename in the viewer.
     *
     * Split out from renderModalMedia() because the date editor puts it back
     * afterwards, and the two must agree on what the line looks like.
     */
    renderModalMetadata(file) {
        const modalMetadata = document.getElementById('modalMetadata');
        if (!modalMetadata) return;

        modalMetadata.innerHTML = '';

        const parts = [
            file.uploaderName ? `by ${file.uploaderName}` : null,
            this.formatFileSize(file.size)
        ].filter(Boolean);

        if (parts.length) {
            modalMetadata.appendChild(document.createTextNode(parts.join(' · ') + ' · '));
        }

        // Your own photo's date is a button; everyone else's is just text.
        if (this.canManageFile(file) && !file.processing) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'modal-date-edit';
            button.dataset.filename = file.filename;
            button.textContent = this.formatDate(file.modified);
            button.title = 'Change this date';
            button.setAttribute('aria-label',
                `Change the date on ${this.getDisplayName(file)}, currently ${this.formatDate(file.modified)}`);
            modalMetadata.appendChild(button);
        } else {
            modalMetadata.appendChild(document.createTextNode(this.formatDate(file.modified)));
        }
    }

    /**
     * Swap the metadata line for a date picker.
     *
     * Inline rather than a second modal: this sits inside the viewer, which is
     * already a dialog, and stacking one on another traps focus in the wrong
     * place.
     */
    startDateEdit(file) {
        const modalMetadata = document.getElementById('modalMetadata');
        if (!modalMetadata) return;

        // A slideshow advancing mid-edit would swap the file out from under
        // the form and save the date onto whatever came next.
        this.slideshow.stop();

        modalMetadata.innerHTML = '';

        const form = document.createElement('form');
        form.className = 'modal-date-form';

        const input = document.createElement('input');
        input.type = 'datetime-local';
        input.className = 'modal-date-input';
        input.value = this.formatDateTimeLocal(file.modified);
        input.setAttribute('aria-label', 'Date and time this was taken');

        const save = document.createElement('button');
        save.type = 'submit';
        save.className = 'modal-date-save';
        save.textContent = 'Save';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'modal-date-cancel';
        cancel.textContent = 'Cancel';

        form.append(input, save, cancel);
        modalMetadata.appendChild(form);
        input.focus();

        cancel.addEventListener('click', () => this.renderModalMetadata(file));

        // Escape belongs to the editor while it is open, or it would close the
        // whole viewer and leave the person wondering where their photo went.
        form.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                e.stopPropagation();
                this.renderModalMetadata(file);
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!input.value) {
                this.showNotification('Pick a date first', 'error');
                return;
            }

            save.disabled = true;
            const timestamp = Math.floor(new Date(input.value).getTime() / 1000);
            await this.setFileDate(file, timestamp);
        });
    }

    /**
     * Write a new date and fold it back into what is on screen.
     */
    async setFileDate(file, timestamp) {
        try {
            const formData = new FormData();
            formData.append('action', 'set_date');
            formData.append('year', this.currentYear);
            formData.append('filename', file.filename);
            formData.append('timestamp', timestamp);

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            // Update in place rather than reloading: a reload would re-sort the
            // grid and lose the viewer's position in it.
            file.modified = data.modified;
            const listed = this.files.find(f => f.filename === file.filename);
            if (listed) listed.modified = data.modified;

            this.renderModalMetadata(file);
            this.renderGallery();

            // Under a date sort the tile has just moved, and the viewer walks
            // displayedFiles by index — without this, Next would jump to
            // whatever slid into the old position.
            const movedTo = this.displayedFiles.findIndex(f => f.filename === file.filename);
            if (movedTo !== -1) {
                this.currentFileIndex = movedTo;

                const modalCounter = document.getElementById('modalCounter');
                if (modalCounter) {
                    modalCounter.textContent =
                        `${this.currentFileIndex + 1} of ${this.displayedFiles.length}`;
                }
            }

            this.showNotification('Date updated', 'success');

        } catch (error) {
            this.showNotification('Could not change the date: ' + error.message, 'error');
            this.renderModalMetadata(file);
        }
    }
    
    updateYearButtons() {
        document.querySelectorAll('.year-item').forEach(item => {
            const isActive = parseInt(item.dataset.year, 10) === this.currentYear;
            item.classList.toggle('active', isActive);
            item.setAttribute('aria-current', isActive ? 'true' : 'false');
        });

        // Uploads are only open for the current calendar year. This used to
        // `display: none` the whole section, so the controls silently vanished
        // when you clicked a past year — which reads as a bug, not a rule.
        // Now the section stays put and explains itself.
        const canUpload = this.currentYear === this.uploadYear;
        const uploadControls = document.querySelector('.upload-controls');
        const uploadInfo = document.querySelector('.upload-info');
        const uploadClosed = document.getElementById('uploadClosed');

        if (uploadControls) uploadControls.hidden = !canUpload;
        if (uploadInfo) uploadInfo.hidden = !canUpload;

        if (uploadClosed) {
            uploadClosed.hidden = canUpload;
            uploadClosed.textContent =
                `Uploads are open for ${this.uploadYear}. Switch to ${this.uploadYear} to add photos.`;
        }
    }

    /**
     * Turn rearrange mode on or off. Reordering renames files on disk for
     * everyone in camp, so it needs a deliberate switch rather than being
     * live by default.
     */
    toggleRearrange() {
        // The other sorts are views, not the saved order, so rearranging only
        // means anything in camp order. Now that the gallery opens on oldest
        // first, refusing the click and asking for a sort change would make
        // this a two-step affair for everyone — switch on their behalf and
        // say so instead. The stored sort preference is left alone.
        if (!this.rearranging && this.sortMode !== 'custom') {
            this.sortMode = 'custom';
            const sortSelect = document.getElementById('sortSelect');
            if (sortSelect) sortSelect.value = 'custom';
            this.showNotification('Switched to camp order — that is the order you are editing.', 'info');
        }

        this.rearranging = !this.rearranging;

        const toggle = document.getElementById('rearrangeToggle');
        const hint = document.getElementById('rearrangeHint');
        const grid = document.getElementById('galleryGrid');

        if (toggle) {
            toggle.setAttribute('aria-pressed', String(this.rearranging));
            toggle.textContent = this.rearranging ? 'Done rearranging' : 'Rearrange';
        }
        if (hint) hint.hidden = !this.rearranging;
        if (grid) grid.classList.toggle('is-rearranging', this.rearranging);

        this.renderGallery();
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

        // Upload queue actions. Delegated rather than inline onclick, which
        // required a `gallery` global and interpolated values into attributes.
        const selectedFiles = document.getElementById('selectedFiles');
        if (selectedFiles) {
            selectedFiles.addEventListener('click', (e) => {
                const remove = e.target.closest('[data-remove-id]');
                if (remove) {
                    this.removeFromQueue(Number(remove.dataset.removeId));
                    return;
                }

                const help = e.target.closest('[data-compression-help]');
                if (help) {
                    this.showCompressionHelp(help.dataset.compressionHelp);
                }
            });
        }
        
        // Grid interactions. The tile only opens; delete is in the viewer.
        const galleryGrid = document.getElementById('galleryGrid');
        if (galleryGrid) {
            galleryGrid.addEventListener('click', (e) => {
                const open = e.target.closest('.g-tile-open');
                if (open) {
                    const preview = open.querySelector('.g-tile-preview');
                    this.resumeAt = preview?.currentTime || 0;
                    this.stopPreview(open);
                    this.openModal(parseInt(open.dataset.index, 10), open);
                }
            });

            this.setupHoverPreview(galleryGrid);
        }

        // Details toggle
        const detailsToggle = document.getElementById('detailsToggle');
        if (detailsToggle) {
            detailsToggle.setAttribute('aria-pressed', String(this.showDetails));
            detailsToggle.addEventListener('click', () => {
                this.showDetails = !this.showDetails;
                detailsToggle.setAttribute('aria-pressed', String(this.showDetails));
                this.saveShowDetails(this.showDetails);
                this.renderGallery();
            });
        }

        // Delete, from the viewer
        const modalDelete = document.getElementById('modalDelete');
        if (modalDelete) {
            modalDelete.addEventListener('click', () => {
                const file = this.displayedFiles[this.currentFileIndex];
                if (file) this.deleteFile(file.filename);
            });
        }

        // Rearrange toggle
        const rearrangeToggle = document.getElementById('rearrangeToggle');
        if (rearrangeToggle) {
            rearrangeToggle.addEventListener('click', () => this.toggleRearrange());
        }

        // Sort select
        const sortSelect = document.getElementById('sortSelect');
        if (sortSelect) {
            // The markup ships the default selected; a remembered choice wins.
            sortSelect.value = this.sortMode;

            sortSelect.addEventListener('change', (e) => {
                this.sortMode = e.target.value;
                this.saveSortMode(this.sortMode);

                // The other sorts are views. Leaving camp order while
                // rearranging would let a drop write an order derived from a
                // sequence nobody is actually storing.
                if (this.sortMode !== 'custom' && this.rearranging) {
                    this.rearranging = false;
                    const toggle = document.getElementById('rearrangeToggle');
                    const hint = document.getElementById('rearrangeHint');
                    if (toggle) {
                        toggle.setAttribute('aria-pressed', 'false');
                        toggle.textContent = 'Rearrange';
                    }
                    if (hint) hint.hidden = true;
                    galleryGrid?.classList.remove('is-rearranging');
                }

                this.renderGallery();
            });
        }
        
        // Modal controls
        this.setupModalEventListeners();
        
        // Compression modal
        this.setupCompressionModalEventListeners();
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            const modal = document.getElementById('galleryModal');

            if (modal?.classList.contains('show')) {
                if (e.key === 'Tab') {
                    this.trapFocus(e, modal);
                    return;
                }

                // The date editor owns its own keys. A datetime-local input
                // steps its fields with the arrows, which the viewer otherwise
                // claims for navigation, and Escape there means "stop editing"
                // rather than "close the viewer" — the form handles that one.
                if (e.target.closest?.('.modal-date-form')) {
                    return;
                }

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
                    // Space only. 'S' was a second binding for the same action,
                    // and neither was surfaced anywhere; the viewer now shows
                    // the shortcut on the button itself.
                    case ' ':
                        // Let Space reach the video's own play/pause control.
                        if (e.target.tagName === 'VIDEO' || e.target.tagName === 'BUTTON') break;
                        e.preventDefault();
                        this.toggleSlideshow();
                        break;
                }
            }

            const compression = document.getElementById('compressionModal');
            if (compression?.classList.contains('show')) {
                if (e.key === 'Tab') {
                    this.trapFocus(e, compression);
                } else if (e.key === 'Escape') {
                    this.closeCompressionModal();
                }
            }
        });
    }

    /**
     * Play a video tile in place while the pointer rests on it.
     *
     * Only for a real mouse: on touch there is no hover, and the tap goes
     * straight to the viewer. The <video> is created on hover and removed on
     * leave, so the grid still loads nothing but posters up front. A short
     * delay stops a pointer sweeping across the grid from starting a download
     * for every video it crosses.
     */
    setupHoverPreview(grid) {
        const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!canHover || reducedMotion) return;

        grid.addEventListener('pointerover', (e) => {
            const open = e.target.closest('.g-tile-open.is-video');
            if (!open || open.contains(e.relatedTarget) || this.rearranging) return;

            clearTimeout(this.previewTimer);
            this.previewTimer = setTimeout(() => {
                const file = this.displayedFiles[Number(open.dataset.index)];
                if (!file || !open.isConnected || open.querySelector('.g-tile-preview')) return;

                const video = document.createElement('video');
                video.className = 'g-tile-preview';
                video.src = file.url;
                video.muted = true; // required: hover is not permission for sound
                video.loop = true;
                video.playsInline = true;
                video.setAttribute('aria-hidden', 'true');
                video.addEventListener('playing', () => open.classList.add('is-previewing'), { once: true });
                open.appendChild(video);
                video.play().catch(() => this.stopPreview(open));
            }, 150);
        });

        grid.addEventListener('pointerout', (e) => {
            const open = e.target.closest('.g-tile-open.is-video');
            if (!open || open.contains(e.relatedTarget)) return;
            this.stopPreview(open);
        });
    }

    stopPreview(open) {
        clearTimeout(this.previewTimer);
        const video = open?.querySelector('.g-tile-preview');
        if (video) {
            video.pause();
            video.removeAttribute('src');
            video.load(); // abort the download
            video.remove();
        }
        open?.classList.remove('is-previewing');
    }

    /**
     * Keep Tab inside an open dialog. Without this, tabbing out of the viewer
     * walks through the page behind the overlay.
     */
    trapFocus(event, container) {
        const focusable = container.querySelectorAll(
            'button:not([disabled]):not([hidden]), [href], input, select, textarea, video[controls], [tabindex]:not([tabindex="-1"])'
        );
        // getClientRects() rather than offsetParent: the dialog is
        // position: fixed, which makes offsetParent unreliable inside it.
        const visible = Array.from(focusable).filter(el => el.getClientRects().length > 0);
        if (visible.length === 0) return;

        const first = visible[0];
        const last = visible[visible.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
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
        
        // The date in the metadata line is a button when the file is yours.
        // Delegated, because renderModalMetadata() rebuilds that line on every
        // navigation and a bound listener would go with it.
        document.getElementById('modalMetadata')?.addEventListener('click', (e) => {
            if (!e.target.closest('.modal-date-edit')) return;

            const file = this.displayedFiles?.[this.currentFileIndex];
            if (file) this.startDateEdit(file);
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
        if (file.size > this.maxSizeFor(file)) {
            fileItem.status = 'error';
            fileItem.error = 'File too large';
            fileItem.showCompressionHelp = true;
            return;
        }

        // Videos are re-encoded server-side, so a big one is a slow upload
        // rather than a problem — flag it as such instead of asking for a
        // compression pass that the server is about to do anyway.
        if (file.type.startsWith('video/') && file.size > 100 * 1024 * 1024) {
            fileItem.status = 'warning';
            fileItem.error = 'Large video - this will take a while';
        } else if (file.type.startsWith('image/') && file.size > 20 * 1024 * 1024) {
            fileItem.status = 'warning';
            fileItem.error = 'Large file - consider compressing';
        } else {
            fileItem.status = 'ready';
        }
    }

    /**
     * The size ceiling that applies to a given file, by kind.
     */
    maxSizeFor(file) {
        const type = typeof file === 'string' ? file : file.type;
        return type.startsWith('video/') ? this.maxVideoSize : this.maxImageSize;
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
                        <span class="${this.getFileSizeClass(item)}">${this.formatFileSize(item.size)}</span>
                        <span>${this.getFileTypeDisplay(item.type)}</span>
                    </div>
                </div>
                ${item.showCompressionHelp
                    ? `<button type="button" class="file-status ${item.status}" data-compression-help="${this.escapeHtml(item.type)}">${this.escapeHtml(this.getStatusText(item))}</button>`
                    : `<div class="file-status ${item.status}" title="${this.escapeHtml(this.getStatusText(item))}">${this.escapeHtml(this.getStatusText(item))}</div>`}
                <div class="file-actions">
                    <button type="button" class="file-remove" data-remove-id="${item.id}" aria-label="${this.escapeHtml('Remove ' + item.name)}">×</button>
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Get file size class for styling
     */
    getFileSizeClass(item) {
        const isVideo = item.type.startsWith('video/');
        if (item.size > this.maxSizeFor(item)) return 'file-size-huge';
        if (item.size > (isVideo ? 100 : 20) * 1024 * 1024) return 'file-size-large';
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
                // Say what actually went wrong. uploadFiles() records the
                // thrown message on item.error, and returning a bare "Error"
                // here threw away the only report the user ever sees — the
                // server's message ("Too many uploads are in progress", "PHP's
                // upload_max_filesize...", a failed chunk) never reached them.
                return item.error ? `❌ ${item.error}` : '❌ Error';
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
        let processingCount = 0;

        for (let i = 0; i < validFiles.length; i++) {
            const fileItem = validFiles[i];
            this.updateProgressCount(i + 1, validFiles.length);

            try {
                fileItem.status = 'uploading';
                this.renderUploadQueue();

                // Chunking gives real progress inside a single file, which
                // matters now that one of them can be 500MB. The bar advances
                // across the whole batch, so a file's own progress is scaled
                // into its slice of it.
                const result = await this.uploadSingleFile(fileItem, fraction => {
                    this.updateProgressBar((i + fraction) / validFiles.length * 100);
                });

                fileItem.status = 'success';
                successCount++;
                if (result.processing) {
                    processingCount++;
                }
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
            const note = processingCount > 0
                ? ` ${processingCount} video${processingCount === 1 ? ' is' : 's are'} still converting.`
                : '';
            this.showNotification(
                `${successCount} file${successCount === 1 ? '' : 's'} uploaded successfully! 🎉${note}`,
                'success'
            );

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
     * Upload a single file, in slices.
     *
     * Nothing is posted whole any more. The file is cut into chunks and sent
     * one at a time, which keeps every request small enough that the server's
     * body-size limits stop being the cap on what the gallery accepts — and
     * means a dropped connection costs one chunk instead of the whole upload.
     *
     * @param {object}   fileItem   Entry from this.uploadQueue
     * @param {function} onProgress Called with 0..1 for this file
     */
    async uploadSingleFile(fileItem, onProgress = () => {}) {
        const file = fileItem.file;

        // 1. Open a session. The server decides the chunk size and the id.
        const initData = new FormData();
        initData.append('action', 'upload_init');
        initData.append('year', this.currentYear);
        initData.append('filename', file.name);
        initData.append('size', file.size);

        // Add upload on behalf parameter for admins
        if (this.isAdmin) {
            const uploadOnBehalfSelect = document.getElementById('uploadOnBehalfSelect');
            if (uploadOnBehalfSelect && uploadOnBehalfSelect.value) {
                initData.append('uploadOnBehalf', uploadOnBehalfSelect.value);
            }
        }

        const session = await this.postToApi(initData);
        const chunkSize = session.chunkSize || this.chunkSize;
        const totalChunks = session.totalChunks;

        try {
            // 2. Send the slices.
            for (let index = 0; index < totalChunks; index++) {
                const start = index * chunkSize;
                const blob = file.slice(start, Math.min(start + chunkSize, file.size));

                await this.uploadChunkWithRetry(session.uploadId, index, blob);
                onProgress((index + 1) / totalChunks);
            }

            // 3. Stitch it back together and file it.
            const finalizeData = new FormData();
            finalizeData.append('action', 'upload_finalize');
            finalizeData.append('uploadId', session.uploadId);

            return await this.postToApi(finalizeData);
        } catch (error) {
            // Leaving chunks behind would keep a partial file on disk until
            // the 24h sweep; tell the server to drop them now. Best-effort:
            // the original failure is the one worth reporting.
            const abortData = new FormData();
            abortData.append('action', 'upload_abort');
            abortData.append('uploadId', session.uploadId);
            this.postToApi(abortData).catch(() => {});

            throw error;
        }
    }

    /**
     * Post one chunk, retrying a few times before giving up.
     *
     * Chunks are stored under their index rather than appended, so a retry
     * that actually did land the first time overwrites itself harmlessly.
     */
    async uploadChunkWithRetry(uploadId, index, blob) {
        let lastError = null;

        for (let attempt = 0; attempt < this.chunkRetries; attempt++) {
            if (attempt > 0) {
                // Back off a little; a flaky link is usually flaky for a moment.
                await new Promise(resolve => setTimeout(resolve, 500 * Math.pow(2, attempt - 1)));
            }

            try {
                const formData = new FormData();
                formData.append('action', 'upload_chunk');
                formData.append('uploadId', uploadId);
                formData.append('index', index);
                formData.append('chunk', blob);

                return await this.postToApi(formData);
            } catch (error) {
                lastError = error;
            }
        }

        throw new Error(`Upload stalled on part ${index + 1}: ${lastError ? lastError.message : 'no attempts were made'}`);
    }

    /**
     * POST a FormData to the API and unwrap the JSON, throwing on failure.
     *
     * A chunk that dies in transit can come back as an HTML error page rather
     * than JSON, so the parse is guarded — otherwise the retry loop would see
     * a SyntaxError and report that instead of the real problem.
     */
    async postToApi(formData) {
        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });

        let data;
        try {
            data = await response.json();
        } catch (parseError) {
            throw new Error(`Server returned ${response.status}`);
        }

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
        
        // Show the section that matches what they picked
        const isImage = fileType.startsWith('image/');
        const isVideo = fileType.startsWith('video/');
        imageSection.hidden = isVideo;
        videoSection.hidden = isImage;

        // The two kinds have different ceilings now, so the sentence naming
        // the limit is picked the same way the tips are.
        const imageNote = document.getElementById('imageLimitNote');
        const videoNote = document.getElementById('videoLimitNote');
        if (imageNote) imageNote.hidden = isVideo;
        if (videoNote) videoNote.hidden = isImage;

        this.compressionReturnFocus = document.activeElement;
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.getElementById('compressionModalClose')?.focus();
    }
    
    /**
     * Close compression help modal
     */
    closeCompressionModal() {
        const modal = document.getElementById('compressionModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';

        if (this.compressionReturnFocus && document.contains(this.compressionReturnFocus)) {
            this.compressionReturnFocus.focus();
        }
        this.compressionReturnFocus = null;
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
            progressContainer.hidden = false;
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
                progressContainer.hidden = true;
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
    
    async deleteFile(filename) {
        const file = this.files.find(f => f.filename === filename);
        const name = file ? this.getDisplayName(file) : 'this file';

        if (!confirm(`Delete ${name}? It will be removed from the gallery for everyone.`)) {
            return;
        }

        // Deleting from the viewer: close it first, as the file it shows and
        // the list it navigates are both about to change.
        if (document.getElementById('galleryModal')?.classList.contains('show')) {
            this.closeModal();
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
            
            this.showNotification('Deleted', 'success');
            await this.loadFiles(this.currentYear);
            await this.loadYears(); // Update counts
            
        } catch (error) {
            this.showNotification('Delete failed: ' + error.message, 'error');
        }
    }
    
    openModal(index, returnFocusTo = null) {
        if (!this.displayedFiles || this.displayedFiles.length === 0) return;
        if (!this.displayedFiles[index]) return;

        this.currentFileIndex = index;
        this.modalReturnFocus = returnFocusTo || document.activeElement;

        const modal = document.getElementById('galleryModal');

        // Show/hide navigation buttons
        const prevBtn = document.getElementById('modalPrev');
        const nextBtn = document.getElementById('modalNext');
        const multiple = this.displayedFiles.length > 1;
        prevBtn.hidden = !multiple;
        nextBtn.hidden = !multiple;

        modal.classList.add('show');
        document.body.style.overflow = 'hidden';

        this.renderModalMedia(this.displayedFiles[index], { schedule: false });

        // Move focus into the dialog so keyboard and screen reader users land
        // somewhere useful, and so the trap has an anchor.
        document.getElementById('modalClose')?.focus();
    }

    closeModal() {
        const modal = document.getElementById('galleryModal');
        modal.classList.remove('show');
        document.body.style.overflow = '';

        // Stop any playing video
        const video = modal.querySelector('video');
        if (video) {
            video.pause();
            video.currentTime = 0;
        }

        this.slideshow.stop();

        // Hand focus back to the tile that opened this, rather than dropping it
        // at the top of the document. A delete or a reorder can re-render the
        // grid while the viewer is open, so fall back to whatever tile now
        // sits at the same position.
        const fallback = document.querySelector(
            `.g-tile-open[data-index="${this.currentFileIndex}"]`
        );
        const target = (this.modalReturnFocus && document.contains(this.modalReturnFocus))
            ? this.modalReturnFocus
            : fallback;

        target?.focus();
        this.modalReturnFocus = null;
    }

    /**
     * Paint one file into the viewer. Shared by open and navigate so the two
     * paths cannot drift apart.
     */
    renderModalMedia(file, { schedule = true } = {}) {
        const modalMedia = document.getElementById('modalMedia');
        const modalFilename = document.getElementById('modalFilename');
        const modalMetadata = document.getElementById('modalMetadata');
        const modalCounter = document.getElementById('modalCounter');

        modalFilename.textContent = this.getDisplayName(file);
        this.renderModalMetadata(file);
        modalCounter.textContent = `${this.currentFileIndex + 1} of ${this.displayedFiles.length}`;

        const modalDelete = document.getElementById('modalDelete');
        if (modalDelete) {
            modalDelete.hidden = !(this.canManageFile(file) && !file.processing);
        }

        modalMedia.innerHTML = '';

        const done = () => {
            modalMedia.style.opacity = '1';
            if (schedule) this.slideshow.scheduleNext();
        };

        if (file.type === 'image') {
            const img = document.createElement('img');
            img.src = file.url;
            img.alt = this.getDisplayName(file);
            img.onload = done;
            img.onerror = done;
            modalMedia.appendChild(img);
        } else {
            const video = document.createElement('video');
            video.controls = true;
            video.preload = 'auto';
            video.playsInline = true;

            const source = document.createElement('source');
            source.src = file.url;
            source.type = 'video/mp4';

            // Pick up where the hover preview was, if it got going.
            const resumeAt = this.resumeAt;
            this.resumeAt = 0;

            video.onloadedmetadata = () => {
                if (resumeAt > 0 && resumeAt < video.duration) {
                    video.currentTime = resumeAt;
                }
                done();
            };
            video.onerror = done;

            video.appendChild(source);
            modalMedia.appendChild(video);

            // Opening a video plays it, with sound.
            this.playVideo(video);
        }

        // Safety net if neither load nor error fires (cached media in some
        // browsers reports neither).
        setTimeout(() => {
            if (modalMedia.style.opacity !== '1') done();
        }, 2000);
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
        if (!file) return;

        const modalMedia = document.getElementById('modalMedia');
        modalMedia.style.opacity = '0.5';

        setTimeout(() => this.renderModalMedia(file), 150);
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
        grid.innerHTML = '<div class="loading">Loading…</div>';
    }
    
    hideLoading() {
        const loading = document.querySelector('.loading');
        if (loading) {
            loading.remove();
        }
    }
    
    showNotification(message, type = 'success') {
        const container = document.getElementById('notificationContainer');
        if (!container) return;

        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        // Announce without stealing focus. Errors interrupt; the rest wait.
        notification.setAttribute('role', type === 'error' ? 'alert' : 'status');

        const icons = { success: '✓', error: '✗', warning: '!', info: 'i' };

        const icon = document.createElement('span');
        icon.className = 'notification-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = icons[type] || '';

        // Built with textContent rather than innerHTML. Every other render path
        // in this file escapes; this one interpolated the message raw, which
        // would have become a hole the first time a filename or a user-supplied
        // field reached an error string.
        const text = document.createElement('span');
        text.textContent = message;

        notification.append(icon, text);
        container.appendChild(notification);

        requestAnimationFrame(() => notification.classList.add('show'));

        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 4000);
    }
    
    /**
     * Drag to reorder, with the grid itself as the preview.
     *
     * The dragged tile is moved through the grid as the pointer crosses its
     * neighbours, so what is on screen mid-drag is exactly what a drop saves.
     * Highlighting the tile under the pointer (what this used to do) says
     * which tile you are over but not which side of it you land on, which is
     * the part that was impossible to guess. The numbered badges renumber as the
     * preview moves, so the landing position is also readable as a number.
     */
    setupDragAndDrop() {
        const grid = document.getElementById('galleryGrid');
        if (!grid) return;

        // Remove any existing event listeners first
        if (this.dragHandlers) {
            Object.entries(this.dragHandlers).forEach(([type, handler]) => {
                grid.removeEventListener(type, handler);
            });
        }

        let draggedTile = null;
        let originIndex = -1;

        const tiles = () => Array.from(grid.querySelectorAll('.g-tile'));

        const renumber = () => {
            tiles().forEach((tile, i) => {
                const badge = tile.querySelector('.g-tile-pos');
                if (badge) badge.textContent = String(i + 1);
            });
        };

        const clearMarks = () => {
            grid.querySelectorAll('.g-tile').forEach(tile => {
                tile.classList.remove('dragging');
            });
            grid.classList.remove('is-dragging');
            draggedTile = null;
            originIndex = -1;
        };

        const handlers = {
            dragstart: (e) => {
                const tile = e.target.closest('.g-tile');
                if (this.isReordering || !tile || tile.getAttribute('draggable') !== 'true') {
                    e.preventDefault();
                    return;
                }
                draggedTile = tile;
                originIndex = tiles().indexOf(tile);
                tile.classList.add('dragging');
                grid.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                // A plain-text payload. The old code stuffed the tile's full
                // outerHTML into the transfer, which is never read back.
                e.dataTransfer.setData('text/plain', tile.dataset.filename || '');
            },

            dragover: (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (!draggedTile) return;

                const target = e.target.closest('.g-tile');
                if (!target || target === draggedTile) return;

                // Past the middle of the tile under the pointer means landing
                // after it, before the middle means landing in front of it.
                const rect = target.getBoundingClientRect();
                const after = (e.clientX - rect.left) > rect.width / 2;
                const ref = after ? target.nextElementSibling : target;
                if (ref === draggedTile) return;

                grid.insertBefore(draggedTile, ref);
                renumber();
            },

            drop: (e) => {
                e.preventDefault();
                if (!draggedTile || this.isReordering) {
                    clearMarks();
                    return;
                }

                // Rearranging is only available in camp order, so the rendered
                // position the preview settled on is the position to store.
                const from = originIndex;
                const to = tiles().indexOf(draggedTile);
                clearMarks();

                if (from >= 0 && to >= 0 && from !== to) {
                    this.reorderFile(from, to);
                }
            },

            dragend: () => {
                // Only reached without a drop when the drag was cancelled or
                // released outside the grid: the preview has moved the tile,
                // so re-render from the order actually stored.
                if (draggedTile) {
                    clearMarks();
                    this.renderGallery();
                    return;
                }
                clearMarks();
            }
        };

        this.dragHandlers = handlers;
        Object.entries(handlers).forEach(([type, handler]) => {
            grid.addEventListener(type, handler);
        });
    }

    async reorderFile(fromIndex, toIndex) {
        if (fromIndex === toIndex) return;

        if (this.isReordering) {
            this.showNotification('Still saving the last move — try again in a moment.', 'warning');
            return;
        }

        const file = this.displayedFiles[fromIndex];
        if (!file) {
            this.showNotification('That photo is no longer in the list. Reloading.', 'error');
            await this.loadFiles(this.currentYear);
            return;
        }

        if (!this.canManageFile(file)) {
            this.showNotification('You can only rearrange photos you uploaded.', 'warning');
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

            this.showNotification('Moved', 'success');
            await this.loadFiles(this.currentYear);

        } catch (error) {
            console.error('Reorder error:', error);
            this.showNotification('Could not move that photo: ' + error.message, 'error');
            // Reload on error so the grid reflects what the server actually has
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
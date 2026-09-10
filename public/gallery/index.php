<?php
/**
 * Gallery Main Page
 */

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once '../../includes/gallery/auth.php';
require_once '../../includes/header.php';
require_once '../../includes/navigation.php';

// Require authentication
$auth = new GalleryAuth();
$auth->requireAuth();

$user = $auth->getUser();

// Render header and navigation
renderHeader("Gallery - Weird Steel", [
    'gallery.css'
]);
renderNavigation('gallery');
?>

    <!-- Main Gallery Layout -->
    <div class="gallery-layout">
        <!-- Sidebar -->
        <aside class="gallery-sidebar">
            <div class="sidebar-header">
                <h2 class="sidebar-title">Gallery</h2>
                <p class="sidebar-subtitle">
                    Welcome back, <span class="user-name"><?php echo htmlspecialchars($user->display_name); ?></span>
                </p>
            </div>

            <!-- Upload Section. Leads the sidebar now: the "About This Gallery"
                 paragraph that used to sit above it explained a gallery to
                 people who were already signed in and looking at one. -->
            <div class="sidebar-section upload-section">
                <h3 class="section-title">Upload</h3>

                <p class="upload-closed" id="uploadClosed" hidden></p>

                <div class="upload-controls">
                    <!-- Upload on behalf dropdown (admin only) -->
                    <div class="upload-on-behalf" id="uploadOnBehalfContainer" hidden>
                        <label for="uploadOnBehalfSelect" class="upload-behalf-label">Upload on behalf of</label>
                        <select id="uploadOnBehalfSelect" class="upload-behalf-select">
                            <option value="">Loading members…</option>
                        </select>
                    </div>

                    <div class="file-input-wrapper">
                        <input type="file" id="fileInput" class="file-input"
                               accept="image/*,video/*" multiple>
                        <label for="fileInput" class="file-input-label">
                            Choose files
                        </label>
                    </div>
                    <div class="selected-files" id="selectedFiles">
                        <!-- Selected files will appear here -->
                    </div>
                    <button type="button" id="uploadButton" class="upload-button" disabled>
                        Upload selected
                    </button>
                </div>

                <p class="upload-info">Photos up to 50MB, video up to 500MB. JPG, PNG, WebP, MP4, WebM, MOV. Video is converted for playback after upload.</p>

                <!-- Upload Progress -->
                <div class="upload-progress" id="uploadProgress" hidden>
                    <div class="progress-header">
                        <span class="progress-text">Uploading…</span>
                        <span class="progress-count" id="progressCount">0/0</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" id="progressBarFill"></div>
                    </div>
                </div>
            </div>

            <!-- Years Navigation. The active row carries the year and its
                 counts, so the separate "Currently Viewing" panel that
                 repeated both has been removed. -->
            <nav class="sidebar-section" aria-label="Gallery years">
                <h3 class="section-title">Years</h3>
                <div class="years-list" id="yearsList">
                    <!-- Years will be loaded dynamically -->
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="gallery-main">
            <!-- Gallery Header -->
            <div class="gallery-header">
                <h1 class="gallery-main-title">
                    <span id="galleryYearTitle">2024</span> Memories
                </h1>
            </div>

            <!-- Gallery Controls -->
            <div class="gallery-controls">
                <div class="sort-options">
                    <label class="sort-label" for="sortSelect">Sort</label>
                    <select id="sortSelect" class="sort-select">
                        <option value="custom">Camp order</option>
                        <option value="newest">Newest first</option>
                        <option value="oldest" selected>Oldest first</option>
                        <option value="name">By name</option>
                    </select>
                </div>

                <div class="view-toggles">
                    <!-- Uploader, date and size stay off the grid unless asked
                         for; the pictures are the content. -->
                    <button type="button" id="detailsToggle" class="rearrange-toggle" aria-pressed="false">
                        Details
                    </button>
                    <button type="button" id="rearrangeToggle" class="rearrange-toggle" aria-pressed="false">
                        Rearrange
                    </button>
                </div>
            </div>

            <p class="rearrange-hint" id="rearrangeHint" hidden>
                Drag photos to change the order everyone sees — the grid shifts as you drag, so the gap under the pointer is where it lands. You can move the ones you uploaded.
            </p>

            <!-- Gallery Grid -->
            <div class="gallery-content">
                <div class="gallery-grid" id="galleryGrid">
                    <!-- Files will be loaded dynamically -->
                </div>
            </div>
        </main>
    </div>

    <!-- Compression Guide Modal -->
    <div class="compression-modal" id="compressionModal" role="dialog" aria-modal="true" aria-labelledby="compressionModalTitle">
        <div class="compression-modal-content">
            <button type="button" class="compression-modal-close" id="compressionModalClose" aria-label="Close">&times;</button>
            
            <div class="compression-modal-header">
                <span class="compression-modal-icon" aria-hidden="true">🗜️</span>
                <h3 id="compressionModalTitle">That file is too large</h3>
            </div>
            
            <div class="compression-section">
                <p id="imageLimitNote" hidden>The limit is <strong>50MB</strong> per photo. Here is how to get under it:</p>
                <p id="videoLimitNote" hidden>The limit is <strong>500MB</strong> per video. Camp converts every video after upload, so you do not need to worry about format or resolution — only about getting under the size.</p>
            </div>

            <div class="compression-section" id="imageCompressionTips" hidden>
                <h4>📸 Image Compression Tips</h4>
                <ul class="compression-tips">
                    <li><strong>Reduce quality:</strong> Export at 80-90% quality instead of 100%</li>
                    <li><strong>Resize dimensions:</strong> Consider 2048px width for web sharing</li>
                    <li><strong>Use WebP:</strong> Modern format that's 25-30% smaller than JPEG</li>
                    <li><strong>Strip metadata:</strong> Remove EXIF data to save space</li>
                </ul>
                
                <div class="tool-links">
                    <a href="https://squoosh.app/" target="_blank" class="tool-link">Squoosh (Web)</a>
                    <a href="https://tinypng.com/" target="_blank" class="tool-link">TinyPNG</a>
                    <a href="https://compressor.io/" target="_blank" class="tool-link">Compressor.io</a>
                </div>
            </div>
            
            <div class="compression-section" id="videoCompressionTips" hidden>
                <h4>🎥 Video Compression Tips</h4>
                <ul class="compression-tips">
                    <li><strong>Trim length:</strong> The one that always helps — share the highlight, not the whole walk</li>
                    <li><strong>Lower resolution:</strong> Record or export at 1080p instead of 4K</li>
                    <li><strong>Reduce bitrate:</strong> Use 2-8 Mbps for good quality/size balance</li>
                </ul>
                <p class="compression-note">Camp re-encodes to 1080p H.264 on its own, so converting the format yourself is not worth the trouble — only shrinking it below 500MB is.</p>
                
                <div class="tool-links">
                    <a href="https://www.media.io/video-compressor.html" target="_blank" class="tool-link">Media.io</a>
                    <a href="https://handbrake.fr/" target="_blank" class="tool-link">HandBrake (Free)</a>
                    <a href="https://clideo.com/compress-video" target="_blank" class="tool-link">Clideo</a>
                </div>
            </div>
            
            <div class="compression-section">
                <h4>💡 Pro Tips</h4>
                <ul class="compression-tips">
                    <li><strong>Batch processing:</strong> Most tools can compress multiple files at once</li>
                    <li><strong>Keep originals:</strong> Always save a backup of your original files</li>
                    <li><strong>Preview first:</strong> Check quality before uploading</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Full-size viewer -->
    <div class="modal" id="galleryModal" role="dialog" aria-modal="true" aria-labelledby="modalFilename">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-info">
                    <span class="modal-filename" id="modalFilename"></span>
                    <span class="modal-metadata" id="modalMetadata"></span>
                    <p class="slideshow-indicator" id="slideshowIndicator" hidden>Slideshow running</p>
                </div>
                <button type="button" class="modal-close" id="modalClose" aria-label="Close viewer">&times;</button>
            </div>

            <div class="modal-body">
                <!-- These carry their glyph in a CSS ::before, so without an
                     explicit label a screen reader announces only "button". -->
                <button type="button" class="modal-nav modal-prev" id="modalPrev" aria-label="Previous"></button>
                <div class="modal-media" id="modalMedia">
                    <!-- Media content will be loaded dynamically -->
                </div>
                <button type="button" class="modal-nav modal-next" id="modalNext" aria-label="Next"></button>
            </div>

            <div class="modal-footer">
                <div class="modal-controls">
                    <div class="modal-actions">
                        <button type="button" class="modal-btn" id="modalSlideshow">
                            <span>Slideshow</span>
                            <kbd class="modal-key">Space</kbd>
                        </button>
                        <button type="button" class="modal-btn modal-btn-quiet" id="modalDownload">
                            <span>Download</span>
                        </button>
                    </div>
                    <p class="modal-counter" id="modalCounter">1 of 1</p>
                    <!-- Delete lives here, not on the grid: a tile is a big tap
                         target, and a delete button on every one of them was
                         one slip away from removing someone's photo. -->
                    <button type="button" class="modal-btn modal-btn-danger" id="modalDelete" hidden>
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification System -->
    <div class="notification-container" id="notificationContainer" aria-live="polite"></div>

    <script>
        // Pass current user ID and admin status to JavaScript
        window.GALLERY_CONFIG = {
            currentUserId: <?php echo json_encode($user->id); ?>,
            isAdmin: <?php echo json_encode($auth->isAdmin()); ?>
        };
    </script>
    <script src="../assets/js/main.js"></script>
    <script src="gallery.js"></script>
    
    <?php require_once '../../includes/fire-footer.php'; ?>
    
<?php require_once '../../includes/footer.php'; ?>
</body>
</html>

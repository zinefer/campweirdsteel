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
                    Welcome back, <span class="user-name"><?php echo htmlspecialchars($user->display_name); ?></span>!
                </p>
            </div>

            <!-- Guide Section -->
            <div class="sidebar-section">
                <h3 class="section-title">About This Gallery</h3>
                <p class="section-text">
                    Share your Burning Man memories with the Weird Steel camp family. 
                    Upload photos and videos from your adventures on the playa.
                </p>
            </div>

            <!-- Upload Section -->
            <div class="sidebar-section upload-section">
                <h3 class="section-title">Upload Media</h3>
                <div class="upload-controls">
                    <!-- Upload on behalf dropdown (admin only) -->
                    <div class="upload-on-behalf" id="uploadOnBehalfContainer" style="display: none;">
                        <label for="uploadOnBehalfSelect" class="upload-behalf-label">Upload on behalf of:</label>
                        <select id="uploadOnBehalfSelect" class="upload-behalf-select">
                            <option value="">Loading users...</option>
                        </select>
                    </div>
                    
                    <div class="file-input-wrapper">
                        <input type="file" id="fileInput" class="file-input" 
                               accept="image/*,video/*" multiple>
                        <label for="fileInput" class="file-input-label">
                            📷 Choose Files
                        </label>
                    </div>
                    <div class="selected-files" id="selectedFiles">
                        <!-- Selected files will appear here -->
                    </div>
                    <button id="uploadButton" class="upload-button" disabled>
                        Upload Selected
                    </button>
                </div>
                
                <div class="upload-info">
                    <small>Max 50MB per file • JPG, PNG, WebP, MP4, WebM</small>
                </div>
                
                <!-- Upload Progress -->
                <div class="upload-progress" id="uploadProgress" style="display: none;">
                    <div class="progress-header">
                        <span class="progress-text">Uploading...</span>
                        <span class="progress-count" id="progressCount">0/0</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" id="progressBarFill"></div>
                    </div>
                </div>
            </div>

            <!-- Years Navigation -->
            <div class="sidebar-section">
                <h3 class="section-title">Browse by Year</h3>
                <div class="years-list" id="yearsList">
                    <!-- Years will be loaded dynamically -->
                </div>
            </div>

            <!-- Current Selection Info -->
            <div class="sidebar-section current-selection">
                <h3 class="section-title">Currently Viewing</h3>
                <div class="current-year" id="currentYearInfo">
                    <span class="current-year-number" id="currentYear">2024</span>
                    <span class="year-stats" id="currentYearStats">Loading...</span>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="gallery-main">
            <!-- Gallery Header -->
            <div class="gallery-header">
                <h1 class="gallery-main-title">
                    <span id="galleryYearTitle">2024</span> Memories
                </h1>
                <p class="gallery-description">
                    Relive the magic, creativity, and community spirit of our playa adventures.
                </p>
            </div>

            <!-- Gallery Controls -->
            <div class="gallery-controls">
                <div class="sort-options">
                    <select id="sortSelect" class="sort-select">
                        <option value="custom" selected>Custom Order</option>
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="name">By Name</option>
                    </select>
                </div>
            </div>

            <!-- Gallery Grid -->
            <div class="gallery-content">
                <div class="gallery-grid" id="galleryGrid">
                    <!-- Files will be loaded dynamically -->
                </div>
            </div>
        </main>
    </div>

    <!-- Compression Guide Modal -->
    <div class="compression-modal" id="compressionModal">
        <div class="compression-modal-content">
            <button class="compression-modal-close" id="compressionModalClose">&times;</button>
            
            <div class="compression-modal-header">
                <span class="compression-modal-icon">🗜️</span>
                <h3>File Too Large - Compression Help</h3>
            </div>
            
            <div class="compression-section">
                <h4>Your file is too large for upload</h4>
                <p>The maximum file size is <strong>50MB</strong>. Here's how to compress your media:</p>
            </div>
            
            <div class="compression-section" id="imageCompressionTips" style="display: none;">
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
            
            <div class="compression-section" id="videoCompressionTips" style="display: none;">
                <h4>🎥 Video Compression Tips</h4>
                <ul class="compression-tips">
                    <li><strong>Lower resolution:</strong> Try 1080p instead of 4K for web sharing</li>
                    <li><strong>Reduce bitrate:</strong> Use 2-8 Mbps for good quality/size balance</li>
                    <li><strong>Use H.264:</strong> Best compatibility and compression</li>
                    <li><strong>Trim length:</strong> Share shorter clips or highlights</li>
                    <li><strong>Remove audio:</strong> If not needed, audio tracks add significant size</li>
                </ul>
                
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

    <!-- Enhanced Modal for full-size viewing -->
    <div class="modal" id="galleryModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-info">
                    <span class="modal-filename" id="modalFilename"></span>
                    <span class="modal-metadata" id="modalMetadata"></span>
                    <div class="slideshow-indicator" id="slideshowIndicator" style="display: none;">
                        <span>📽️ Slideshow Active</span>
                    </div>
                </div>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            
            <div class="modal-body">
                <button class="modal-nav modal-prev" id="modalPrev"></button>
                <div class="modal-media" id="modalMedia">
                    <!-- Media content will be loaded dynamically -->
                </div>
                <button class="modal-nav modal-next" id="modalNext"></button>
            </div>
            
            <div class="modal-footer">
                <div class="modal-controls">
                    <button class="modal-btn" id="modalSlideshow">
                        <span>Slideshow</span>
                    </button>
                    <button class="modal-btn" id="modalDownload">
                        <span>Download</span>
                    </button>
                    <div class="modal-counter">
                        <span id="modalCounter">1 of 1</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-bar" id="progressBar" style="display: none;">
        <div class="progress-fill" id="progressFill"></div>
    </div>

    <!-- Notification System -->
    <div class="notification-container" id="notificationContainer"></div>

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

<?php
/**
 * Gallery Main Page
 */

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
                    <div class="file-input-wrapper">
                        <input type="file" id="fileInput" class="file-input" 
                               accept="image/*,video/*" multiple="false">
                        <label for="fileInput" class="file-input-label">
                            Choose File
                        </label>
                    </div>
                    <div class="selected-file" id="selectedFileName">
                        No file selected
                    </div>
                    <button id="uploadButton" class="upload-button" disabled>
                        Upload
                    </button>
                </div>
                
                <div class="upload-info">
                    <small>Max 50MB • JPG, PNG, WebP, MP4, WebM</small>
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

    <!-- Enhanced Modal for full-size viewing -->
    <div class="modal" id="galleryModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-info">
                    <span class="modal-filename" id="modalFilename"></span>
                    <span class="modal-metadata" id="modalMetadata"></span>
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
        // Pass current user ID to JavaScript
        window.GALLERY_CONFIG = {
            currentUserId: <?php echo json_encode($user->id); ?>
        };
    </script>
    <script src="../assets/js/main.js"></script>
    <script src="gallery.js"></script>
    
    <?php require_once '../../includes/fire-footer.php'; ?>
    
<?php require_once '../../includes/footer.php'; ?>
</body>
</html>

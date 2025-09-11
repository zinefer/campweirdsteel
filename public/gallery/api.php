<?php
/**
 * Gallery API
 * Handles AJAX requests for gallery operations
 */

require_once '../../includes/gallery/auth.php';
require_once '../../includes/gallery/manager.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

// Require authentication
$auth = new GalleryAuth();
$auth->requireAuth();

$user = $auth->getUser();
$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    switch ($action) {
        case 'years':
            echo json_encode(['years' => GalleryConfig::getAvailableYears()]);
            break;
            
        case 'files':
            $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
            if (!$year || $year < 2000 || $year > 3000) {
                throw new Exception('Invalid year');
            }
            
            $files = GalleryManager::getFiles($year);
            
            // Add thumbnails/posters and enhanced metadata for all media types
            foreach ($files as &$file) {
                if ($file['type'] === 'image') {
                    $file['thumbnail'] = GalleryManager::generateThumbnail($year, $file['filename']);
                    
                    // Try to extract EXIF data for better metadata
                    $exifData = GalleryManager::getImageMetadata($year, $file['filename']);
                    if ($exifData) {
                        $file = array_merge($file, $exifData);
                    }
                } else {
                    // For videos, try to generate poster image
                    $file['poster'] = GalleryManager::generateVideoPoster($year, $file['filename']);
                }
            }
            
            echo json_encode(['files' => $files]);
            break;
            
        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            if (!$year || $year < 2000 || $year > 3000) {
                throw new Exception('Invalid year');
            }
            
            if (!$auth->canUploadForYear($year)) {
                throw new Exception('Cannot upload for this year');
            }
            
            if (!isset($_FILES['file'])) {
                throw new Exception('No file uploaded');
            }
            
            // Handle single file upload
            $uploadedFile = $_FILES['file'];
            
            // Additional validation for file size
            if ($uploadedFile['size'] > 50 * 1024 * 1024) { // 50MB
                throw new Exception('File too large. Maximum size is 50MB. Consider compressing your file first.');
            }
            
            // Validate file type more strictly
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'webm', 'mov', 'avi'];
            $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extension, $allowedExtensions)) {
                throw new Exception('File type not allowed. Please use: ' . implode(', ', $allowedExtensions));
            }
            
            $filename = GalleryManager::uploadFile($year, $uploadedFile, $user->id);
            
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'message' => 'File uploaded successfully',
                'size' => $uploadedFile['size'],
                'type' => $extension
            ]);
            break;
            
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
            
            if (!$year || $year < 2000 || $year > 3000) {
                throw new Exception('Invalid year');
            }
            
            if (!$filename || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
                throw new Exception('Invalid filename');
            }
            
            GalleryManager::deleteFile($year, $filename, $user->id);
            
            echo json_encode([
                'success' => true,
                'message' => 'File deleted successfully'
            ]);
            break;
            
        case 'reorder':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
            $newPosition = isset($_POST['position']) ? (int)$_POST['position'] : -1;
            
            if (!$year || $year < 2000 || $year > 3000) {
                throw new Exception('Invalid year');
            }
            
            if (!$filename || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
                throw new Exception('Invalid filename');
            }
            
            if ($newPosition < 0 || $newPosition > 10000) { // reasonable upper limit
                throw new Exception('Invalid position');
            }
            
            $newFilename = GalleryManager::reorderFile($year, $filename, $newPosition, $user->id);
            
            echo json_encode([
                'success' => true,
                'newFilename' => $newFilename,
                'message' => 'File reordered successfully'
            ]);
            break;
            
        case 'regenerate_thumbnails':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            if (!$year || $year < 2000 || $year > 3000) {
                throw new Exception('Invalid year');
            }
            
            // Only allow admins or specific users to regenerate thumbnails
            // For now, allow any authenticated user, but you might want to restrict this
            
            $results = GalleryManager::regenerateThumbnails($year);
            
            echo json_encode([
                'success' => true,
                'results' => $results,
                'message' => "Regenerated thumbnails: {$results['succeeded']} succeeded, {$results['failed']} failed"
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

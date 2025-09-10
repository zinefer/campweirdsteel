<?php
/**
 * Gallery API
 * Handles AJAX requests for gallery operations
 */

require_once '../../includes/gallery/auth.php';
require_once '../../includes/gallery/manager.php';

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
            $year = (int)($_GET['year'] ?? 0);
            if (!$year) {
                throw new Exception('Year required');
            }
            
            $files = GalleryManager::getFiles($year);
            
            // Add thumbnail URLs and enhanced metadata for images
            foreach ($files as &$file) {
                if ($file['type'] === 'image') {
                    $file['thumbnail'] = GalleryManager::generateThumbnail($year, $file['filename']);
                    
                    // Try to extract EXIF data for better metadata
                    $exifData = GalleryManager::getImageMetadata($year, $file['filename']);
                    if ($exifData) {
                        $file = array_merge($file, $exifData);
                    }
                }
            }
            
            echo json_encode(['files' => $files]);
            break;
            
        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $year = (int)($_POST['year'] ?? 0);
            if (!$year) {
                throw new Exception('Year required');
            }
            
            if (!$auth->canUploadForYear($year)) {
                throw new Exception('Cannot upload for this year');
            }
            
            if (!isset($_FILES['file'])) {
                throw new Exception('No file uploaded');
            }
            
            $filename = GalleryManager::uploadFile($year, $_FILES['file'], $user->id);
            
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'message' => 'File uploaded successfully'
            ]);
            break;
            
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $year = (int)($_POST['year'] ?? 0);
            $filename = $_POST['filename'] ?? '';
            
            if (!$year || !$filename) {
                throw new Exception('Year and filename required');
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
            
            $year = (int)($_POST['year'] ?? 0);
            $filename = $_POST['filename'] ?? '';
            $newPosition = (int)($_POST['position'] ?? -1);
            
            if (!$year || !$filename || $newPosition < 0) {
                throw new Exception('Year, filename, and position required');
            }
            
            $newFilename = GalleryManager::reorderFile($year, $filename, $newPosition, $user->id);
            
            echo json_encode([
                'success' => true,
                'newFilename' => $newFilename,
                'message' => 'File reordered successfully'
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

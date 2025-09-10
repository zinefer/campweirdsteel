<?php
/**
 * Gallery File Server
 * Serves images/videos with authentication
 */

require_once '../../includes/gallery/auth.php';
require_once '../../includes/gallery/manager.php';

// Require authentication
$auth = new GalleryAuth();
$auth->requireAuth();

// Get parameters
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$filename = isset($_GET['file']) ? $_GET['file'] : null;

if (!$year || !$filename) {
    http_response_code(400);
    exit('Missing parameters');
}

try {
    $filePath = GalleryManager::serveFile($year, $filename);
    
    // Get file info
    $filesize = filesize($filePath);
    $mimeType = mime_content_type($filePath);
    
    // Set headers
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $filesize);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    
    // Handle range requests for video
    if (strpos($mimeType, 'video/') === 0 && isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        $ranges = explode('=', $range);
        $offsets = explode('-', $ranges[1]);
        $offset = intval($offsets[0]);
        $length = intval($offsets[1]) ?: $filesize - 1;
        
        if ($offset > 0 || $length < $filesize - 1) {
            header('HTTP/1.1 206 Partial Content');
            header('Accept-Ranges: bytes');
            header("Content-Range: bytes $offset-$length/$filesize");
            header('Content-Length: ' . ($length - $offset + 1));
            
            $file = fopen($filePath, 'rb');
            fseek($file, $offset);
            echo fread($file, $length - $offset + 1);
            fclose($file);
            exit;
        }
    }
    
    // Serve full file
    readfile($filePath);
    
} catch (Exception $e) {
    http_response_code(404);
    exit('File not found');
}
?>

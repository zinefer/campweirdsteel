<?php
/**
 * Gallery Manager
 * Handles file operations for the gallery
 */

require_once 'config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use FFMpeg\FFMpeg;
use FFMpeg\Coordinate\TimeCode;

class GalleryManager {
    
    /**
     * Get files for a specific year
     */
    public static function getFiles($year) {
        $files = [];
        $orderedFilenames = GalleryConfig::getOrderedFiles($year);
        $yearPath = GalleryConfig::getYearPath($year);
        
        foreach ($orderedFilenames as $filename) {
            $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
            if (is_file($filePath)) {
                $files[] = [
                    'filename' => $filename,
                    'path' => $filePath,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath),
                    'type' => GalleryConfig::isImage($filename) ? 'image' : 'video',
                    'url' => "serve.php?year={$year}&file=" . urlencode($filename),
                    'originalName' => GalleryConfig::extractOriginalName($filename),
                    'userId' => GalleryConfig::extractUserId($filename),
                    'ordering' => GalleryConfig::extractOrdering($filename),
                    'uploaderName' => htmlspecialchars(self::getUserDisplayName(GalleryConfig::extractUserId($filename)), ENT_QUOTES, 'UTF-8')
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Get image metadata including EXIF data
     */
    public static function getImageMetadata($year, $filename) {
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        if (!GalleryConfig::isImage($filename) || !file_exists($filePath)) {
            return null;
        }
        
        $metadata = [];
        
        try {
            // Get basic image info
            $imageInfo = getimagesize($filePath);
            if ($imageInfo) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['dimensions'] = $imageInfo[0] . 'x' . $imageInfo[1];
            }
            
            // Try to get EXIF data
            if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg'])) {
                $exif = @exif_read_data($filePath);
                if ($exif !== false) {
                    // Date taken
                    if (isset($exif['DateTime'])) {
                        $dateTaken = strtotime($exif['DateTime']);
                        if ($dateTaken) {
                            $metadata['date_taken'] = $dateTaken;
                        }
                    }
                    
                    // Camera info
                    if (isset($exif['Make']) && isset($exif['Model'])) {
                        $metadata['camera'] = trim($exif['Make'] . ' ' . $exif['Model']);
                    }
                    
                    // GPS coordinates
                    if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
                        $metadata['has_gps'] = true;
                    }
                }
            }
        } catch (Exception $e) {
            // Silently fail on EXIF errors
            error_log("EXIF read error for {$filename}: " . $e->getMessage());
        }
        
        return $metadata;
    }
    
    /**
     * Upload a file
     */
    public static function uploadFile($year, $uploadedFile, $userId) {
        if (!GalleryConfig::isAllowedFileType($uploadedFile['name'])) {
            throw new Exception('File type not allowed');
        }
        
        if ($uploadedFile['size'] > GalleryConfig::MAX_FILE_SIZE) {
            throw new Exception('File too large');
        }
        
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload error: ' . $uploadedFile['error']);
        }
        
        // Validate file content matches extension
        if (!self::validateFileContent($uploadedFile['tmp_name'], $uploadedFile['name'])) {
            throw new Exception('File content does not match file type');
        }
        
        $yearPath = GalleryConfig::getYearPath($year);
        $safeFilename = GalleryConfig::generateSafeFilename($uploadedFile['name'], $userId);
        $destinationPath = $yearPath . DIRECTORY_SEPARATOR . $safeFilename;
        
        if (!move_uploaded_file($uploadedFile['tmp_name'], $destinationPath)) {
            throw new Exception('Failed to save file');
        }
        
        // Set proper permissions
        chmod($destinationPath, 0644);
        
        return $safeFilename;
    }
    
    /**
     * Validate file content matches the expected file type
     */
    private static function validateFileContent($filePath, $originalName) {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        // Get MIME type from file content
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        // Define allowed MIME types for each extension
        $allowedMimeTypes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'gif' => ['image/gif'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
            'mov' => ['video/quicktime'],
            'avi' => ['video/x-msvideo', 'video/avi']
        ];
        
        if (!isset($allowedMimeTypes[$ext])) {
            return false;
        }
        
        return in_array($mimeType, $allowedMimeTypes[$ext]);
    }
    
    /**
     * Soft delete a file
     */
    public static function deleteFile($year, $filename, $userId) {
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        if (!file_exists($filePath)) {
            throw new Exception('File not found');
        }
        
        // Check if user owns the file using new filename format
        $fileUserId = GalleryConfig::extractUserId($filename);
        if ($fileUserId !== $userId) {
            throw new Exception('Permission denied');
        }
        
        $deletedFilename = GalleryConfig::DELETED_PREFIX . $filename;
        $deletedPath = $yearPath . DIRECTORY_SEPARATOR . $deletedFilename;
        
        if (!rename($filePath, $deletedPath)) {
            throw new Exception('Failed to delete file');
        }
        
        // Also delete the corresponding thumbnail/poster if it exists
        $thumbsDir = $yearPath . DIRECTORY_SEPARATOR . 'thumbs';
        
        if (GalleryConfig::isImage($filename)) {
            // For images, thumbnail has same filename
            $thumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $filename;
        } else {
            // For videos, poster has .jpg extension
            $posterFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
            $thumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $posterFilename;
        }
        
        if (file_exists($thumbPath)) {
            $deletedThumbFilename = GalleryConfig::DELETED_PREFIX . basename($thumbPath);
            $deletedThumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $deletedThumbFilename;
            if (!rename($thumbPath, $deletedThumbPath)) {
                error_log("Warning: Failed to delete thumbnail/poster: {$thumbPath}");
                // Don't fail the whole operation if thumbnail deletion fails
            }
        }
        
        return true;
    }
    
    /**
     * Reorder a file to a new position
     */
    public static function reorderFile($year, $filename, $newPosition, $userId) {
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filename}. It may have been moved or renamed by another operation.");
        }
        
        // Check if user owns the file
        $fileUserId = GalleryConfig::extractUserId($filename);
        if ($fileUserId !== $userId) {
            throw new Exception('Permission denied');
        }
        
        // Get current files in order
        $files = GalleryConfig::getOrderedFiles($year);
        $currentIndex = array_search($filename, $files);
        
        if ($currentIndex === false) {
            throw new Exception('File not found in list');
        }
        
        // Validate new position
        if ($newPosition < 0 || $newPosition >= count($files)) {
            throw new Exception('Invalid position');
        }
        
        // If moving to same position, no change needed
        if ($currentIndex === $newPosition) {
            return $filename;
        }
        
        // Calculate new ordering
        $newOrdering = null;
        if ($newPosition === 0) {
            // Moving to first position
            $firstOrdering = GalleryConfig::extractOrdering($files[0]);
            $newOrdering = GalleryConfig::generateOrderingBefore($firstOrdering);
        } elseif ($newPosition === count($files) - 1) {
            // Moving to last position
            $lastOrdering = GalleryConfig::extractOrdering($files[count($files) - 1]);
            $newOrdering = GalleryConfig::generateOrderingAfter($lastOrdering);
        } else {
            // Moving between two files
            if ($newPosition > $currentIndex) {
                // Moving down - insert after target position
                $beforeOrdering = GalleryConfig::extractOrdering($files[$newPosition]);
                $afterOrdering = ($newPosition + 1 < count($files)) ? 
                    GalleryConfig::extractOrdering($files[$newPosition + 1]) : null;
            } else {
                // Moving up - insert before target position
                $beforeOrdering = ($newPosition > 0) ? 
                    GalleryConfig::extractOrdering($files[$newPosition - 1]) : null;
                $afterOrdering = GalleryConfig::extractOrdering($files[$newPosition]);
            }
            
            if ($beforeOrdering && $afterOrdering) {
                $newOrdering = GalleryConfig::generateOrderingBetween($beforeOrdering, $afterOrdering);
            } elseif ($beforeOrdering) {
                $newOrdering = GalleryConfig::generateOrderingAfter($beforeOrdering);
            } else {
                $newOrdering = GalleryConfig::generateOrderingBefore($afterOrdering);
            }
        }
        
        // Create new filename with new ordering
        $userId = GalleryConfig::extractUserId($filename);
        $originalName = GalleryConfig::extractOriginalName($filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $newFilename = "{$newOrdering}_{$userId}_{$originalName}.{$extension}";
        $newFilePath = $yearPath . DIRECTORY_SEPARATOR . $newFilename;
        
        // Rename the file
        if (!rename($filePath, $newFilePath)) {
            error_log("Failed to rename file from {$filePath} to {$newFilePath}");
            throw new Exception('Failed to reorder file');
        }
        
        // Also rename the corresponding thumbnail/poster if it exists
        $thumbsDir = $yearPath . DIRECTORY_SEPARATOR . 'thumbs';
        
        if (GalleryConfig::isImage($filename)) {
            // For images, thumbnail has same filename
            $oldThumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $filename;
            $newThumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $newFilename;
        } else {
            // For videos, poster has .jpg extension
            $oldPosterFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
            $newPosterFilename = pathinfo($newFilename, PATHINFO_FILENAME) . '.jpg';
            $oldThumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $oldPosterFilename;
            $newThumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $newPosterFilename;
        }
        
        if (file_exists($oldThumbPath)) {
            if (!rename($oldThumbPath, $newThumbPath)) {
                error_log("Warning: Failed to rename thumbnail/poster from {$oldThumbPath} to {$newThumbPath}");
                // Don't fail the whole operation if thumbnail rename fails
            } else {
                error_log("Successfully renamed thumbnail/poster: {$filename} -> {$newFilename}");
            }
        }
        
        error_log("Successfully reordered file: {$filename} -> {$newFilename} at position {$newPosition}");
        
        return $newFilename;
    }
    
    /**
     * Serve a file (with authentication check)
     */
    public static function serveFile($year, $filename) {
        // Validate inputs first
        if (!is_numeric($year) || $year < 2000 || $year > 3000) {
            throw new Exception('Invalid year');
        }
        
        // Check if this is a thumbnail request
        $isThumb = strpos($filename, 'thumbs/') === 0;
        $actualFilename = $isThumb ? substr($filename, 7) : $filename; // Remove 'thumbs/' prefix
        
        // Strict filename validation - allow thumbs/ prefix but validate the actual filename
        if ($isThumb) {
            // For thumbnails, validate the actual filename after 'thumbs/'
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $actualFilename)) {
                throw new Exception('Invalid filename');
            }
            // Prevent path traversal in thumbnail path
            if (strpos($actualFilename, '..') !== false || strpos($actualFilename, '/') !== false || strpos($actualFilename, '\\') !== false) {
                throw new Exception('Invalid filename');
            }
        } else {
            // For regular files, use strict validation (no slashes allowed)
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
                throw new Exception('Invalid filename');
            }
            // Prevent path traversal attempts
            if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                throw new Exception('Invalid filename');
            }
        }
        
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        // Security checks
        if (strpos($actualFilename, GalleryConfig::DELETED_PREFIX) === 0) {
            throw new Exception('File not found');
        }
        
        if (!file_exists($filePath) || !is_file($filePath)) {
            throw new Exception('File not found');
        }
        
        // Ensure file is actually in the expected directory (canonical path check)
        $canonicalFilePath = realpath($filePath);
        $canonicalYearPath = realpath($yearPath);
        
        if ($canonicalFilePath === false || $canonicalYearPath === false) {
            throw new Exception('File not found');
        }
        
        // For thumbnails, check that the file is in the thumbs subdirectory
        if ($isThumb) {
            $thumbsPath = $canonicalYearPath . DIRECTORY_SEPARATOR . 'thumbs';
            if (strpos($canonicalFilePath, $thumbsPath . DIRECTORY_SEPARATOR) !== 0) {
                throw new Exception('Invalid file path');
            }
        } else {
            // For regular files, ensure they're directly in the year directory
            if (strpos($canonicalFilePath, $canonicalYearPath . DIRECTORY_SEPARATOR) !== 0) {
                throw new Exception('Invalid file path');
            }
        }
        
        // Verify it's an allowed file type (check the actual filename, not the path)
        if (!GalleryConfig::isAllowedFileType($actualFilename)) {
            throw new Exception('File type not allowed');
        }
        
        return $filePath;
    }
    
    /**
     * Generate thumbnail for images
     */
    public static function generateThumbnail($year, $filename) {
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        if (!GalleryConfig::isImage($filename) || !file_exists($filePath)) {
            return null;
        }
        
        $thumbsDir = $yearPath . DIRECTORY_SEPARATOR . 'thumbs';
        if (!is_dir($thumbsDir)) {
            mkdir($thumbsDir, 0755, true);
        }
        
        $thumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $filename;
        
        // Return existing thumbnail if it exists and is newer than original
        if (file_exists($thumbPath) && filemtime($thumbPath) >= filemtime($filePath)) {
            return "serve.php?year={$year}&file=thumbs/" . urlencode($filename);
        }
        
        // Create thumbnail
        try {
            $imageInfo = getimagesize($filePath);
            if (!$imageInfo) return null;
            
            list($width, $height, $type) = $imageInfo;
            
            // Create image resource
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($filePath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($filePath);
                    break;
                case IMAGETYPE_WEBP:
                    $source = imagecreatefromwebp($filePath);
                    break;
                default:
                    return null;
            }
            
            if (!$source) return null;
            
            // Calculate thumbnail dimensions
            $thumbSize = GalleryConfig::THUMBNAIL_SIZE;
            if ($width > $height) {
                $thumbWidth = $thumbSize;
                $thumbHeight = ($height / $width) * $thumbSize;
            } else {
                $thumbHeight = $thumbSize;
                $thumbWidth = ($width / $height) * $thumbSize;
            }
            
            // Create thumbnail
            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            
            // Preserve transparency for PNG
            if ($type == IMAGETYPE_PNG) {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
            }
            
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
            
            // Save thumbnail
            switch ($type) {
                case IMAGETYPE_JPEG:
                    imagejpeg($thumb, $thumbPath, 85);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($thumb, $thumbPath);
                    break;
                case IMAGETYPE_WEBP:
                    imagewebp($thumb, $thumbPath, 85);
                    break;
            }
            
            imagedestroy($source);
            imagedestroy($thumb);
            
            return "serve.php?year={$year}&file=thumbs/" . urlencode($filename);
        } catch (Exception $e) {
            error_log("Thumbnail generation error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get user display name by user ID
     */
    public static function getUserDisplayName($userId) {
        if (!$userId) {
            return 'Unknown User';
        }
        
        try {
            // Get database connection through auth
            $auth = new GalleryAuth();
            $app = $auth->getApp();
            $container = $app->getContainer();
            $db = $container->make('flarum.db');

            $userData = $db->table('users')
                ->select('username', 'nickname')
                ->where('id', $userId)
                ->first();
            
            if ($userData) {
                // Prefer nickname if available, fall back to username
                return $userData->nickname ?: $userData->username;
            }
            
            return "User {$userId}";
        } catch (Exception $e) {
            error_log("Error getting user display name: " . $e->getMessage());
            return "User {$userId}";
        }
    }
    
    /**
     * Generate poster image for videos (using php-ffmpeg library)
     * Returns null if FFmpeg is not available or generation fails
     */
    public static function generateVideoPoster($year, $filename) {
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        if (GalleryConfig::isImage($filename) || !file_exists($filePath)) {
            return null;
        }
        
        $thumbsDir = $yearPath . DIRECTORY_SEPARATOR . 'thumbs';
        if (!is_dir($thumbsDir)) {
            mkdir($thumbsDir, 0755, true);
        }
        
        // Create poster filename (change extension to .jpg)
        $posterFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        $posterPath = $thumbsDir . DIRECTORY_SEPARATOR . $posterFilename;
        
        // Return existing poster if it exists and is newer than original
        if (file_exists($posterPath) && filemtime($posterPath) >= filemtime($filePath)) {
            return "serve.php?year={$year}&file=thumbs/" . urlencode($posterFilename);
        }
        
        try {
            // Create FFMpeg instance with explicit binary paths
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries'  => '/usr/bin/ffmpeg',
                'ffprobe.binaries' => '/usr/bin/ffprobe',
                'timeout'          => 60, // Reduced timeout for thumbnail generation
                'ffmpeg.threads'   => 1,
            ]);
            
            // Open video file
            $video = $ffmpeg->open($filePath);
            
            // Extract frame at 1 second
            $frame = $video->frame(TimeCode::fromSeconds(1));
            
            // Save the frame as JPEG
            $frame->save($posterPath);
            
            // Verify the poster was created successfully
            if (file_exists($posterPath) && filesize($posterPath) > 0) {
                return "serve.php?year={$year}&file=thumbs/" . urlencode($posterFilename);
            } else {
                error_log("Generated poster file is empty or missing for {$filename}");
                return null;
            }
            
        } catch (Exception $e) {
            error_log("Video poster generation error for {$filename}: " . $e->getMessage());
            return null;
        }
    }
}
?>

<?php
/**
 * Gallery Configuration
 */

class GalleryConfig {
    const GALLERY_STORAGE_PATH = '../../gallery-storage';
    const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB
    const ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    const ALLOWED_VIDEO_TYPES = ['mp4', 'webm', 'mov', 'avi'];
    const DELETED_PREFIX = '_DELETED_';
    const THUMBNAIL_SIZE = 300;
    const MAX_FILENAME_LENGTH = 75;
    
    /**
     * Get storage path for a specific year
     */
    public static function getYearPath($year) {
        // Validate year input
        if (!is_numeric($year) || $year < 2000 || $year > 3000) {
            throw new Exception('Invalid year: ' . $year);
        }
        
        $basePath = realpath(dirname(__FILE__) . '/' . self::GALLERY_STORAGE_PATH);
        if ($basePath === false) {
            throw new Exception('Gallery storage path not found');
        }
        
        $yearPath = $basePath . DIRECTORY_SEPARATOR . $year;
        
        // Create directory if it doesn't exist
        if (!is_dir($yearPath)) {
            if (!mkdir($yearPath, 0755, true)) {
                throw new Exception('Failed to create year directory');
            }
        }
        
        return $yearPath;
    }
    
    /**
     * Get all available years
     */
    public static function getAvailableYears() {
        $basePath = realpath(dirname(__FILE__) . '/' . self::GALLERY_STORAGE_PATH);
        if (!$basePath || !is_dir($basePath)) {
            return [];
        }
        
        $years = [];
        $dirs = scandir($basePath);
        
        foreach ($dirs as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir($basePath . DIRECTORY_SEPARATOR . $dir)) {
                if (preg_match('/^\d{4}$/', $dir)) {
                    $years[] = (int)$dir;
                }
            }
        }
        
        rsort($years); // Most recent first
        return $years;
    }
    
    /**
     * Check if file type is allowed
     */
    public static function isAllowedFileType($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array_merge(self::ALLOWED_IMAGE_TYPES, self::ALLOWED_VIDEO_TYPES));
    }
    
    /**
     * Check if file is an image
     */
    public static function isImage($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOWED_IMAGE_TYPES);
    }
    
    /**
     * Check if file is a video
     */
    public static function isVideo($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOWED_VIDEO_TYPES);
    }
    
    /**
     * Generate safe filename with fractional ordering
     */
    public static function generateSafeFilename($originalName, $userId) {
        // Validate user ID
        if (!is_numeric($userId) || $userId <= 0) {
            throw new Exception('Invalid user ID');
        }
        
        // Sanitize original filename and truncate if needed
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        
        // More strict sanitization - only allow alphanumeric, dots, hyphens, underscores
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $baseName);
        $safeName = preg_replace('/_{2,}/', '_', $safeName); // Replace multiple underscores with single
        $safeName = trim($safeName, '_'); // Remove leading/trailing underscores
        
        if (strlen($safeName) > self::MAX_FILENAME_LENGTH) {
            $safeName = substr($safeName, 0, self::MAX_FILENAME_LENGTH);
        }
        
        if (empty($safeName)) {
            $safeName = 'upload_' . time();
        }
        
        // Validate extension
        $ext = strtolower($ext);
        if (!in_array($ext, array_merge(self::ALLOWED_IMAGE_TYPES, self::ALLOWED_VIDEO_TYPES))) {
            throw new Exception('Invalid file extension');
        }
        
        // Generate fractional ordering position for current year
        $currentYear = date('Y');
        $ordering = self::generateFractionalOrder($originalName, $currentYear);
        
        return "{$ordering}_{$userId}_{$safeName}.{$ext}";
    }
    
    /**
     * Generate fractional ordering string for insertion
     */
    public static function generateFractionalOrder($originalName, $year = null, $insertAfterFile = null) {
        if ($year === null) {
            $year = date('Y');
        }
        
        $files = self::getOrderedFiles($year);
        
        // If no files exist, start with "n"
        if (empty($files)) {
            return 'n';
        }
        
        // Find insertion point based on alphabetical order of original names
        $insertIndex = 0;
        foreach ($files as $i => $file) {
            $fileOriginalName = self::extractOriginalName($file);
            if (strcasecmp($originalName, $fileOriginalName) > 0) {
                $insertIndex = $i + 1;
            } else {
                break;
            }
        }
        
        // Generate fractional index
        if ($insertIndex === 0) {
            // Insert at beginning
            $firstOrdering = self::extractOrdering($files[0]);
            return self::generateOrderingBefore($firstOrdering);
        } elseif ($insertIndex >= count($files)) {
            // Insert at end
            $lastOrdering = self::extractOrdering($files[count($files) - 1]);
            return self::generateOrderingAfter($lastOrdering);
        } else {
            // Insert between two files
            $beforeOrdering = self::extractOrdering($files[$insertIndex - 1]);
            $afterOrdering = self::extractOrdering($files[$insertIndex]);
            return self::generateOrderingBetween($beforeOrdering, $afterOrdering);
        }
    }
    
    /**
     * Get files ordered by their fractional index
     */
    public static function getOrderedFiles($year) {
        $yearPath = self::getYearPath($year);
        if (!is_dir($yearPath)) {
            return [];
        }
        
        $files = [];
        $items = scandir($yearPath);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || is_dir($yearPath . DIRECTORY_SEPARATOR . $item)) continue;
            
            // Skip deleted files
            if (strpos($item, self::DELETED_PREFIX) === 0) continue;
            
            if (self::isAllowedFileType($item)) {
                $files[] = $item;
            }
        }
        
        // Sort by fractional ordering
        usort($files, function($a, $b) {
            $orderA = self::extractOrdering($a);
            $orderB = self::extractOrdering($b);
            return strcmp($orderA, $orderB);
        });
        
        return $files;
    }
    
    /**
     * Extract fractional ordering from filename
     */
    public static function extractOrdering($filename) {
        $parts = explode('_', $filename, 3);
        return isset($parts[0]) ? $parts[0] : 'z';
    }
    
    /**
     * Extract user ID from filename
     */
    public static function extractUserId($filename) {
        $parts = explode('_', $filename, 3);
        return isset($parts[1]) ? (int)$parts[1] : 0;
    }
    
    /**
     * Extract original filename from our systematic filename
     */
    public static function extractOriginalName($filename) {
        $parts = explode('_', $filename, 3);
        if (isset($parts[2])) {
            return pathinfo($parts[2], PATHINFO_FILENAME);
        }
        return pathinfo($filename, PATHINFO_FILENAME);
    }
    
    /**
     * Generate ordering string before given ordering
     */
    public static function generateOrderingBefore($ordering) {
        if ($ordering === 'a') {
            return 'Z';
        }
        if (strlen($ordering) === 1) {
            return chr(ord($ordering) - 1);
        }
        // For multi-character strings, append 'a' to the beginning
        return substr($ordering, 0, -1) . chr(ord(substr($ordering, -1)) - 1) . 'z';
    }
    
    /**
     * Generate ordering string after given ordering
     */
    public static function generateOrderingAfter($ordering) {
        if ($ordering === 'z') {
            return 'za';
        }
        if (strlen($ordering) === 1) {
            return chr(ord($ordering) + 1);
        }
        // For multi-character strings
        return substr($ordering, 0, -1) . chr(ord(substr($ordering, -1)) + 1);
    }
    
    /**
     * Generate ordering string between two orderings
     */
    public static function generateOrderingBetween($before, $after) {
        // Simple midpoint algorithm for fractional indexing
        $beforeChars = str_split($before);
        $afterChars = str_split($after);
        
        $result = '';
        $carry = 0;
        
        for ($i = 0; $i < max(strlen($before), strlen($after)); $i++) {
            $beforeChar = isset($beforeChars[$i]) ? ord($beforeChars[$i]) : ord('a') - 1;
            $afterChar = isset($afterChars[$i]) ? ord($afterChars[$i]) : ord('z') + 1;
            
            $mid = intval(($beforeChar + $afterChar) / 2);
            
            if ($mid > $beforeChar) {
                $result .= chr($mid);
                break;
            } else {
                $result .= chr($beforeChar);
            }
        }
        
        // If we couldn't find a midpoint, append a character
        if (strlen($result) === 0 || $result === $before) {
            $result = $before . 'm';
        }
        
        return $result;
    }
}
?>

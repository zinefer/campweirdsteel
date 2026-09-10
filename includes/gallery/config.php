<?php
/**
 * Gallery Configuration
 */

require_once __DIR__ . '/ordering.php';
require_once __DIR__ . '/ordering-migration.php';

class GalleryConfig {
    const GALLERY_STORAGE_PATH = '../../gallery-storage';
    // Kept as the image limit, and as the name anything older still calls.
    // Video goes through a bigger door because it arrives in chunks and gets
    // re-encoded down to something sane before it lands — see MAX_VIDEO_SIZE.
    const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB
    const MAX_VIDEO_SIZE = 500 * 1024 * 1024; // 500MB
    // Uploads are sliced client-side so no single request has to carry a whole
    // video. Nothing here has to agree with nginx's client_max_body_size or
    // PHP's post_max_size beyond staying comfortably under them.
    const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB, what the browser aims for
    const MAX_CHUNK_SIZE = 8 * 1024 * 1024; // what a request is allowed to be
    // Partially-uploaded files older than this are abandoned and collected.
    const UPLOAD_SESSION_TTL = 86400; // 24 hours
    // Ceiling on staging directories that exist at once. Each one can hold up
    // to MAX_VIDEO_SIZE before it is assembled, so without a cap a client
    // looping upload_init could fill the disk without ever finishing a file.
    const MAX_ACTIVE_UPLOADS = 24;
    const ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    const ALLOWED_VIDEO_TYPES = ['mp4', 'webm', 'mov', 'avi'];
    const DELETED_PREFIX = '_DELETED_';
    // Longest edge of a generated thumbnail. Was 300, which meant a 4:3 photo
    // had a 225px short edge being stretched across a grid tile of 280px or
    // more — doubled again on a 2x display. Existing thumbnails are only
    // rebuilt when they are older than their source, so run
    // regenerate_thumbnails.php once after changing this.
    const THUMBNAIL_SIZE = 800;
    const MAX_FILENAME_LENGTH = 75;

    // Every video is re-encoded to h264/aac mp4 after upload. That is partly
    // about size, but mostly about playback: .avi and iPhone HEVC .mov are
    // both in ALLOWED_VIDEO_TYPES and neither plays in Chrome as uploaded.
    const FFMPEG_BINARY = '/usr/bin/ffmpeg';
    const FFPROBE_BINARY = '/usr/bin/ffprobe';
    const PHP_CLI_BINARY = '/usr/bin/php';
    const VIDEO_MAX_WIDTH = 1920;   // Downscale anything wider; 4K helps nobody here
    const VIDEO_CRF = 23;           // x264 quality; lower is bigger and better
    const VIDEO_PRESET = 'medium';
    const VIDEO_AUDIO_BITRATE = '128k';
    // A phone video that takes longer than this to encode is left as-is
    // rather than tying up the box indefinitely.
    const TRANSCODE_TIMEOUT = 3600; // 1 hour
    // Threads per encode, and how many encodes may run at once.
    //
    // The container has 2 cores (see terraform: campweirdsteel_container), and
    // it is also serving nginx, PHP-FPM and MariaDB. The previous 2 slots x 2
    // threads put 4 encoder threads on those 2 cores and left the site
    // fighting its own transcoder for them.
    //
    // 1 x 2 rather than 2 x 1: the same total, but videos finish in the order
    // they were uploaded instead of all crawling in parallel, so the first
    // upload appears while the rest are still queued. Raise the slot count if
    // the box ever gets more cores — keep slots x threads <= cores.
    //
    // This is a cap on *concurrency*, not on queue depth. Workers past the cap
    // wait for a slot rather than being dropped, because there is nothing here
    // to pick them up later. That waiting is itself a problem — each waiter is
    // a full PHP process — which the queue rework addresses separately.
    const VIDEO_ENCODE_THREADS = 2;
    const MAX_CONCURRENT_TRANSCODES = 1;
    // How often the drain service looks for work, and how long its heartbeat
    // stays trustworthy. The poll interval is the worst-case delay before an
    // uploaded video starts converting — irrelevant next to an encode that
    // runs for minutes, and far cheaper than a process per queued video.
    const DRAINER_POLL_INTERVAL = 5;      // seconds
    // Generous next to the poll interval: systemd restarts a crashed drainer
    // within RestartSec, and a brief gap should not make every upload fall
    // back to spawning its own worker.
    const DRAINER_HEARTBEAT_STALE = 60;   // seconds
    // How long a worker gets to claim its marker after being spawned. Past
    // this with no claim, the spawn is assumed to have failed. See
    // GalleryManager::isProcessing().
    const TRANSCODE_START_GRACE = 120; // 2 minutes
    
    /**
     * Root of the gallery storage tree, or null if it isn't there.
     *
     * Year directories, the in-progress upload staging area and the transcode
     * markers all hang off this. Unlike getYearPath() it never creates
     * anything.
     */
    public static function getBasePath() {
        $basePath = realpath(dirname(__FILE__) . '/' . self::GALLERY_STORAGE_PATH);
        return $basePath === false ? null : $basePath;
    }

    /**
     * Staging area for chunks of an upload that is still arriving.
     *
     * A dot-prefixed sibling of the year folders. getAvailableYears() only
     * matches four digits and getOrderedFiles() skips directories, so nothing
     * that walks the archive can trip over it.
     */
    public static function getUploadsPath() {
        $basePath = self::getBasePath();
        if ($basePath === null) {
            throw new Exception('Gallery storage path not found');
        }

        $uploadsPath = $basePath . DIRECTORY_SEPARATOR . '.uploads';
        if (!is_dir($uploadsPath) && !mkdir($uploadsPath, 0755, true)) {
            throw new Exception('Failed to create upload staging directory');
        }

        return $uploadsPath;
    }

    /**
     * Home for lock files.
     *
     * Deliberately not under .uploads: that directory is swept by
     * ChunkedUpload::collectStale(), and collecting a lock file out from
     * under a worker holding it would quietly raise the transcode
     * concurrency cap.
     */
    public static function getLocksPath() {
        $basePath = self::getBasePath();
        if ($basePath === null) {
            throw new Exception('Gallery storage path not found');
        }

        $locksPath = $basePath . DIRECTORY_SEPARATOR . '.locks';
        if (!is_dir($locksPath) && !mkdir($locksPath, 0755, true)) {
            throw new Exception('Failed to create lock directory');
        }

        return $locksPath;
    }

    /**
     * Largest a given file is allowed to be, by kind.
     */
    public static function maxSizeFor($filename) {
        return self::isVideo($filename) ? self::MAX_VIDEO_SIZE : self::MAX_FILE_SIZE;
    }

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
     * Per-year counts and total size, from the directory listing alone.
     *
     * The sidebar used to get these by asking the files endpoint for every
     * year in the archive — which ran an EXIF read per image and an ffmpeg
     * call per video, on every page load and again after every upload and
     * delete. Nothing here opens a file.
     */
    public static function getYearSummaries() {
        $summaries = [];

        $years = self::getAvailableYears();

        // The current year is always offered, even before it has a directory.
        // Year folders are created by the first upload, so without this there
        // is no way to select the year uploads are actually open for — the
        // gallery would refuse uploads all the way to the first burn of the
        // season, with no visible reason.
        $currentYear = (int) date('Y');
        if (!in_array($currentYear, $years, true)) {
            array_unshift($years, $currentYear);
        }

        $basePath = realpath(dirname(__FILE__) . '/' . self::GALLERY_STORAGE_PATH);

        foreach ($years as $year) {
            $images = 0;
            $videos = 0;
            $bytes = 0;

            // Don't route through getYearPath() here — it creates the
            // directory as a side effect, and reading a summary should not
            // bring a year into existence.
            $yearPath = $basePath ? $basePath . DIRECTORY_SEPARATOR . $year : null;

            if ($yearPath && is_dir($yearPath)) {
                foreach (self::getOrderedFiles($year) as $filename) {
                    $bytes += (int) @filesize($yearPath . DIRECTORY_SEPARATOR . $filename);
                    if (self::isImage($filename)) {
                        $images++;
                    } else {
                        $videos++;
                    }
                }
            }

            $summaries[] = [
                'year'   => $year,
                'count'  => $images + $videos,
                'images' => $images,
                'videos' => $videos,
                'bytes'  => $bytes,
            ];
        }

        return $summaries;
    }

    /**
     * Whether a user may delete or reorder a file.
     *
     * Ownership is encoded in the filename. Admins can act on anything —
     * they can already upload on another member's behalf, so they need to be
     * able to clean up after it too.
     */
    public static function canManage($filename, $userId, $isAdmin = false) {
        if ($isAdmin) {
            return true;
        }
        return self::extractUserId($filename) === (int) $userId;
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
    public static function generateSafeFilename($originalName, $userId, $year = null) {
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
        
        // Position within the year the file is being filed under — which is
        // not always the current one. This read date('Y') regardless, so an
        // upload backfilling an earlier year got a key measured against this
        // year's files and landed in an arbitrary spot in the target.
        $ordering = self::generateFractionalOrder($originalName, $year === null ? date('Y') : $year);
        
        return "{$ordering}_{$userId}_{$safeName}.{$ext}";
    }
    
    /**
     * Ordering key for a newly uploaded file: the end of the year.
     *
     * Uploads used to be slotted in by comparing the original filename
     * against every file already filed. That sounds tidier than appending and
     * is not — phones name files by counter, so a batch picked newest-first
     * inserted every single file ahead of the last one, and the old key
     * scheme spent a character per insert before running out of alphabet.
     * Arrival order is also what people expect of an upload; anything else is
     * a drag away in the gallery, which is what reorderFile() is for.
     *
     * $originalName and $insertAfterFile are unused, kept so existing callers
     * keep working.
     */
    public static function generateFractionalOrder($originalName = null, $year = null, $insertAfterFile = null) {
        if ($year === null) {
            $year = date('Y');
        }

        // A year still on the old single-letter keys converts here, before
        // anything reads the last key off it.
        OrderingMigration::ensureMigrated($year);

        $files = self::getOrderedFiles($year);
        if (empty($files)) {
            return FractionalOrdering::firstKey();
        }

        $lastOrdering = self::extractOrdering($files[count($files) - 1]);

        return FractionalOrdering::generateKeyBetween($lastOrdering, null);
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
     * Ordering keys around, or between, existing ones.
     *
     * These are thin wrappers now: the key algebra lives in
     * FractionalOrdering, where a null bound means "nothing on that side".
     */
    public static function generateOrderingBefore($ordering) {
        return FractionalOrdering::generateKeyBetween(null, $ordering);
    }

    public static function generateOrderingAfter($ordering) {
        return FractionalOrdering::generateKeyBetween($ordering, null);
    }

    public static function generateOrderingBetween($before, $after) {
        return FractionalOrdering::generateKeyBetween($before, $after);
    }

    /**
     * Rename a file and its thumbnail or poster together.
     *
     * Both re-keying paths need this — a drag in the gallery and a migration
     * — and a thumbnail left behind under the old name is invisible until
     * someone notices a blank tile, so the convention lives in one place.
     *
     * A thumbnail that fails to move is logged rather than thrown: the
     * original is already in its new home by then, and a missing thumbnail is
     * regenerated on the next request. Returns whether it moved.
     */
    public static function renameWithThumbnail($year, $oldFilename, $newFilename) {
        $yearPath = self::getYearPath($year);

        $oldPath = $yearPath . DIRECTORY_SEPARATOR . $oldFilename;
        $newPath = $yearPath . DIRECTORY_SEPARATOR . $newFilename;

        if (!rename($oldPath, $newPath)) {
            throw new Exception("Failed to rename {$oldFilename} to {$newFilename}");
        }

        $thumbsDir = $yearPath . DIRECTORY_SEPARATOR . 'thumbs';

        if (self::isImage($oldFilename)) {
            // An image's thumbnail carries the same name as the image.
            $oldThumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $oldFilename;
            $newThumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $newFilename;
        } else {
            // A video's poster is a .jpg alongside it.
            $oldThumbPath = $thumbsDir . DIRECTORY_SEPARATOR
                . pathinfo($oldFilename, PATHINFO_FILENAME) . '.jpg';
            $newThumbPath = $thumbsDir . DIRECTORY_SEPARATOR
                . pathinfo($newFilename, PATHINFO_FILENAME) . '.jpg';
        }

        if (!file_exists($oldThumbPath)) {
            return false;
        }

        if (!rename($oldThumbPath, $newThumbPath)) {
            error_log("Warning: failed to rename thumbnail {$oldThumbPath} to {$newThumbPath}");
            return false;
        }

        return true;
    }
}
?>

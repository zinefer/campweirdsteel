<?php
/**
 * Gallery Manager
 * Handles file operations for the gallery
 */

// __DIR__, not a bare 'config.php'. PHP resolves a relative include against
// include_path (which begins with '.', the *current working directory*) before
// it falls back to the calling script's own directory — and a deployed app
// root contains Flarum's config.php. Running with the CWD set there, as the
// transcode service does, silently loaded that array instead of this class and
// died later at the first use of GalleryConfig, with nothing to say why.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use FFMpeg\FFMpeg;
use FFMpeg\Coordinate\TimeCode;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTiff;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelEntryShort;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelTag;
use Symfony\Component\Process\Process;

class GalleryManager {
    
    // Static cache for user display names to avoid repeated database queries
    private static $userDisplayNameCache = [];
    
    // Static database connection cache
    private static $dbConnection = null;
    
    /**
     * Get files for a specific year
     */
    public static function getFiles($year) {
        $files = [];
        $orderedFilenames = GalleryConfig::getOrderedFiles($year);
        $yearPath = GalleryConfig::getYearPath($year);
        
        // First pass: collect all user IDs
        $userIds = [];
        $fileData = [];
        
        foreach ($orderedFilenames as $filename) {
            $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
            if (is_file($filePath)) {
                $userId = GalleryConfig::extractUserId($filename);
                $userIds[] = $userId;
                
                $fileData[] = [
                    'filename' => $filename,
                    'path' => $filePath,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath),
                    'type' => GalleryConfig::isImage($filename) ? 'image' : 'video',
                    'url' => "serve.php?year={$year}&file=" . urlencode($filename),
                    'originalName' => GalleryConfig::extractOriginalName($filename),
                    'userId' => $userId,
                    'ordering' => GalleryConfig::extractOrdering($filename)
                ];
            }
        }
        
        // Batch load user display names for all unique user IDs
        $uniqueUserIds = array_unique(array_filter($userIds));
        self::batchLoadUserDisplayNames($uniqueUserIds);
        
        // Second pass: add cached user display names
        foreach ($fileData as $file) {
            // Raw text. This is JSON, not markup — the client escapes at the
            // point it builds HTML. Escaping here as well double-escaped any
            // name containing an ampersand or a quote.
            $file['uploaderName'] = self::getCachedUserDisplayName($file['userId']);
            $files[] = $file;
        }
        
        return $files;
    }
    
    /**
     * When a photo or video was taken, in Unix seconds, or null if it does
     * not say.
     *
     * This is deliberately separate from getImageMetadata(): that one runs on
     * every listing request and is best-effort decoration, whereas this runs
     * once at upload and decides the date the gallery will show forever after.
     */
    public static function extractCaptureTime($filePath, $filename = null) {
        if ($filename === null) {
            $filename = basename($filePath);
        }

        if (!is_file($filePath)) {
            return null;
        }

        if (GalleryConfig::isImage($filename)) {
            return self::extractImageCaptureTime($filePath, $filename);
        }

        if (GalleryConfig::isVideo($filename)) {
            return self::extractVideoCaptureTime($filePath);
        }

        return null;
    }

    /**
     * Stamp a file's mtime with its capture time.
     *
     * The gallery dates every tile by filemtime(), which for an upload is the
     * moment it landed on disk. Moving the mtime back to when the shutter
     * actually fired means listing, sorting and display all get the right date
     * without reading EXIF on every request.
     *
     * Returns the timestamp applied, or null if the file had nothing usable
     * (in which case the upload time stands, as before).
     */
    public static function applyCaptureTime($filePath, $filename = null) {
        $captured = self::extractCaptureTime($filePath, $filename);
        if ($captured === null) {
            return null;
        }

        if (!@touch($filePath, $captured)) {
            error_log("Failed to stamp capture time on {$filePath}");
            return null;
        }

        return $captured;
    }

    /**
     * Capture time from a still image's EXIF block.
     */
    private static function extractImageCaptureTime($filePath, $filename) {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        // PNG and GIF carry no EXIF at all. WebP can, and PHP has read it
        // since 7.2, so it is worth the attempt.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'webp'])) {
            return null;
        }

        try {
            $exif = @exif_read_data($filePath);
        } catch (Exception $e) {
            error_log("EXIF read error for {$filename}: " . $e->getMessage());
            return null;
        }

        if (!is_array($exif)) {
            return null;
        }

        // DateTimeOriginal is the shutter. DateTime is the file's own
        // modification stamp, which any editor along the way will have
        // rewritten, so it is only a last resort.
        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $tag) {
            if (!isset($exif[$tag])) {
                continue;
            }

            $timestamp = self::parseExifDate($exif[$tag], self::exifOffsetFor($exif, $tag));
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return null;
    }

    /**
     * The UTC offset EXIF recorded alongside a date tag, as "+02:00", or null.
     *
     * EXIF dates are bare local time with no zone, so without this the server's
     * timezone is the only thing to go on. Phones have written the matching
     * OffsetTime* tags since EXIF 2.31; PHP surfaces them under their raw tag
     * ids because it has no names for them.
     */
    private static function exifOffsetFor($exif, $tag) {
        $offsetTags = [
            'DateTime'          => 'UndefinedTag:0x9010',
            'DateTimeOriginal'  => 'UndefinedTag:0x9011',
            'DateTimeDigitized' => 'UndefinedTag:0x9012',
        ];

        if (!isset($offsetTags[$tag]) || !isset($exif[$offsetTags[$tag]])) {
            return null;
        }

        $offset = trim($exif[$offsetTags[$tag]]);

        return preg_match('/^[+-]\d{2}:\d{2}$/', $offset) ? $offset : null;
    }

    /**
     * Parse EXIF's "YYYY:MM:DD HH:MM:SS" into a Unix timestamp.
     *
     * strtotime() cannot be trusted with this format — the colons in the date
     * half make it ambiguous — so the format is given explicitly.
     */
    private static function parseExifDate($value, $utcOffset = null) {
        $value = trim((string) $value);

        // An unset date field is written as all zeroes rather than omitted.
        if ($value === '' || strpos($value, '0000:00:00') === 0) {
            return null;
        }

        $date = $utcOffset === null
            ? DateTime::createFromFormat('Y:m:d H:i:s', $value)
            : DateTime::createFromFormat('Y:m:d H:i:sP', $value . $utcOffset);

        if ($date === false) {
            return null;
        }

        return self::sanityCheckTimestamp($date->getTimestamp());
    }

    /**
     * Capture time from a video container's metadata.
     */
    private static function extractVideoCaptureTime($filePath) {
        if (!is_executable(GalleryConfig::FFPROBE_BINARY)) {
            return null;
        }

        // Symfony's Process is safe to use here, unlike in queueTranscode():
        // this call is synchronous, so the destructor that would kill a
        // backgrounded worker fires only after the probe has already finished.
        $process = new Process([
            GalleryConfig::FFPROBE_BINARY,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            $filePath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $data = json_decode($process->getOutput(), true);
        if (!isset($data['format']['tags'])) {
            return null;
        }

        // Tag names vary by muxer and case is not guaranteed.
        $tags = array_change_key_case($data['format']['tags'], CASE_LOWER);
        foreach (['creation_time', 'com.apple.quicktime.creationdate', 'date'] as $tag) {
            if (!isset($tags[$tag])) {
                continue;
            }

            // These are ISO 8601 and carry their own zone, so strtotime is
            // the right tool for once.
            $timestamp = strtotime(trim($tags[$tag]));
            if ($timestamp !== false) {
                $timestamp = self::sanityCheckTimestamp($timestamp);
                if ($timestamp !== null) {
                    return $timestamp;
                }
            }
        }

        return null;
    }

    /**
     * Reject dates a camera could not plausibly have produced.
     *
     * QuickTime counts from 1904 and a camera with a dead clock reports 1970,
     * both of which would file the photo at the very end of an
     * oldest-first sort rather than being ignored.
     */
    private static function sanityCheckTimestamp($timestamp) {
        if ($timestamp < 631152000) { // 1990-01-01
            return null;
        }

        if ($timestamp > time() + 86400) {
            return null;
        }

        return $timestamp;
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
                    // getimagesize() reports the pixels as stored, but phones
                    // store portrait shots sideways and set Orientation 5-8 to
                    // say "rotate 90°". The browser honours that when it draws
                    // the thumbnail (the flag is copied onto it), so the grid
                    // has to size the tile for the rotated shape too, or a
                    // portrait photo gets a landscape tile.
                    if (isset($metadata['width'], $exif['Orientation'])
                        && in_array((int) $exif['Orientation'], [5, 6, 7, 8], true)) {
                        [$metadata['width'], $metadata['height']] = [$metadata['height'], $metadata['width']];
                        $metadata['dimensions'] = $metadata['width'] . 'x' . $metadata['height'];
                    }

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
     * Width and height of a video, read from its generated poster frame.
     *
     * The grid uses this to give each tile the shape of the thing inside it.
     * Reading the poster's header avoids probing the video itself.
     */
    public static function getPosterDimensions($year, $filename) {
        if (GalleryConfig::isImage($filename)) {
            return null;
        }

        $posterFilename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        $posterPath = GalleryConfig::getYearPath($year)
            . DIRECTORY_SEPARATOR . 'thumbs'
            . DIRECTORY_SEPARATOR . $posterFilename;

        if (!file_exists($posterPath)) {
            return null;
        }

        $info = @getimagesize($posterPath);
        if (!$info) {
            return null;
        }

        return ['width' => $info[0], 'height' => $info[1]];
    }

    /**
     * Return a filename that is free inside $yearPath.
     *
     * Suffixes the name part (never the ordering or user id, which the
     * extract* helpers parse positionally by splitting on the first two
     * underscores) until nothing is in the way.
     *
     * O_EXCL would close the last sliver of race between the check and the
     * rename, but uploads from one session are sequential and the suffix
     * search re-runs per attempt; a collision across two simultaneous
     * uploaders lands on the next free suffix rather than on top of a file.
     */
    private static function claimFilename($yearPath, $filename) {
        if (!file_exists($yearPath . DIRECTORY_SEPARATOR . $filename)) {
            return $filename;
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);

        for ($n = 2; $n < 10000; $n++) {
            $candidate = "{$base}-{$n}.{$ext}";
            if (!file_exists($yearPath . DIRECTORY_SEPARATOR . $candidate)) {
                return $candidate;
            }
        }

        throw new Exception('Could not find a free filename for this upload');
    }

    /**
     * File a completed upload into its year.
     *
     * The source is a plain path — chunked uploads are stitched together in
     * the staging area first, so by the time we get here there is no such
     * thing as an "uploaded file" in PHP's sense any more.
     *
     * Images get their thumbnail on the spot. Videos get queued for
     * re-encoding, which happens out of band because it takes far longer than
     * a request is allowed to live.
     */
    public static function storeUpload($year, $sourcePath, $originalName, $userId) {
        if (!GalleryConfig::isAllowedFileType($originalName)) {
            throw new Exception('File type not allowed');
        }

        $size = filesize($sourcePath);
        if ($size > GalleryConfig::maxSizeFor($originalName)) {
            throw new Exception('File too large');
        }

        // Validate file content matches extension
        if (!self::validateFileContent($sourcePath, $originalName)) {
            throw new Exception('File content does not match file type');
        }

        $yearPath = GalleryConfig::getYearPath($year);
        $safeFilename = GalleryConfig::generateSafeFilename($originalName, $userId, $year);

        // Never land on a name that is already taken. rename() overwrites
        // without complaint, so a duplicate destination destroyed the earlier
        // upload and still reported success to the browser — no exception, no
        // log line, just a file count that did not match what was sent.
        //
        // Two uploads collide whenever their ordering, uploader and sanitised
        // name all match, which is routine from a phone: iOS transcodes HEIC
        // on upload and names every one of them `image.jpg`.
        $safeFilename = self::claimFilename($yearPath, $safeFilename);
        $destinationPath = $yearPath . DIRECTORY_SEPARATOR . $safeFilename;

        if (!rename($sourcePath, $destinationPath)) {
            throw new Exception('Failed to save file');
        }

        // Set proper permissions
        chmod($destinationPath, 0644);

        // Date the file by when it was taken rather than when it arrived. Has
        // to happen before the thumbnail is made, or the thumbnail would look
        // older than its original and be rebuilt on every request.
        self::applyCaptureTime($destinationPath, $safeFilename);

        // Generate thumbnail immediately for images
        if (GalleryConfig::isImage($safeFilename)) {
            try {
                self::generateThumbnail($year, $safeFilename);
            } catch (Exception $e) {
                // Log thumbnail generation error but don't fail the upload
                error_log("Failed to generate thumbnail for {$safeFilename}: " . $e->getMessage());
            }

            return ['filename' => $safeFilename, 'processing' => false];
        }

        $queued = self::queueTranscode($year, $safeFilename);

        // If the worker could not be started the video is still perfectly
        // filed, just in whatever container the camera produced. Generate its
        // poster inline so the tile isn't blank.
        if (!$queued) {
            try {
                self::generateVideoPoster($year, $safeFilename);
            } catch (Exception $e) {
                error_log("Failed to generate poster for {$safeFilename}: " . $e->getMessage());
            }
        }

        return ['filename' => $safeFilename, 'processing' => $queued];
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
            'mp4' => ['video/mp4', 'video/x-m4v'],
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
     * Directory holding one marker file per video currently being re-encoded.
     *
     * Dot-prefixed so getOrderedFiles() — which skips directories anyway —
     * and anything else walking a year folder stays clear of it.
     */
    private static function processingDir($year) {
        return GalleryConfig::getYearPath($year) . DIRECTORY_SEPARATOR . '.processing';
    }

    /**
     * Is this file waiting on, or in the middle of, a transcode?
     *
     * The gallery shows these as placeholders: the file on disk is still the
     * camera's original, which is exactly the thing that might not play.
     */
    public static function isProcessing($year, $filename) {
        $marker = self::processingDir($year) . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($marker)) {
            return false;
        }

        $age = time() - filemtime($marker);
        $state = trim((string) @file_get_contents($marker));

        // The marker is written before the worker is spawned and claimed by
        // the worker once it is running. A marker still unclaimed after the
        // grace period means the spawn itself failed — a missing CLI binary,
        // a fork that never happened — and nothing is ever going to finish
        // it. Without this the tile polls "Converting…" for over an hour.
        if (strpos($state, 'started') !== 0 && $age > GalleryConfig::TRANSCODE_START_GRACE) {
            @unlink($marker);
            error_log("Transcode worker never claimed {$filename}; clearing marker");
            return false;
        }

        // A worker that was killed — OOM, a deploy, a reboot — never gets to
        // clear its own marker, and the tile would sit at "processing"
        // forever. A live worker touches its marker while it waits for a
        // slot, so mtime is a heartbeat rather than a start time.
        if ($age > GalleryConfig::TRANSCODE_TIMEOUT + 300) {
            @unlink($marker);
            error_log("Cleared stale transcode marker for {$filename}");
            return false;
        }

        return true;
    }

    /**
     * Note that a transcode is outstanding. Returns false if the marker could
     * not be written, in which case no worker should be started.
     */
    public static function markProcessing($year, $filename) {
        $dir = self::processingDir($year);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        return file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, 'queued:' . time()) !== false;
    }

    /**
     * Claim a marker from inside the worker.
     *
     * Until this happens the marker only records that something was spawned;
     * isProcessing() gives up on an unclaimed marker after
     * TRANSCODE_START_GRACE so a failed spawn does not strand the tile.
     */
    public static function markTranscodeStarted($year, $filename) {
        $marker = self::processingDir($year) . DIRECTORY_SEPARATOR . $filename;
        return @file_put_contents($marker, 'started:' . time()) !== false;
    }

    /**
     * Heartbeat. A worker queued behind the concurrency cap can be waiting
     * for a long time, and without this the staleness sweep would collect a
     * marker whose worker is alive and simply waiting its turn.
     */
    public static function touchProcessing($year, $filename) {
        @touch(self::processingDir($year) . DIRECTORY_SEPARATOR . $filename);
    }

    /**
     * Every outstanding transcode, across every year, oldest first.
     *
     * The .processing markers *are* the queue — there is no second list to
     * keep in step with them, and they already survive a reboot because they
     * are files. A marker whose source has since been deleted is collected
     * here rather than handed to a worker that would only reject it.
     *
     * @return array of ['year' => int, 'filename' => string, 'queuedAt' => int]
     */
    public static function pendingTranscodes() {
        $basePath = GalleryConfig::getBasePath();
        if ($basePath === null) {
            return [];
        }

        $jobs = [];

        foreach (scandir($basePath) ?: [] as $entry) {
            // Year directories only: .uploads, .locks and friends are not one.
            if (!preg_match('/^[0-9]{4}$/', $entry)) {
                continue;
            }

            $year = (int) $entry;
            $dir = $basePath . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . '.processing';
            if (!is_dir($dir)) {
                continue;
            }

            foreach (scandir($dir) ?: [] as $filename) {
                if ($filename === '.' || $filename === '..') {
                    continue;
                }

                // Same alphabet generateSafeFilename() produces. A marker
                // named anything else did not come from us.
                if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
                    continue;
                }

                $marker = $dir . DIRECTORY_SEPARATOR . $filename;
                $source = $basePath . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . $filename;

                if (!file_exists($source)) {
                    @unlink($marker);
                    continue;
                }

                $jobs[] = [
                    'year'     => $year,
                    'filename' => $filename,
                    'queuedAt' => (int) @filemtime($marker),
                ];
            }
        }

        // FIFO: whoever has been waiting longest goes next.
        usort($jobs, function ($a, $b) {
            return $a['queuedAt'] <=> $b['queuedAt'];
        });

        return $jobs;
    }

    /**
     * Take exclusive ownership of one transcode job.
     *
     * Returns the open handle on success and null if someone else already has
     * it. The lock lives on its own file rather than on the marker, because
     * the marker is rewritten by markTranscodeStarted() and deleted by
     * clearProcessing() — locking something with that lifecycle invites two
     * workers onto one file across a delete/recreate.
     *
     * Like the encode slots, this is an flock(), so a worker that dies in any
     * fashion releases the job rather than stranding it.
     */
    public static function claimTranscode($year, $filename) {
        try {
            $dir = GalleryConfig::getLocksPath();
        } catch (Exception $e) {
            return null;
        }

        $handle = @fopen($dir . DIRECTORY_SEPARATOR . 'job_' . (int) $year . '_' . $filename, 'c');
        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * Hand a claim (or an encode slot) back.
     */
    public static function releaseLock($handle) {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * The drain service says it is alive.
     */
    public static function drainerHeartbeat() {
        try {
            $dir = GalleryConfig::getLocksPath();
        } catch (Exception $e) {
            return;
        }

        @touch($dir . DIRECTORY_SEPARATOR . 'drainer.alive');
    }

    /**
     * Has the drain service checked in recently?
     *
     * queueTranscode() uses this to decide whether it can simply leave a
     * marker or has to spawn a one-shot worker itself. Without it, deploying
     * this code somewhere the systemd unit was never installed would mean no
     * video ever converts again, silently.
     */
    public static function drainerIsRunning() {
        try {
            $dir = GalleryConfig::getLocksPath();
        } catch (Exception $e) {
            return false;
        }

        $beat = $dir . DIRECTORY_SEPARATOR . 'drainer.alive';
        if (!file_exists($beat)) {
            return false;
        }

        return (time() - (int) @filemtime($beat)) < GalleryConfig::DRAINER_HEARTBEAT_STALE;
    }

    /**
     * Block until one of MAX_CONCURRENT_TRANSCODES encode slots is free.
     *
     * Slots are flock()ed files, so a worker that dies for any reason —
     * including SIGKILL — releases its slot when the kernel closes the fd.
     * A counter in a file would leak on every crash.
     *
     * Returns the open handle; the lock is held until it is closed or the
     * process exits. Callers must keep the handle alive for the encode.
     */
    /**
     * Set by the drain service so the slot wait below keeps its heartbeat
     * alive. Waiting for a slot can take as long as a whole encode, and a
     * drainer that goes quiet for DRAINER_HEARTBEAT_STALE looks dead to
     * queueTranscode() — which would start spawning per-video workers again,
     * the exact pileup the service exists to prevent.
     */
    public static $isDrainService = false;

    public static function acquireTranscodeSlot($year, $filename) {
        try {
            $dir = GalleryConfig::getLocksPath();
        } catch (Exception $e) {
            return null; // No lock dir; proceed uncapped rather than not at all.
        }

        while (true) {
            for ($i = 0; $i < GalleryConfig::MAX_CONCURRENT_TRANSCODES; $i++) {
                $handle = @fopen($dir . DIRECTORY_SEPARATOR . 'slot_' . $i, 'c');
                if ($handle === false) {
                    continue;
                }

                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return $handle;
                }

                fclose($handle);
            }

            // Keep the marker warm so the gallery keeps reporting this file
            // as in progress while it queues.
            self::touchProcessing($year, $filename);

            if (self::$isDrainService) {
                self::drainerHeartbeat();
            }

            sleep(5);
        }
    }

    /**
     * Clear the marker, whether the transcode succeeded or gave up.
     */
    public static function clearProcessing($year, $filename) {
        @unlink(self::processingDir($year) . DIRECTORY_SEPARATOR . $filename);
    }

    /**
     * Start re-encoding a video in the background.
     *
     * Re-encoding a phone video takes minutes; a request gets 30 seconds
     * (see regenerateThumbnails() working around the same ceiling). So the
     * work is handed to a detached CLI process and the request returns
     * immediately with the file marked as processing.
     *
     * Spawned through proc_open rather than exec(). The deployment hardens
     * PHP-FPM with `disable_functions = exec,passthru,shell_exec,system,
     * popen,...`, which made the exec() version a permanent no-op:
     * function_exists('exec') returns false for a disabled function, the
     * guard fired, and every video was filed in whatever container the camera
     * produced and never converted — silently, with the upload still
     * reporting success. proc_open is not on that list, and is the same call
     * generateVideoPoster() has always reached ffmpeg through.
     *
     * Note this deliberately does NOT use Symfony's Process, despite it being
     * available: Process::__destruct() calls stop(), so the worker would be
     * SIGKILLed the moment this request ended. The command backgrounds itself
     * inside a shell instead, so the shell exits immediately and the worker is
     * reparented to init, outliving the PHP-FPM process that spawned it.
     *
     * Returns whether a worker was handed off. A worker that starts and then
     * dies is caught separately, by isProcessing() giving up on a marker that
     * was never claimed.
     */
    public static function queueTranscode($year, $filename) {
        if (!GalleryConfig::isVideo($filename)) {
            return false;
        }

        if (!self::markProcessing($year, $filename)) {
            error_log("Cannot queue transcode for {$filename}: failed to write marker");
            return false;
        }

        // With the drain service up, the marker written above *is* the whole
        // job — it will pick it up within DRAINER_POLL_INTERVAL.
        //
        // This used to spawn a detached PHP process per video regardless, and
        // everything past the concurrency cap then sat in a sleep loop waiting
        // for a slot. Each of those waiters had loaded the Composer autoloader,
        // php-ffmpeg and PEL, so a fifty-video upload was fifty PHP processes
        // and well over a gigabyte of resident memory to run one encode. The
        // encodes were capped; the waiting was not.
        if (self::drainerIsRunning()) {
            return true;
        }

        // No drain service. Fall back to spawning a one-shot worker, which is
        // how this worked before the queue existed — deploying this code
        // somewhere the systemd unit was never installed must not mean videos
        // silently stop converting.
        error_log("No transcode drain service; spawning a one-shot worker for {$filename}");

        // Only the fallback needs these. The drain service reaches the encode
        // without a CLI spawn at all, so a hardened FPM pool with proc_open
        // disabled is no longer a reason to refuse the job.
        $php = GalleryConfig::PHP_CLI_BINARY;
        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'transcode.php';

        if (!is_executable($php) || !file_exists($worker)) {
            error_log("Cannot queue transcode for {$filename}: no PHP CLI at {$php}");
            self::clearProcessing($year, $filename);
            return false;
        }

        if (!function_exists('proc_open')) {
            error_log("Cannot queue transcode for {$filename}: proc_open is unavailable");
            self::clearProcessing($year, $filename);
            return false;
        }

        // Every element is escaped, and $filename has already been through
        // generateSafeFilename()'s [a-zA-Z0-9._-] alphabet.
        $command = sprintf(
            'nohup %s %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($worker),
            escapeshellarg((string) (int) $year),
            escapeshellarg($filename)
        );

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $handle = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($handle)) {
            error_log("Failed to spawn transcode worker for {$filename}: proc_open refused");
            self::clearProcessing($year, $filename);
            return false;
        }

        // Returns as soon as the shell exits, which is immediately — the
        // worker it backgrounded is already detached.
        proc_close($handle);

        return true;
    }

    /**
     * Set the date a file is shown and sorted under.
     *
     * The metadata is not always right — a camera with a flat battery loses
     * its clock, a scan carries the date it was scanned, and a video shot
     * across midnight can land on the wrong day. This is the manual override,
     * limited to files the person uploaded (or, for an admin, any of them,
     * since admins upload on other members' behalf).
     *
     * The date lives in the file's mtime like every other date here, so
     * nothing downstream needs to know an edit happened.
     */
    public static function setCaptureDate($year, $filename, $timestamp, $userId, $isAdmin = false) {
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($filePath)) {
            throw new Exception('File not found');
        }

        if (!GalleryConfig::canManage($filename, $userId, $isAdmin)) {
            throw new Exception('You can only change the date on files you uploaded');
        }

        // Same race as delete and reorder: the transcode worker re-stamps the
        // file from what it read when it started, so an edit made mid-encode
        // would be silently thrown away a few minutes later.
        if (self::isProcessing($year, $filename)) {
            throw new Exception('That video is still being converted. Try again once it is ready.');
        }

        $timestamp = self::sanityCheckTimestamp((int) $timestamp);
        if ($timestamp === null) {
            throw new Exception('That date is not a date this could have been taken');
        }

        if (!@touch($filePath, $timestamp)) {
            throw new Exception('Failed to change the date');
        }

        // Thumbnails are cached by being newer than their original, so moving
        // a date forward would otherwise throw away a perfectly good thumbnail
        // and rebuild it — for a video, that means another ffmpeg run.
        self::keepDerivedNewerThan($year, $filename, $timestamp);

        return $timestamp;
    }

    /**
     * Push a file's thumbnail or poster past the given time, if it has one.
     */
    private static function keepDerivedNewerThan($year, $filename, $timestamp) {
        $thumbsDir = GalleryConfig::getYearPath($year) . DIRECTORY_SEPARATOR . 'thumbs';

        $derivedName = GalleryConfig::isImage($filename)
            ? $filename
            : pathinfo($filename, PATHINFO_FILENAME) . '.jpg';

        $derivedPath = $thumbsDir . DIRECTORY_SEPARATOR . $derivedName;

        if (file_exists($derivedPath) && filemtime($derivedPath) < $timestamp) {
            @touch($derivedPath, $timestamp + 1);
        }
    }

    /**
     * Soft delete a file
     */
    public static function deleteFile($year, $filename, $userId, $isAdmin = false) {
        $yearPath = GalleryConfig::getYearPath($year);
        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        if (!file_exists($filePath)) {
            throw new Exception('File not found');
        }
        
        // Owner or admin. Admins can upload on another member's behalf, so
        // they need to be able to remove those uploads too.
        if (!GalleryConfig::canManage($filename, $userId, $isAdmin)) {
            throw new Exception('You can only delete files you uploaded');
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
    public static function reorderFile($year, $filename, $newPosition, $userId, $isAdmin = false) {
        $yearPath = GalleryConfig::getYearPath($year);

        // A year still on the old single-letter keys is converted before
        // anything here computes a key from its neighbours. Converting
        // renames files, so it happens before the name the client sent is
        // resolved — and if that name was one of the renamed ones, the page
        // is simply out of date.
        $migrated = OrderingMigration::ensureMigrated($year);

        $filePath = $yearPath . DIRECTORY_SEPARATOR . $filename;
        
        if (!file_exists($filePath)) {
            if ($migrated > 0) {
                throw new Exception('The gallery was reorganised just now. Refresh the page and try again.');
            }
            throw new Exception("File not found: {$filename}. It may have been moved or renamed by another operation.");
        }
        
        // Reordering renames the file on disk and changes the order every
        // member sees. This check did not exist: the $userId argument was
        // accepted and then overwritten below by the owner ID parsed out of
        // the filename, so any signed-in member could rearrange the archive.
        if (!GalleryConfig::canManage($filename, $userId, $isAdmin)) {
            throw new Exception('You can only rearrange files you uploaded');
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
        
        // Rebuild the filename with the new ordering. The owner ID is carried
        // over from the existing name — reordering never changes who owns a
        // file, including when an admin does it.
        $ownerId = GalleryConfig::extractUserId($filename);
        $originalName = GalleryConfig::extractOriginalName($filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $newFilename = "{$newOrdering}_{$ownerId}_{$originalName}.{$extension}";
        // Moves the thumbnail or poster with it — a thumbnail left under the
        // old name shows up as a blank tile and nothing else.
        GalleryConfig::renameWithThumbnail($year, $filename, $newFilename);

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
            
            // Get EXIF orientation for JPEG images
            $orientation = 1; // Default orientation (no rotation)
            if ($type == IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($filePath);
                if ($exif !== false && isset($exif['Orientation'])) {
                    $orientation = $exif['Orientation'];
                }
            }
            
            // Calculate thumbnail dimensions
            $thumbSize = GalleryConfig::THUMBNAIL_SIZE;
            if ($width > $height) {
                $thumbWidth = $thumbSize;
                $thumbHeight = ($height / $width) * $thumbSize;
            } else {
                $thumbHeight = $thumbSize;
                $thumbWidth = ($width / $height) * $thumbSize;
            }
            
            // Create thumbnail from original image (without rotation)
            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            
            // Preserve transparency for PNG
            if ($type == IMAGETYPE_PNG) {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
            }
            
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
            imagedestroy($source);
            
            // Save thumbnail to temporary location first to avoid race conditions
            $tempThumbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thumb_' . uniqid() . '_' . basename($thumbPath);
            
            // Save thumbnail
            switch ($type) {
                case IMAGETYPE_JPEG:
                    imagejpeg($thumb, $tempThumbPath, 85);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($thumb, $tempThumbPath);
                    break;
                case IMAGETYPE_WEBP:
                    imagewebp($thumb, $tempThumbPath, 85);
                    break;
            }
            
            imagedestroy($thumb);
            
            // Copy EXIF orientation to thumbnail for JPEG files BEFORE moving to final location
            if ($type == IMAGETYPE_JPEG && $orientation != 1) {
                self::copyExifOrientationToThumbnail($filePath, $tempThumbPath);
            }
            
            // Atomically move from temp to final location
            if (!rename($tempThumbPath, $thumbPath)) {
                // Clean up temp file if move failed
                if (file_exists($tempThumbPath)) {
                    unlink($tempThumbPath);
                }
                throw new Exception('Failed to save thumbnail');
            }
            
            return "serve.php?year={$year}&file=thumbs/" . urlencode($filename);
        } catch (Exception $e) {
            error_log("Thumbnail generation error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Copy EXIF orientation data from original image to thumbnail using PEL library
     * This allows the browser to automatically rotate the thumbnail correctly
     * 
     * @param string $originalPath Path to the original image
     * @param string $thumbPath Path to the thumbnail image
     */
    private static function copyExifOrientationToThumbnail($originalPath, $thumbPath) {
        try {
            // Read EXIF data from original image using PEL
            $originalJpeg = new PelJpeg($originalPath);
            $originalExif = $originalJpeg->getExif();
            
            if ($originalExif === null) {
                return; // No EXIF data in original
            }
            
            $originalTiff = $originalExif->getTiff();
            if ($originalTiff === null) {
                return; // No TIFF data
            }
            
            $originalIfd0 = $originalTiff->getIfd();
            if ($originalIfd0 === null) {
                return; // No IFD data
            }
            
            // Get orientation from original
            $orientationEntry = $originalIfd0->getEntry(PelTag::ORIENTATION);
            if ($orientationEntry === null) {
                return; // No orientation data
            }
            
            $orientation = $orientationEntry->getValue();
            
            // Only proceed if orientation is not the default (1)
            if ($orientation == 1) {
                return;
            }
            
            // Read thumbnail JPEG and add EXIF orientation
            $thumbJpeg = new PelJpeg($thumbPath);
            
            // Create new EXIF data for thumbnail if it doesn't exist
            $thumbExif = $thumbJpeg->getExif();
            if ($thumbExif === null) {
                $thumbExif = new PelExif();
                $thumbJpeg->setExif($thumbExif);
            }
            
            $thumbTiff = $thumbExif->getTiff();
            if ($thumbTiff === null) {
                $thumbTiff = new PelTiff();
                $thumbExif->setTiff($thumbTiff);
            }
            
            $thumbIfd0 = $thumbTiff->getIfd();
            if ($thumbIfd0 === null) {
                $thumbIfd0 = new PelIfd(PelIfd::IFD0);
                $thumbTiff->setIfd($thumbIfd0);
            }
            
            // Set orientation in thumbnail
            $thumbIfd0->addEntry(new PelEntryShort(PelTag::ORIENTATION, $orientation));
            
            // Save the modified thumbnail
            $thumbJpeg->saveFile($thumbPath);
            
        } catch (Exception $e) {
            error_log("Error copying EXIF orientation using PEL: " . $e->getMessage());
        }
    }
    
    /**
     * Get or create a shared database connection
     */
    private static function getDbConnection() {
        if (self::$dbConnection === null) {
            try {
                $auth = new GalleryAuth();
                $app = $auth->getApp();
                $container = $app->getContainer();
                self::$dbConnection = $container->make('flarum.db');
            } catch (Exception $e) {
                error_log("Error creating database connection: " . $e->getMessage());
                throw $e;
            }
        }
        return self::$dbConnection;
    }
    
    /**
     * Batch load user display names for multiple user IDs
     */
    private static function batchLoadUserDisplayNames($userIds) {
        if (empty($userIds)) {
            return;
        }
        
        // Filter out already cached users and invalid IDs
        $uncachedUserIds = [];
        foreach ($userIds as $userId) {
            if ($userId && !isset(self::$userDisplayNameCache[$userId])) {
                $uncachedUserIds[] = $userId;
            }
        }
        
        if (empty($uncachedUserIds)) {
            return; // All users already cached
        }
        
        try {
            $db = self::getDbConnection();
            
            $users = $db->table('users')
                ->select('id', 'username', 'nickname')
                ->whereIn('id', $uncachedUserIds)
                ->get();
            
            foreach ($users as $user) {
                // Prefer nickname if available, fall back to username
                $displayName = $user->nickname ?: $user->username;
                self::$userDisplayNameCache[$user->id] = $displayName;
            }
            
            // Set default names for any user IDs that weren't found
            foreach ($uncachedUserIds as $userId) {
                if (!isset(self::$userDisplayNameCache[$userId])) {
                    self::$userDisplayNameCache[$userId] = "User {$userId}";
                }
            }
            
        } catch (Exception $e) {
            error_log("Error batch loading user display names: " . $e->getMessage());
            
            // Fallback: set default names for all requested users
            foreach ($uncachedUserIds as $userId) {
                self::$userDisplayNameCache[$userId] = "User {$userId}";
            }
        }
    }
    
    /**
     * Get cached user display name
     */
    private static function getCachedUserDisplayName($userId) {
        if (!$userId) {
            return 'Unknown User';
        }
        
        if (isset(self::$userDisplayNameCache[$userId])) {
            return self::$userDisplayNameCache[$userId];
        }
        
        // If not in cache, load it individually (fallback)
        return self::getUserDisplayName($userId);
    }
    
    /**
     * Get user display name by user ID
     */
    public static function getUserDisplayName($userId) {
        if (!$userId) {
            return 'Unknown User';
        }
        
        // Check cache first
        if (isset(self::$userDisplayNameCache[$userId])) {
            return self::$userDisplayNameCache[$userId];
        }
        
        try {
            $db = self::getDbConnection();

            $userData = $db->table('users')
                ->select('username', 'nickname')
                ->where('id', $userId)
                ->first();
            
            if ($userData) {
                // Prefer nickname if available, fall back to username
                $displayName = $userData->nickname ?: $userData->username;
                self::$userDisplayNameCache[$userId] = $displayName;
                return $displayName;
            }
            
            $fallbackName = "User {$userId}";
            self::$userDisplayNameCache[$userId] = $fallbackName;
            return $fallbackName;
            
        } catch (Exception $e) {
            error_log("Error getting user display name: " . $e->getMessage());
            $fallbackName = "User {$userId}";
            self::$userDisplayNameCache[$userId] = $fallbackName;
            return $fallbackName;
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
    
    /**
     * Regenerate all thumbnails for a specific year
     * Returns array with results
     */
    public static function regenerateThumbnails($year) {
        $yearPath = GalleryConfig::getYearPath($year);
        $thumbsDir = $yearPath . DIRECTORY_SEPARATOR . 'thumbs';
        
        // Create thumbs directory if it doesn't exist
        if (!is_dir($thumbsDir)) {
            mkdir($thumbsDir, 0755, true);
        }
        
        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        // Start time tracking to prevent timeouts
        $startTime = time();
        $maxExecutionTime = 25; // Leave 5 seconds buffer before 30-second timeout
        
        // Get all image files for the year
        $allFiles = GalleryConfig::getOrderedFiles($year);
        $imageFiles = array_filter($allFiles, function($filename) {
            return GalleryConfig::isImage($filename);
        });
        
        foreach ($imageFiles as $filename) {
            // Check execution time to prevent timeout
            if (time() - $startTime > $maxExecutionTime) {
                $results['errors'][] = "Stopped early to prevent timeout. Process remaining files manually.";
                break;
            }
            
            $results['processed']++;
            
            try {
                // Delete existing thumbnail if it exists
                $thumbPath = $thumbsDir . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
                
                // Generate new thumbnail
                $thumbnailUrl = self::generateThumbnail($year, $filename);
                
                if ($thumbnailUrl) {
                    $results['succeeded']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to generate thumbnail for: {$filename}";
                }
                
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Error processing {$filename}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
}
?>
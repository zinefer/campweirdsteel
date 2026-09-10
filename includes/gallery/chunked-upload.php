<?php
/**
 * Chunked Upload
 *
 * A whole video never travels in one request. The browser slices the file and
 * posts the pieces one at a time; they land here, in a per-upload staging
 * directory, and are stitched back together when the last one arrives.
 *
 * That keeps every request small, so nginx's client_max_body_size and PHP's
 * post_max_size stop being the ceiling on how big a file the gallery accepts —
 * and a dropped connection on camp wifi costs one 5MB chunk instead of the
 * whole upload.
 */

// Absolute for the same reason as manager.php: a bare 'config.php' resolves
// against the working directory first, where a deployed app root has Flarum's.
require_once __DIR__ . '/config.php';

class ChunkedUpload {

    /**
     * Open a staging directory for a file that is about to arrive.
     *
     * Returns the upload id the browser quotes on every subsequent request.
     *
     * @param int    $year         Year the file is destined for
     * @param string $originalName Filename as the browser reported it
     * @param int    $size         Total byte size the browser promises to send
     * @param int    $attributeTo  User the finished file is credited to
     * @param int    $sessionUser  User whose session opened this upload
     */
    public static function init($year, $originalName, $size, $attributeTo, $sessionUser) {
        if (!GalleryConfig::isAllowedFileType($originalName)) {
            throw new Exception('File type not allowed');
        }

        if ($size <= 0) {
            throw new Exception('That file is empty');
        }

        $maxSize = GalleryConfig::maxSizeFor($originalName);
        if ($size > $maxSize) {
            throw new Exception('File too large. Maximum size is ' . self::formatSize($maxSize) . '.');
        }

        // Opportunistic cleanup. There is no cron on this box, so the only
        // thing that will ever collect an abandoned upload is another upload.
        self::collectStale();

        if (self::countSessions() >= GalleryConfig::MAX_ACTIVE_UPLOADS) {
            throw new Exception('Too many uploads are in progress right now. Try again in a few minutes.');
        }

        $uploadId = bin2hex(random_bytes(16));
        $uploadPath = GalleryConfig::getUploadsPath() . DIRECTORY_SEPARATOR . $uploadId;

        if (!mkdir($uploadPath, 0755, true)) {
            throw new Exception('Failed to open upload session');
        }

        $meta = [
            'id'           => $uploadId,
            'year'         => (int) $year,
            'originalName' => $originalName,
            'size'         => (int) $size,
            'totalChunks'  => (int) ceil($size / GalleryConfig::CHUNK_SIZE),
            'attributeTo'  => (int) $attributeTo,
            'sessionUser'  => (int) $sessionUser,
            'createdAt'    => time(),
        ];

        self::writeMeta($uploadPath, $meta);

        return $meta;
    }

    /**
     * Store one chunk.
     *
     * Chunks are written under their index rather than appended to a single
     * file, so a retried chunk overwrites cleanly and arrival order does not
     * matter.
     */
    public static function storeChunk($uploadId, $index, $uploadedFile, $sessionUser) {
        $uploadPath = self::resolve($uploadId);
        $meta = self::readMeta($uploadPath);

        if ($meta['sessionUser'] !== (int) $sessionUser) {
            throw new Exception('Upload session does not belong to you');
        }

        $index = (int) $index;
        if ($index < 0 || $index >= $meta['totalChunks']) {
            throw new Exception('Chunk index out of range');
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(self::uploadErrorMessage($uploadedFile['error']));
        }

        if ($uploadedFile['size'] > GalleryConfig::MAX_CHUNK_SIZE) {
            throw new Exception('Chunk too large');
        }

        // Bound what a session can put on disk before assemble() gets a
        // chance to check the total. totalChunks is derived from CHUNK_SIZE
        // (5MB) but a request may carry up to MAX_CHUNK_SIZE (8MB), so a
        // client that ignores the chunk size we handed it could otherwise
        // stage ~1.6x the size it declared.
        list(, $stagedBytes) = self::chunkStats($uploadPath, $index);
        if ($stagedBytes + $uploadedFile['size'] > $meta['size']) {
            throw new Exception('Upload is larger than the size it declared');
        }

        $chunkPath = $uploadPath . DIRECTORY_SEPARATOR . self::chunkName($index);
        if (!move_uploaded_file($uploadedFile['tmp_name'], $chunkPath)) {
            throw new Exception('Failed to store chunk');
        }
        chmod($chunkPath, 0644);

        list($received) = self::chunkStats($uploadPath);

        return [
            'received' => $received,
            'total'    => $meta['totalChunks'],
        ];
    }

    /**
     * Turn PHP's upload error code into something a camp member can act on.
     *
     * UPLOAD_ERR_INI_SIZE is the one that matters: the default
     * upload_max_filesize on a fresh box is 2M, which is smaller than a
     * single 5MB chunk, so every upload fails on its first slice. "Chunk
     * upload error: 1" pointed at nothing.
     */
    private static function uploadErrorMessage($code) {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The server is refusing a ' . self::formatSize(GalleryConfig::CHUNK_SIZE)
                    . ' upload chunk. PHP\'s upload_max_filesize and post_max_size must be at least '
                    . self::formatSize(GalleryConfig::MAX_CHUNK_SIZE) . ' — ask an admin to check php.ini.';
            case UPLOAD_ERR_FORM_SIZE:
                return 'Upload chunk rejected as too large by the browser form limit.';
            case UPLOAD_ERR_PARTIAL:
                return 'That piece of the upload arrived incomplete.';
            case UPLOAD_ERR_NO_FILE:
                return 'No upload chunk was received.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'The server has no temp directory to receive uploads. Ask an admin to check php.ini.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not write the upload to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension blocked the upload.';
            default:
                return 'Chunk upload error: ' . $code;
        }
    }

    /**
     * Stitch the chunks back together.
     *
     * Returns [$assembledPath, $meta]. The caller owns the assembled file and
     * must move or delete it; discard() clears the staging directory.
     */
    public static function assemble($uploadId, $sessionUser) {
        $uploadPath = self::resolve($uploadId);
        $meta = self::readMeta($uploadPath);

        if ($meta['sessionUser'] !== (int) $sessionUser) {
            throw new Exception('Upload session does not belong to you');
        }

        for ($i = 0; $i < $meta['totalChunks']; $i++) {
            if (!file_exists($uploadPath . DIRECTORY_SEPARATOR . self::chunkName($i))) {
                throw new Exception("Upload incomplete: chunk {$i} is missing");
            }
        }

        $assembledPath = $uploadPath . DIRECTORY_SEPARATOR . 'assembled';
        $out = fopen($assembledPath, 'wb');
        if ($out === false) {
            throw new Exception('Failed to assemble upload');
        }

        try {
            for ($i = 0; $i < $meta['totalChunks']; $i++) {
                $in = fopen($uploadPath . DIRECTORY_SEPARATOR . self::chunkName($i), 'rb');
                if ($in === false) {
                    throw new Exception("Failed to read chunk {$i}");
                }
                try {
                    // Streamed rather than file_get_contents()'d: a 500MB
                    // video must never have to fit in PHP's memory_limit all
                    // at once.
                    stream_copy_to_stream($in, $out);
                } finally {
                    fclose($in);
                }
            }
        } finally {
            fclose($out);
        }

        // The browser told us how big this would be before it started. If the
        // result disagrees, something was lost or duplicated in transit and we
        // would rather fail than file a corrupt video.
        $actualSize = filesize($assembledPath);
        if ($actualSize !== $meta['size']) {
            throw new Exception("Assembled size ({$actualSize}) does not match the expected size ({$meta['size']})");
        }

        return [$assembledPath, $meta];
    }

    /**
     * Cancel an upload on the user's own say-so.
     *
     * Unlike discard(), this checks whose session opened the upload — it is
     * reachable directly from the API, and a guessed id should not let one
     * member throw away another's in-progress upload.
     */
    public static function abort($uploadId, $sessionUser) {
        $uploadPath = self::resolve($uploadId);
        $meta = self::readMeta($uploadPath);

        if ($meta['sessionUser'] !== (int) $sessionUser) {
            throw new Exception('Upload session does not belong to you');
        }

        self::removeDirectory($uploadPath);
    }

    /**
     * Delete a staging directory and everything in it.
     */
    public static function discard($uploadId) {
        try {
            $uploadPath = self::resolve($uploadId);
        } catch (Exception $e) {
            return; // Already gone, which is the state we wanted.
        }

        self::removeDirectory($uploadPath);
    }

    /**
     * Drop staging directories that were never finished.
     *
     * An upload the user walked away from leaves its chunks behind; without
     * this they would accumulate in .uploads forever.
     */
    public static function collectStale() {
        try {
            $uploadsPath = GalleryConfig::getUploadsPath();
        } catch (Exception $e) {
            return;
        }

        $cutoff = time() - GalleryConfig::UPLOAD_SESSION_TTL;

        foreach (scandir($uploadsPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $path = $uploadsPath . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) continue;

            if (filemtime($path) < $cutoff) {
                self::removeDirectory($path);
            }
        }
    }

    /**
     * Turn an upload id into its staging path, refusing anything that isn't
     * one of ours. The id goes into a filesystem path, so it is checked
     * against the exact shape init() hands out rather than merely sanitized.
     */
    private static function resolve($uploadId) {
        if (!is_string($uploadId) || !preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new Exception('Invalid upload id');
        }

        $uploadPath = GalleryConfig::getUploadsPath() . DIRECTORY_SEPARATOR . $uploadId;
        if (!is_dir($uploadPath)) {
            throw new Exception('Upload session not found or expired');
        }

        return $uploadPath;
    }

    /**
     * How many staging directories currently exist.
     */
    private static function countSessions() {
        try {
            $uploadsPath = GalleryConfig::getUploadsPath();
        } catch (Exception $e) {
            return 0;
        }

        $count = 0;
        foreach (scandir($uploadsPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir($uploadsPath . DIRECTORY_SEPARATOR . $entry)) {
                $count++;
            }
        }

        return $count;
    }

    private static function chunkName($index) {
        return sprintf('chunk_%05d', $index);
    }

    /**
     * How many chunks are staged and how many bytes they occupy.
     *
     * $excludeIndex leaves one chunk out of the total, so a retry of that
     * chunk is measured against what it will replace rather than what it is
     * being added to.
     *
     * @return array [count, bytes]
     */
    private static function chunkStats($uploadPath, $excludeIndex = null) {
        $count = 0;
        $bytes = 0;
        $exclude = $excludeIndex === null ? null : self::chunkName((int) $excludeIndex);

        foreach (scandir($uploadPath) ?: [] as $entry) {
            if (strpos($entry, 'chunk_') !== 0) {
                continue;
            }
            $count++;
            if ($entry === $exclude) {
                continue;
            }
            $bytes += (int) @filesize($uploadPath . DIRECTORY_SEPARATOR . $entry);
        }

        return [$count, $bytes];
    }

    private static function writeMeta($uploadPath, array $meta) {
        $metaPath = $uploadPath . DIRECTORY_SEPARATOR . 'meta.json';
        if (file_put_contents($metaPath, json_encode($meta)) === false) {
            throw new Exception('Failed to record upload session');
        }
        chmod($metaPath, 0644);
    }

    private static function readMeta($uploadPath) {
        $metaPath = $uploadPath . DIRECTORY_SEPARATOR . 'meta.json';
        $raw = @file_get_contents($metaPath);
        if ($raw === false) {
            throw new Exception('Upload session is missing its metadata');
        }

        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            throw new Exception('Upload session metadata is unreadable');
        }

        return $meta;
    }

    private static function removeDirectory($path) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? self::removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }

    private static function formatSize($bytes) {
        return round($bytes / (1024 * 1024)) . 'MB';
    }
}

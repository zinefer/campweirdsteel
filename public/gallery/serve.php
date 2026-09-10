<?php
/**
 * Gallery File Server
 * Serves images/videos with authentication
 */

require_once '../../includes/gallery/auth.php';
require_once '../../includes/gallery/manager.php';

// How much is read from disk per write. Videos are now up to MAX_VIDEO_SIZE,
// so nothing here may ever hold a whole file — or a whole requested range —
// in memory at once.
const SERVE_CHUNK_SIZE = 262144; // 256KB

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

// Additional security: validate filename format
if (!preg_match('/^[a-zA-Z0-9._\/-]+$/', $filename)) {
    http_response_code(400);
    exit('Invalid filename');
}

// Additional input validation
if ($year < 2000 || $year > 3000) {
    http_response_code(400);
    exit('Invalid year');
}

/**
 * Parse a Range header against a known file size.
 *
 * Returns [$start, $end] inclusive, null if the header should be ignored
 * (absent, or a form we do not serve), or false if it is unsatisfiable and
 * the caller should answer 416.
 *
 * Handles all three shapes browsers actually send: `bytes=0-1023`,
 * `bytes=1024-` (the common seek, and the one the old code turned into a
 * read of the entire remainder of the file), and `bytes=-1024`.
 */
function parseRange($header, $filesize) {
    if (!is_string($header) || !preg_match('/^bytes=(.*)$/i', trim($header), $m)) {
        return null;
    }

    // Multi-range needs a multipart/byteranges body. No player asks for one
    // for progressive video, so serve the whole file instead of getting it
    // subtly wrong.
    if (strpos($m[1], ',') !== false) {
        return null;
    }

    if (!preg_match('/^(\d*)-(\d*)$/', trim($m[1]), $parts)) {
        return null;
    }

    list(, $rawStart, $rawEnd) = $parts;

    if ($rawStart === '' && $rawEnd === '') {
        return null;
    }

    if ($rawStart === '') {
        // Suffix range: the last N bytes.
        $suffix = (int) $rawEnd;
        if ($suffix <= 0) {
            return false;
        }
        $start = max(0, $filesize - $suffix);
        $end = $filesize - 1;
    } else {
        $start = (int) $rawStart;
        $end = $rawEnd === '' ? $filesize - 1 : (int) $rawEnd;
    }

    if ($start >= $filesize || $start < 0 || $end < $start) {
        return false;
    }

    // Clamp rather than reject: a client asking past the end of the file is
    // asking for what is there.
    $end = min($end, $filesize - 1);

    return [$start, $end];
}

/**
 * Copy a byte range from a file to the client without buffering it.
 */
function streamRange($filePath, $start, $end) {
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        return;
    }

    // Output buffering would defeat the point of chunking, and PHP-FPM has
    // one on by default.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    fseek($handle, $start);
    $remaining = $end - $start + 1;

    while ($remaining > 0 && !feof($handle)) {
        $buffer = fread($handle, (int) min(SERVE_CHUNK_SIZE, $remaining));
        if ($buffer === false || $buffer === '') {
            break;
        }

        echo $buffer;
        flush();

        $remaining -= strlen($buffer);

        // A user who scrubbed away or closed the tab should not keep a worker
        // pushing the rest of a 500MB file into a dead socket.
        if (connection_aborted()) {
            break;
        }
    }

    fclose($handle);
}

try {
    $filePath = GalleryManager::serveFile($year, $filename);

    $filesize = filesize($filePath);
    $mimeType = mime_content_type($filePath);

    header('Content-Type: ' . $mimeType);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; media-src \'self\';');

    // Advertised unconditionally. Sent only alongside a 206 before, which
    // left players with no way to know seeking was available until they had
    // already guessed at a range.
    header('Accept-Ranges: bytes');

    $range = isset($_SERVER['HTTP_RANGE']) ? parseRange($_SERVER['HTTP_RANGE'], $filesize) : null;

    if ($range === false) {
        http_response_code(416);
        header("Content-Range: bytes */{$filesize}");
        header('Content-Length: 0');
        exit;
    }

    if ($range !== null) {
        list($start, $end) = $range;

        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$filesize}");
        header('Content-Length: ' . ($end - $start + 1));

        streamRange($filePath, $start, $end);
        exit;
    }

    header('Content-Length: ' . $filesize);
    streamRange($filePath, 0, $filesize - 1);

} catch (Exception $e) {
    http_response_code(404);
    exit('File not found');
}

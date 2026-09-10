<?php
/**
 * Gallery API
 * Handles AJAX requests for gallery operations
 */

require_once '../../includes/gallery/auth.php';
require_once '../../includes/gallery/manager.php';
require_once '../../includes/gallery/chunked-upload.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

// Require authentication
$auth = new GalleryAuth();
$auth->requireAuth();

// A deploy that lands this code on an archive still keyed by the old scheme
// converts it here, before any response carries a filename the client would
// then act on. Once the archive is current this is a single stat().
try {
    OrderingMigration::ensureArchiveMigrated();
} catch (Exception $e) {
    // Never take the gallery down over this. What it could not convert is a
    // sort order, and the upload and reorder paths retry per year anyway.
    error_log('Gallery ordering migration failed: ' . $e->getMessage());
}

$user = $auth->getUser();
$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    switch ($action) {
        case 'years':
            // `summaries` is what the sidebar reads: year, counts and total
            // bytes, straight from the directory listing. It replaces a
            // per-year call to the `files` action below, which opened every
            // image for EXIF and ran ffmpeg for every video just to print
            // "12 files". `years` stays for anything else that expects it.
            echo json_encode([
                'years'     => GalleryConfig::getAvailableYears(),
                'summaries' => GalleryConfig::getYearSummaries(),
            ]);
            break;
            
        case 'users':
            // Only allow admins to get user list
            if (!$auth->isAdmin()) {
                throw new Exception('Admin access required');
            }
            
            $db = $auth->getDbConnection();
            
            $users = $db->table('users')
                ->select('id', 'username', 'nickname')
                ->orderBy('username')
                ->get();
            
            $userList = [];
            foreach ($users as $userRow) {
                $userList[] = [
                    'id' => $userRow->id,
                    'username' => $userRow->username,
                    'display_name' => $userRow->nickname ?: $userRow->username,
                ];
            }
            
            echo json_encode(['users' => $userList]);
            break;
            
        case 'files':
            $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
            if (!$year || $year < 2000 || $year > 3000) {
                throw new Exception('Invalid year');
            }
            
            $files = GalleryManager::getFiles($year);
            
            // Add thumbnail/poster URLs and enhanced metadata for all media types
            foreach ($files as &$file) {
                if ($file['type'] === 'image') {
                    // Check if thumbnail exists, if not set to null
                    $yearPath = GalleryConfig::getYearPath($year);
                    $thumbPath = $yearPath . DIRECTORY_SEPARATOR . "thumbs" . DIRECTORY_SEPARATOR . $file['filename'];
                    if (file_exists($thumbPath)) {
                        $file['thumbnail'] = "serve.php?year={$year}&file=thumbs/" . urlencode($file['filename']);
                    } else {
                        $file['thumbnail'] = null;
                    }
                    
                    // Try to extract EXIF data for better metadata
                    $exifData = GalleryManager::getImageMetadata($year, $file['filename']);
                    if ($exifData) {
                        $file = array_merge($file, $exifData);
                    }
                } else {
                    // A video still being re-encoded is reported as such and
                    // otherwise left alone: what is on disk is the camera's
                    // original, which is the very thing that may not play, and
                    // grabbing a poster frame off it would put a second ffmpeg
                    // on the same file the worker is reading.
                    $file['processing'] = GalleryManager::isProcessing($year, $file['filename']);

                    if ($file['processing']) {
                        $file['poster'] = null;
                    } else {
                        // For videos, try to generate poster image
                        $file['poster'] = GalleryManager::generateVideoPoster($year, $file['filename']);

                        // The poster's dimensions are the video's dimensions, and
                        // the grid needs them to size the tile before anything
                        // loads. Reading the poster header is cheap; probing the
                        // video would not be.
                        $posterSize = GalleryManager::getPosterDimensions($year, $file['filename']);
                        if ($posterSize) {
                            $file = array_merge($file, $posterSize);
                        }
                    }
                }
            }
            
            echo json_encode(['files' => $files]);
            break;
            
        // Uploads arrive in three steps: open a session, post the chunks,
        // then finalize. Even a small photo goes this way — one code path is
        // worth more than the round trip it saves.
        case 'upload_init':
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

            $originalName = isset($_POST['filename']) ? trim($_POST['filename']) : '';
            $size = isset($_POST['size']) ? (int)$_POST['size'] : 0;

            if ($originalName === '') {
                throw new Exception('No filename given');
            }

            // Validate file type more strictly
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'webm', 'mov', 'avi'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions)) {
                throw new Exception('File type not allowed. Please use: ' . implode(', ', $allowedExtensions));
            }

            // Handle "upload on behalf" functionality for admins
            $uploadUserId = $user->id; // Default to current user
            $uploadOnBehalf = isset($_POST['uploadOnBehalf']) ? (int)$_POST['uploadOnBehalf'] : null;

            if ($uploadOnBehalf && $uploadOnBehalf !== $user->id) {
                // Only admins can upload on behalf of others
                if (!$auth->isAdmin()) {
                    throw new Exception('Admin access required to upload on behalf of others');
                }

                // Validate that the target user exists
                if (!$auth->validateUser($uploadOnBehalf)) {
                    throw new Exception('Target user not found');
                }

                $uploadUserId = $uploadOnBehalf;
            }

            $meta = ChunkedUpload::init($year, $originalName, $size, $uploadUserId, $user->id);

            echo json_encode([
                'success'     => true,
                'uploadId'    => $meta['id'],
                'chunkSize'   => GalleryConfig::CHUNK_SIZE,
                'totalChunks' => $meta['totalChunks'],
            ]);
            break;

        case 'upload_chunk':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $uploadId = isset($_POST['uploadId']) ? trim($_POST['uploadId']) : '';
            $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;

            if (!isset($_FILES['chunk'])) {
                throw new Exception('No chunk uploaded');
            }

            $progress = ChunkedUpload::storeChunk($uploadId, $index, $_FILES['chunk'], $user->id);

            echo json_encode(array_merge(['success' => true], $progress));
            break;

        case 'upload_finalize':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $uploadId = isset($_POST['uploadId']) ? trim($_POST['uploadId']) : '';

            list($assembledPath, $meta) = ChunkedUpload::assemble($uploadId, $user->id);

            // The year is re-checked against the clock rather than trusted
            // from the session: an upload opened before midnight on December
            // 31st must not file into a year that is now closed.
            if (!$auth->canUploadForYear($meta['year'])) {
                ChunkedUpload::discard($uploadId);
                throw new Exception('Cannot upload for this year');
            }

            try {
                $result = GalleryManager::storeUpload(
                    $meta['year'],
                    $assembledPath,
                    $meta['originalName'],
                    $meta['attributeTo']
                );
            } finally {
                // Clears the chunks either way. storeUpload() renames the
                // assembled file out on success, so this only ever removes
                // leftovers.
                ChunkedUpload::discard($uploadId);
            }

            $message = $result['processing']
                ? 'Uploaded. Converting the video for playback — it will appear shortly.'
                : 'File uploaded successfully';

            if ($meta['attributeTo'] !== (int)$user->id) {
                $targetUser = $auth->validateUser($meta['attributeTo']);
                if ($targetUser) {
                    $message .= " (on behalf of {$targetUser->username})";
                }
            }

            echo json_encode([
                'success'         => true,
                'filename'        => $result['filename'],
                'processing'      => $result['processing'],
                'message'         => $message,
                'size'            => $meta['size'],
                'type'            => strtolower(pathinfo($meta['originalName'], PATHINFO_EXTENSION)),
                'uploadedForUser' => $meta['attributeTo'],
            ]);
            break;

        case 'upload_abort':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            ChunkedUpload::abort(isset($_POST['uploadId']) ? trim($_POST['uploadId']) : '', $user->id);

            echo json_encode(['success' => true]);
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
            
            // The client hides Delete on a converting tile, but that is a
            // courtesy, not a guarantee. Soft delete only renames the source;
            // the worker would then write its finished .mp4 back under the
            // original base name and the "deleted" video would reappear.
            if (GalleryManager::isProcessing($year, $filename)) {
                throw new Exception('That video is still being converted. Try again once it is ready.');
            }

            GalleryManager::deleteFile($year, $filename, $user->id, $auth->isAdmin());

            echo json_encode([
                'success' => true,
                'message' => 'File deleted successfully'
            ]);
            break;
            
        // The date a file is filed under. Sent as a Unix timestamp built from
        // the browser's own clock, because that is the clock the gallery
        // renders dates against — a naive datetime string would shift by the
        // gap between the viewer's timezone and the server's.
        case 'set_date':
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

            if (!isset($_POST['timestamp']) || !is_numeric($_POST['timestamp'])) {
                throw new Exception('Invalid date');
            }

            $timestamp = GalleryManager::setCaptureDate(
                $year,
                $filename,
                (int)$_POST['timestamp'],
                $user->id,
                $auth->isAdmin()
            );

            echo json_encode([
                'success' => true,
                'modified' => $timestamp,
                'message' => 'Date updated'
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
            
            // Same race as delete: reordering renames the source, and the
            // worker's target path was computed from the old name, so the
            // encode would land back under the old ordering token as a
            // duplicate.
            if (GalleryManager::isProcessing($year, $filename)) {
                throw new Exception('That video is still being converted. Try again once it is ready.');
            }

            $newFilename = GalleryManager::reorderFile($year, $filename, $newPosition, $user->id, $auth->isAdmin());
            
            echo json_encode([
                'success' => true,
                'newFilename' => $newFilename,
                'message' => 'File reordered successfully'
            ]);
            break;
            
        case 'generate_thumbnail':
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
            
            $thumbnailUrl = GalleryManager::generateThumbnail($year, $filename);
            
            if ($thumbnailUrl) {
                echo json_encode([
                    'success' => true,
                    'thumbnail' => $thumbnailUrl,
                    'message' => 'Thumbnail generated successfully'
                ]);
            } else {
                throw new Exception('Failed to generate thumbnail');
            }
            break;
            
        case 'regenerate_thumbnails':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            if (!$year || $year < 2000 || $year > 3000) {
                throw new Exception('Invalid year');
            }
            
            // Rebuilding every thumbnail for a year is expensive enough to be
            // worth restricting; it was open to any signed-in member.
            if (!$auth->isAdmin()) {
                throw new Exception('Admin access required');
            }
            
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

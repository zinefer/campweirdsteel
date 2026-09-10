<?php
/**
 * Video Transcode Worker (CLI only)
 *
 * Re-encodes one uploaded video to h264/aac mp4 and puts it back in place of
 * the original, then rebuilds its poster frame.
 *
 * Run detached by GalleryManager::queueTranscode() right after an upload
 * lands, because a phone video takes minutes to encode and a web request gets
 * thirty seconds. It can also be run by hand to reprocess something:
 *
 *     php includes/gallery/transcode.php 2026 n_12_sunset.mov
 *
 * Or as the resident drain service, which is how uploads actually get
 * converted — one process working the queue instead of one process per video:
 *
 *     php includes/gallery/transcode.php --drain
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script is CLI only\n");
}

require_once __DIR__ . '/manager.php';

use Symfony\Component\Process\Process;

// No time limit: the encode is the whole point of this process existing.
set_time_limit(0);

// Get out of the web server's way.
//
// The concurrency cap keeps the number of encoder threads at or below the core
// count, but "at" still means a page load competing with ffmpeg for a core on
// equal terms. Encoding is background work that nobody is waiting on
// synchronously — a video that takes 9 minutes instead of 8 costs nothing,
// whereas a gallery page that takes 4 seconds instead of 0.4 is the thing
// people actually notice.
//
// Set here rather than in the spawn command line: ffmpeg and ffprobe are
// children of this process and inherit both niceness and I/O class, so one
// call covers everything the worker starts. Wrapping the nohup line in
// `nice … ionice …` instead would mean a missing ionice binary stopped the
// worker from running at all.
lowerPriority();

// Service mode: stay resident and work the queue. This is how uploads are
// normally converted; see campweirdsteel-transcode.service.
if (isset($argv[1]) && $argv[1] === '--drain') {
    exit(drain());
}

$year = isset($argv[1]) ? (int) $argv[1] : 0;
$filename = isset($argv[2]) ? $argv[2] : '';

if (!$year || $year < 2000 || $year > 3000) {
    logLine("Invalid year");
    exit(1);
}

// The filename comes from generateSafeFilename(), so it is already restricted
// to this alphabet. Checking it again here matters because this script is
// also runnable by hand.
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    logLine("Invalid filename");
    exit(1);
}

try {
    exit(transcode($year, $filename));
} catch (TranscodeFailure $e) {
    logLine($e->getMessage());
    exit(1);
}

/**
 * Re-encode one video in place. Returns a process exit code.
 */
function transcode($year, $filename) {
    $yearPath = GalleryConfig::getYearPath($year);
    $sourcePath = $yearPath . DIRECTORY_SEPARATOR . $filename;

    if (!GalleryConfig::isVideo($filename)) {
        fail("Not a video: {$filename}");
    }

    if (!file_exists($sourcePath)) {
        GalleryManager::clearProcessing($year, $filename);
        fail("File not found: {$sourcePath}");
    }

    if (!is_executable(GalleryConfig::FFMPEG_BINARY)) {
        GalleryManager::clearProcessing($year, $filename);
        fail("ffmpeg not found at " . GalleryConfig::FFMPEG_BINARY);
    }

    // Claim the marker before doing anything slow. Until this happens the
    // gallery treats the marker as an unproven spawn and gives up on it after
    // TRANSCODE_START_GRACE.
    GalleryManager::markTranscodeStarted($year, $filename);

    // Wait our turn. The handle has to outlive the encode — closing it, or
    // letting it fall out of scope, releases the lock.
    $slot = GalleryManager::acquireTranscodeSlot($year, $filename);

    // The wait can be long, and the file may have been deleted or reordered
    // out from under us while we queued.
    if (!file_exists($sourcePath)) {
        GalleryManager::clearProcessing($year, $filename);
        logLine("Gave up on {$filename}: it was removed or renamed while queued");
        return 0;
    }

    $baseName = pathinfo($filename, PATHINFO_FILENAME);
    $targetName = $baseName . '.mp4';
    $targetPath = $yearPath . DIRECTORY_SEPARATOR . $targetName;
    $workPath = $yearPath . DIRECTORY_SEPARATOR . '.processing'
        . DIRECTORY_SEPARATOR . $baseName . '.work.mp4';

    // Renaming onto an unrelated file would destroy it silently. Ordering
    // tokens make this near-impossible for uploads, but this script is
    // documented as hand-runnable and the check costs nothing.
    if ($targetPath !== $sourcePath && file_exists($targetPath)) {
        GalleryManager::clearProcessing($year, $filename);
        fail("Refusing to overwrite existing {$targetName}");
    }

    $sourceSize = filesize($sourcePath);
    $probe = probe($sourcePath);

    // Nothing to do: this is already h264/aac in an mp4 within the size cap,
    // which is all the encode below would have made it. Bail out before ffmpeg
    // rather than after, so a file that comes back around the queue is not
    // quietly re-encoded a generation worse each time.
    if (alreadyWebReady($filename, $probe)) {
        GalleryManager::clearProcessing($year, $filename);
        logLine("Skipped {$filename}: already h264"
            . ($probe['audio'] === null ? '' : '/' . $probe['audio'])
            . " mp4 at {$probe['width']}x{$probe['height']}");
        return 0;
    }

    // The encode produces a brand new file with a brand new mtime, and the
    // gallery dates every tile by mtime. Remember what this video is dated as
    // now so the swap below can carry it across. An upload arrives already
    // stamped by storeUpload(); reading the container again covers the
    // hand-run case, where the file on disk predates that.
    $sourceStamp = GalleryManager::extractCaptureTime($sourcePath, $filename);
    if ($sourceStamp === null) {
        $sourceStamp = filemtime($sourcePath);
    }

    logLine("Transcoding {$filename} (" . formatSize($sourceSize) . ", "
        . ($probe['codec'] ?: 'unknown codec') . " {$probe['width']}x{$probe['height']})");

    $started = time();
    $exitCode = runFfmpeg($sourcePath, $workPath);
    $elapsed = time() - $started;

    if ($exitCode !== 0 || !file_exists($workPath) || filesize($workPath) === 0) {
        @unlink($workPath);
        GalleryManager::clearProcessing($year, $filename);
        fail("ffmpeg failed for {$filename} (exit {$exitCode}) after {$elapsed}s; keeping the original");
    }

    $newSize = filesize($workPath);

    // If the upload was already a web-friendly mp4 and re-encoding it only
    // made it bigger, the transcode has nothing to offer. Note this is
    // deliberately narrow: an HEVC .mov off an iPhone gets replaced even when
    // it grows, because Chrome cannot play the original at all.
    //
    // This only catches what alreadyWebReady() would not commit to — an
    // oversized h264 mp4, say, or one whose audio needed converting. A file it
    // did recognise never reaches the encode at all.
    if ($newSize >= $sourceSize && $probe['codec'] === 'h264'
        && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4') {
        @unlink($workPath);
        GalleryManager::clearProcessing($year, $filename);
        logLine("Kept original {$filename}: already h264 mp4 and the re-encode was larger ("
            . formatSize($newSize) . " vs " . formatSize($sourceSize) . ")");
        return 0;
    }

    // Last check before the swap. An encode takes minutes, and a delete in
    // that window would otherwise be undone by the rename below — the soft
    // delete only renames the source, so writing the target back would put an
    // apparently-deleted video straight back into the grid.
    if (!file_exists($sourcePath)) {
        @unlink($workPath);
        GalleryManager::clearProcessing($year, $filename);
        logLine("Discarded encode of {$filename}: it was removed or renamed while encoding");
        return 0;
    }

    // Swap the encoded file in. Same directory, so this is an atomic rename;
    // the gallery never sees a half-written video.
    if (!rename($workPath, $targetPath)) {
        @unlink($workPath);
        GalleryManager::clearProcessing($year, $filename);
        fail("Failed to move encoded file into place for {$filename}");
    }
    chmod($targetPath, 0644);

    // Put the original date back on the re-encoded file.
    if ($sourceStamp) {
        @touch($targetPath, $sourceStamp);
    }

    // A .mov or .avi source leaves its original behind under the old
    // extension once the .mp4 has taken its place.
    if ($targetPath !== $sourcePath) {
        @unlink($sourcePath);
    }

    // The poster is named after the base filename, so it survived the
    // extension change but now shows a frame from the old encode.
    $posterPath = $yearPath . DIRECTORY_SEPARATOR . 'thumbs'
        . DIRECTORY_SEPARATOR . $baseName . '.jpg';
    @unlink($posterPath);

    try {
        GalleryManager::generateVideoPoster($year, $targetName);
    } catch (Exception $e) {
        logLine("Poster generation failed for {$targetName}: " . $e->getMessage());
    }

    GalleryManager::clearProcessing($year, $filename);

    // Explicit, so the slot is released before the poster-less tail of any
    // future work rather than at process teardown.
    if (is_resource($slot)) {
        flock($slot, LOCK_UN);
        fclose($slot);
    }

    $saved = $sourceSize > 0 ? round((1 - $newSize / $sourceSize) * 100) : 0;
    logLine("Finished {$filename} -> {$targetName} in {$elapsed}s: "
        . formatSize($sourceSize) . " -> " . formatSize($newSize) . " ({$saved}% smaller)");

    return 0;
}

/**
 * Drop this process to the lowest CPU and I/O priority available.
 *
 * Both are best-effort and both are inherited by ffmpeg. Raising niceness
 * needs no privilege, and the idle I/O class has needed none since 2.6.25 —
 * but neither is worth failing an encode over, so every step is guarded.
 */
function lowerPriority() {
    if (function_exists('proc_nice')) {
        @proc_nice(19);
    }

    // No PHP equivalent for the I/O side. Streaming a 500MB source off cold
    // storage and writing the result back competes with the same disk the
    // gallery reads thumbnails from.
    $ionice = '/usr/bin/ionice';
    if (!is_executable($ionice)) {
        return;
    }

    try {
        // -c 3 is the idle class: only gets the disk when nothing else wants it.
        $process = new Process([$ionice, '-c', '3', '-p', (string) getmypid()]);
        $process->setTimeout(10);
        $process->run();
    } catch (Exception $e) {
        // Priority is an optimisation; the encode matters more.
    }
}

/**
 * Run the encode. Returns ffmpeg's exit code.
 */
function runFfmpeg($sourcePath, $targetPath) {
    // Limit the long edge rather than the width, so a portrait phone video
    // gets scaled down by its height instead of being left at full size.
    $maxEdge = (int) GalleryConfig::VIDEO_MAX_WIDTH;
    $scale = "scale="
        . "'if(gt(iw,ih),trunc(min({$maxEdge},iw)/2)*2,-2)'"
        . ":'if(gt(iw,ih),-2,trunc(min({$maxEdge},ih)/2)*2)'";

    $args = [
        GalleryConfig::FFMPEG_BINARY,
        '-nostdin',
        '-y',
        '-timelimit', (string) (int) GalleryConfig::TRANSCODE_TIMEOUT,
        '-i', $sourcePath,
        // Take one video and, if there is one, one audio stream. Phone
        // recordings often carry timecode or metadata streams that the mp4
        // muxer refuses outright.
        '-map', '0:v:0',
        '-map', '0:a:0?',
        '-vf', $scale,
        '-c:v', 'libx264',
        '-preset', GalleryConfig::VIDEO_PRESET,
        '-crf', (string) (int) GalleryConfig::VIDEO_CRF,
        // Baseline compatibility: without this, footage in a 10-bit or 4:2:2
        // pixel format produces an mp4 that Safari will not touch.
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac',
        '-b:a', GalleryConfig::VIDEO_AUDIO_BITRATE,
        '-ac', '2',
        // Puts the index at the front so playback can start before the whole
        // file has arrived.
        '-movflags', '+faststart',
        '-threads', (string) (int) GalleryConfig::VIDEO_ENCODE_THREADS,
        $targetPath,
    ];

    // Process rather than exec(): an argument array cannot be
    // shell-interpreted at all, and this stays working if the CLI ini is ever
    // hardened the way the FPM one already is.
    $process = new Process($args);
    $process->setTimeout(GalleryConfig::TRANSCODE_TIMEOUT + 60);
    $process->run();

    if (!$process->isSuccessful()) {
        // ffmpeg's diagnosis is in the last few lines; the rest is progress.
        $lines = preg_split('/\r\n|\r|\n/', trim($process->getErrorOutput() ?: $process->getOutput()));
        logLine("ffmpeg output: " . implode(' | ', array_slice($lines, -5)));
    }

    return (int) $process->getExitCode();
}

/**
 * Read codec and dimensions, so we can tell an already-web-ready mp4 from one
 * that only looks like one. Returns nulls if ffprobe is unavailable.
 *
 * Both streams are read, not just the video one: alreadyWebReady() has to know
 * whether the audio is aac before it can decide the encode has nothing to do.
 * 'audio' is null both when there is no audio track and when ffprobe could not
 * be run at all — the two are told apart by 'probed'.
 */
function probe($path) {
    $blank = ['codec' => null, 'width' => 0, 'height' => 0, 'audio' => null, 'probed' => false];

    if (!is_executable(GalleryConfig::FFPROBE_BINARY)) {
        return $blank;
    }

    $process = new Process([
        GalleryConfig::FFPROBE_BINARY,
        '-v', 'quiet',
        '-print_format', 'json',
        '-show_streams',
        $path,
    ]);
    $process->setTimeout(60);
    $process->run();

    if (!$process->isSuccessful()) {
        return $blank;
    }

    $data = json_decode($process->getOutput(), true);
    if (!isset($data['streams']) || !is_array($data['streams'])) {
        return $blank;
    }

    $result = $blank;
    $result['probed'] = true;

    foreach ($data['streams'] as $stream) {
        $type = isset($stream['codec_type']) ? $stream['codec_type'] : '';

        if ($type === 'video' && $result['codec'] === null) {
            $result['codec']  = isset($stream['codec_name']) ? $stream['codec_name'] : null;
            $result['width']  = isset($stream['width']) ? (int) $stream['width'] : 0;
            $result['height'] = isset($stream['height']) ? (int) $stream['height'] : 0;
            continue;
        }

        if ($type === 'audio' && $result['audio'] === null) {
            $result['audio'] = isset($stream['codec_name']) ? $stream['codec_name'] : null;
        }
    }

    return $result;
}

/**
 * Is this file already exactly what the encode would produce?
 *
 * The old shape of this check ran the encode first and compared sizes
 * afterwards, which cannot terminate: h264 at CRF 23 re-encoded is a little
 * smaller than h264 at CRF 23, so the result was swapped in, and the file came
 * back around the queue a slightly worse and slightly smaller video every time.
 * A file that has already been through here is now recognised before ffmpeg is
 * started, so a job that arrives twice costs a probe rather than a generation
 * of quality.
 *
 * Deliberately conservative: anything it is not sure about — no ffprobe, an
 * unreadable container, a codec it does not recognise — is encoded as before.
 */
function alreadyWebReady($filename, $probe) {
    if (!$probe['probed']) {
        return false; // Could not look; do the work rather than guess.
    }

    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'mp4') {
        return false;
    }

    if ($probe['codec'] !== 'h264') {
        return false;
    }

    // No audio at all is fine; anything that is not aac still needs a pass.
    if ($probe['audio'] !== null && $probe['audio'] !== 'aac') {
        return false;
    }

    $longEdge = max($probe['width'], $probe['height']);
    if ($longEdge <= 0 || $longEdge > (int) GalleryConfig::VIDEO_MAX_WIDTH) {
        return false;
    }

    return true;
}

function formatSize($bytes) {
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024 * 1024), 1) . 'GB';
    }
    return round($bytes / (1024 * 1024), 1) . 'MB';
}

function logLine($message) {
    error_log('[gallery-transcode] ' . $message);
}

/**
 * A transcode that could not be completed.
 *
 * Distinct from a programming error so the drain loop can log it, drop the
 * job and carry on, rather than treating it as a reason to stop.
 */
class TranscodeFailure extends Exception {
}

/**
 * Abandon this transcode.
 *
 * Throws rather than exits. Every fail() sits inside transcode(), which the
 * drain service calls in-process — exit(1) there would have taken the whole
 * service down, and systemd would have restarted it straight back onto the
 * same unencodable video. A crash loop, one file wide.
 *
 * Callers that clear the .processing marker before failing still should; this
 * only changes how control leaves the function.
 */
function fail($message) {
    throw new TranscodeFailure($message);
}

/**
 * Work the transcode queue until killed.
 *
 * One resident process replaces the process-per-video model. The .processing
 * markers are the queue, so this also picks up anything stranded by a reboot
 * or a deploy — work that previously died with the waiting worker that held
 * it and was never retried.
 *
 * Deliberately dumb: poll, take the oldest unclaimed job, encode it, repeat.
 * There is no in-memory state to lose, so being killed mid-encode costs one
 * video's progress and nothing else — the marker is still on disk and the
 * next pass picks it up.
 */
function drain() {
    logLine('Drain service starting');

    // Lets the slot wait inside transcode() keep this service's heartbeat
    // alive; see GalleryManager::$isDrainService.
    GalleryManager::$isDrainService = true;

    // Announce before the first sleep. queueTranscode() checks this heartbeat
    // to decide whether it must spawn its own worker, and a slow first pass
    // should not make early uploads fall back unnecessarily.
    GalleryManager::drainerHeartbeat();

    $running = true;

    // Jobs this process has already finished, as "year/filename" => when.
    //
    // Belt and braces for the loop above: if a marker for a file we just
    // completed is still (or once again) in the queue, encoding it a second
    // time cannot produce anything the first pass did not. Filenames are
    // unique per upload — claimFilename() sees to that — so a repeat inside
    // this window is a marker that outlived its job, not a new video.
    $completed = [];
    $repeatGuard = 900; // seconds

    // Let systemd stop us between jobs rather than mid-encode where possible.
    // A half-written mp4 is discarded by the next pass either way, but a clean
    // exit avoids re-doing work.
    if (function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        $stop = function ($signal) use (&$running) {
            logLine("Signal {$signal} received; finishing the current job and stopping");
            $running = false;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    while ($running) {
        GalleryManager::drainerHeartbeat();

        $jobs = GalleryManager::pendingTranscodes();

        // Keep every queued marker warm. isProcessing() collects a marker that
        // is still unclaimed after TRANSCODE_START_GRACE, on the assumption
        // that its spawn failed — true in the old model, but here a job can sit
        // legitimately queued for far longer than that behind other encodes.
        foreach ($jobs as $job) {
            GalleryManager::touchProcessing($job['year'], $job['filename']);
        }

        $worked = false;

        foreach ($jobs as $job) {
            if (!$running) {
                break;
            }

            $key = $job['year'] . '/' . $job['filename'];

            if (isset($completed[$key]) && (time() - $completed[$key]) < $repeatGuard) {
                GalleryManager::clearProcessing($job['year'], $job['filename']);
                logLine("Ignoring {$job['filename']}: finished it "
                    . (time() - $completed[$key]) . "s ago and its marker is still queued");
                continue;
            }

            $claim = GalleryManager::claimTranscode($job['year'], $job['filename']);
            if ($claim === null) {
                continue; // Someone else has it — a one-shot fallback worker, say.
            }

            $worked = true;
            logLine("Picked up {$job['filename']} ({$job['year']})");

            try {
                $code = transcode($job['year'], $job['filename']);
                logLine("Finished {$job['filename']} with status {$code}");
            } catch (Throwable $e) {
                // One bad video must never take the service down; systemd
                // would restart it straight into the same job.
                GalleryManager::clearProcessing($job['year'], $job['filename']);
                logLine("Gave up on {$job['filename']}: " . $e->getMessage());
            } finally {
                // Either way the video is still on disk and still needs a
                // tile: a successful encode replaced the file, and a failed
                // one kept the original. Cheap when the poster already exists.
                ensurePoster($job['year'], $job['filename']);
                GalleryManager::releaseLock($claim);

                $completed[$key] = time();

                // Nothing prunes this otherwise, and the service is resident.
                foreach ($completed as $done => $when) {
                    if (time() - $when >= $repeatGuard) {
                        unset($completed[$done]);
                    }
                }
            }

            // Re-read the queue rather than trusting this snapshot: an encode
            // takes minutes and more uploads will have landed since.
            break;
        }

        if (!$worked && $running) {
            sleep(GalleryConfig::DRAINER_POLL_INTERVAL);
        }
    }

    logLine('Drain service stopped');
    return 0;
}

/**
 * Make sure a video has a poster frame, whatever happened to its encode.
 *
 * Three ways a tile ends up blank, all of which land here: an encode that
 * failed and kept the original, a job stranded by a reboot before the queue
 * existed, and an upload whose worker never started. storeUpload() only
 * generates a poster inline when queueing outright fails, so nothing else
 * covers these.
 *
 * The filename may have changed underneath us — a .mov becomes a .mp4 on a
 * successful encode — so the surviving file is looked up by base name.
 */
function ensurePoster($year, $filename) {
    try {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $yearPath = GalleryConfig::getYearPath($year);
        $poster = $yearPath . DIRECTORY_SEPARATOR . 'thumbs'
            . DIRECTORY_SEPARATOR . $base . '.jpg';

        if (file_exists($poster)) {
            return;
        }

        foreach (GalleryConfig::ALLOWED_VIDEO_TYPES as $ext) {
            $candidate = $base . '.' . $ext;
            if (!file_exists($yearPath . DIRECTORY_SEPARATOR . $candidate)) {
                continue;
            }

            // generateVideoPoster() reports failure by returning null and
            // logging, so check the result rather than announcing a poster
            // that was never written.
            GalleryManager::generateVideoPoster($year, $candidate);

            if (file_exists($poster)) {
                logLine("Backfilled poster for {$candidate}");
            } else {
                logLine("No poster could be made for {$candidate}");
            }
            return;
        }
    } catch (Throwable $e) {
        // A missing poster is a cosmetic problem; never fail a job over it.
        logLine("Could not backfill poster for {$filename}: " . $e->getMessage());
    }
}

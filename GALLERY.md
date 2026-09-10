# Gallery System Setup

## Overview
The gallery system provides a secure, member-only photo and video sharing platform integrated with Flarum authentication. Files are organized by Burning Man year, and only authenticated users can view or upload content.

## Directory Structure
```
includes/gallery/         # Backend files (outside public)
├── auth.php              # Authentication helper
├── config.php            # Configuration settings
├── chunked-upload.php    # Staging and reassembly of sliced uploads
├── manager.php           # File management operations
└── transcode.php         # CLI worker: re-encodes uploaded video

public/gallery/           # Public-facing files
├── index.php             # Main gallery page
├── api.php               # AJAX API endpoints
├── serve.php             # Secure file serving
├── gallery.css           # Gallery-specific styles
└── gallery.js            # Frontend JavaScript

gallery-storage/     # File storage (outside public, mounted)
├── 2025/                 # Year-based directories
│   ├── thumbs/           # Thumbnails and video posters
│   └── .processing/      # Marker per video awaiting transcode
├── 2024/
├── .uploads/             # Chunks of uploads still in flight
└── ...
```

## Security Features
- **Authentication Required**: All gallery access requires active Flarum session
- **File Validation**: Strict file type and size validation (50MB photos / 500MB video, specific formats; MIME re-checked against the extension after reassembly)
- **Upload Sessions**: Chunks can only be added to, finalized or aborted by the session that opened the upload
- **Secure Serving**: Files served through PHP with auth checks
- **User Ownership**: Users can only delete their own uploads
- **Rate Limiting**: Upload protection via nginx
- **Path Security**: Prevents directory traversal attacks
- **Backend Protection**: Gallery includes stored in `/includes/gallery/` (outside public)
- **Storage Protection**: Gallery files stored in `/gallery-storage/` (blocked by nginx)

## File Management
- **Allowed Types**: JPG, PNG, WebP, GIF, MP4, WebM, MOV, AVI
- **Max Size**: 50MB per photo, 500MB per video
- **Naming**: `<ordering>_<userId>_<original name>.<ext>` — position, owner and original name all live in the filename
- **Thumbnails**: Automatic thumbnail generation for images
- **Storage**: Year-based organization (e.g., `/2025/`, `/2024/`)

## File Ordering

A file's position in its year is the part of its name before the first
underscore. It is a base-62 fractional index (`0-9A-Za-z`, ASCII-ordered, so
`strcmp` sorts a year without decoding anything), where the first character of
the integer part encodes sign and length: `a0 a1 … az b00 b01 …` going forward,
`Zz Zy … Yzz` going back, and a fraction such as `a0V` between two neighbours.
Inserting anywhere is always possible and never renames another file.

- Uploads **append** — a batch keeps the order it was sent in. Rearranging is a
  drag in the gallery (`GalleryManager::reorderFile()`); the client sends the
  index the tile ended up at and the server derives the key from its new
  neighbours.
- The gallery **opens on oldest first**. The sort is a view preference, kept per
  browser in `localStorage` under `cws.gallery.sortMode`, and only camp order is
  the stored order — pressing Rearrange switches to it (without overwriting the
  saved preference).
- While dragging, the tile is moved through the grid live, so the layout on
  screen is what a drop saves; the numbered badges renumber with it.
- Keys stay short under appending: 62 files in two characters, 3844 in three.
- The algebra is `FractionalOrdering` (`includes/gallery/ordering.php`), a port
  of the scheme described in "Implementing Fractional Indexing".

### Tests

No PHPUnit in this project; the suites are plain scripts and exit non-zero on
failure:

```bash
php tests/ordering_test.php
```

## Chunked Uploads

Nothing is posted whole. `gallery.js` slices the file into 5MB chunks
(`GalleryConfig::CHUNK_SIZE`) and posts them one at a time, so no single
request is large. Two consequences worth knowing:

- **nginx's `client_max_body_size` and PHP's `post_max_size` no longer cap
  gallery file size.** They only have to clear one chunk. The real ceiling is
  `MAX_FILE_SIZE` / `MAX_VIDEO_SIZE` in `includes/gallery/config.php`.
- **A dropped connection costs one chunk, not the whole upload.** Each chunk
  is retried three times with backoff before the file is reported as failed.

Chunks land in `gallery-storage/.uploads/<uploadId>/`, are reassembled on
`upload_finalize`, and the staging directory is deleted either way. Uploads
abandoned mid-flight are collected after 24 hours (`UPLOAD_SESSION_TTL`) by
the next upload to come along — there is no cron on this box, so nothing else
would ever clear them.

## Video Transcoding

Every uploaded video is re-encoded to 1080p H.264 / AAC mp4 with
`+faststart`, replacing the original. This is partly about size, but mostly
about playback: `.avi` and iPhone HEVC `.mov` are both accepted formats that
browsers will not play as uploaded.

Encoding takes minutes, so it does not happen in the request. `storeUpload()`
writes a marker into `<year>/.processing/` and spawns
`includes/gallery/transcode.php` detached via `nohup`; the API returns
immediately with `processing: true`. The `files` endpoint reports that flag,
and the gallery shows those tiles as placeholders — not openable, draggable or
deletable, since the file is about to be replaced — polling every 5s until the
marker clears.

Requirements and failure modes:

- `ffmpeg`/`ffprobe` at the paths in `config.php`, and a **PHP CLI binary at
  `GalleryConfig::PHP_CLI_BINARY`** (`/usr/bin/php` by default — check this on
  the deploy host).
- `exec()` must not be in `disable_functions`.
- If any of that is missing the upload still succeeds: the video is filed
  as-is, a poster is generated inline, and the reason is logged.
- A worker killed mid-encode leaves its marker behind; `isProcessing()` treats
  any marker older than `TRANSCODE_TIMEOUT + 5min` as dead and clears it.
- An upload that is already h264 mp4 and only got bigger when re-encoded keeps
  its original.

To reprocess something by hand:

```bash
php includes/gallery/transcode.php 2026 n_12_sunset.mov
```

## Upload Permissions
- Users can only upload to the current year
- Past years are read-only
- Files are stored with user ID in filename for ownership tracking

## Installation

### 1. File Permissions
```bash
# Set proper permissions for storage
chmod 755 gallery-storage/
chmod 755 gallery-storage/*/
chmod 644 gallery-storage/*/*.{jpg,png,webp,gif,mp4,webm,mov,avi}

# Ensure web server can write to storage
chown -R www-data:www-data gallery-storage/

# Protect includes directory
chmod 755 includes/gallery/
chmod 644 includes/gallery/*.php
```

### 2. PHP Requirements
Ensure these PHP extensions are installed:
- `gd` or `imagick` (for thumbnail generation)
- `fileinfo` (for MIME type detection)
- `exif` (for image metadata)

Also required for video transcoding:
- `ffmpeg` and `ffprobe` (paths in `config.php`)
- A PHP **CLI** binary at `GalleryConfig::PHP_CLI_BINARY`
- `exec()` available (not in `disable_functions`)

`upload_max_filesize` and `post_max_size` only need to clear a single 8MB
chunk now, not a whole file.

### 3. Nginx Configuration
Include the provided `.nginx-gallery.conf` in your main nginx config:
```nginx
include /path/to/campweirdsteel/.nginx-gallery.conf;
```

### 4. Storage Mount (Production)
Mount the gallery storage directory during deployment

## API Endpoints

### GET /gallery/api.php?action=years
Returns available years
```json
{
  "years": [2025, 2024, 2023]
}
```

### GET /gallery/api.php?action=files&year=2025
Returns files for specified year
```json
{
  "files": [
    {
      "filename": "user123_1640995200_abc12345.jpg",
      "size": 2048576,
      "modified": 1640995200,
      "type": "image",
      "url": "serve.php?year=2025&file=...",
      "thumbnail": "serve.php?year=2025&file=thumbs/..."
    }
  ]
}
```

### POST /gallery/api.php (upload, three steps)
Uploads are chunked; every file goes this way, including small photos.

```
1. FormData: action="upload_init", year=2025, filename="clip.mov", size=184320000
             (admins may add uploadOnBehalf=<userId>)
   -> { uploadId, chunkSize, totalChunks }

2. FormData: action="upload_chunk", uploadId, index=0, chunk=[Blob]
   -> { received, total }          … repeated for every chunk

3. FormData: action="upload_finalize", uploadId
   -> { filename, processing, message, size, type, uploadedForUser }
```

`processing: true` means the file is a video queued for re-encoding and is not
yet playable. `action="upload_abort"` with an `uploadId` drops a partial
upload; the client sends it whenever a file fails part-way.

### POST /gallery/api.php (action=delete)
Soft delete a file (user's own files only)
```
FormData:
- action: "delete"
- year: 2025
- filename: "user123_1640995200_abc12345.jpg"
```

## File Serving
Files are served through `/gallery/serve.php` which:
1. Validates user authentication
2. Checks file exists and isn't deleted
3. Prevents path traversal
4. Sets appropriate headers
5. Supports video range requests
6. Caches files appropriately

## Error Handling
- 403: Authentication required or permission denied
- 404: File not found or deleted
- 400: Invalid request parameters
- 413: File too large
- 415: Unsupported file type

## Development Notes
- Thumbnail generation happens on-demand
- Videos show first frame as thumbnail placeholder
- Modal viewer supports both images and videos
- Responsive design matches main site theme
- All file operations are logged for debugging

## Maintenance
- Periodically clean up old deleted files from storage
- Monitor storage usage and implement quotas if needed
- Review upload logs for suspicious activity
- Backup gallery storage regularly

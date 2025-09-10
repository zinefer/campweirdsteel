# Gallery System Setup

## Overview
The gallery system provides a secure, member-only photo and video sharing platform integrated with Flarum authentication. Files are organized by Burning Man year, and only authenticated users can view or upload content.

## Directory Structure
```
includes/gallery/         # Backend files (outside public)
├── auth.php              # Authentication helper
├── config.php            # Configuration settings
└── manager.php           # File management operations

public/gallery/           # Public-facing files
├── index.php             # Main gallery page
├── api.php               # AJAX API endpoints
├── serve.php             # Secure file serving
├── gallery.css           # Gallery-specific styles
└── gallery.js            # Frontend JavaScript

gallery-storage/     # File storage (outside public, mounted)
├── 2025/                 # Year-based directories
├── 2024/
└── ...
```

## Security Features
- **Authentication Required**: All gallery access requires active Flarum session
- **File Validation**: Strict file type and size validation (50MB, specific formats)
- **Secure Serving**: Files served through PHP with auth checks
- **User Ownership**: Users can only delete their own uploads
- **Rate Limiting**: Upload protection via nginx
- **Path Security**: Prevents directory traversal attacks
- **Backend Protection**: Gallery includes stored in `/includes/gallery/` (outside public)
- **Storage Protection**: Gallery files stored in `/gallery-storage/` (blocked by nginx)

## File Management
- **Allowed Types**: JPG, PNG, WebP, GIF, MP4, WebM, MOV, AVI
- **Max Size**: 50MB per file
- **Naming**: Auto-generated safe filenames with user ID prefix
- **Thumbnails**: Automatic thumbnail generation for images
- **Storage**: Year-based organization (e.g., `/2025/`, `/2024/`)

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

### POST /gallery/api.php (action=upload)
Upload a file to the current year
```
FormData:
- action: "upload"
- year: 2025
- file: [File object]
```

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

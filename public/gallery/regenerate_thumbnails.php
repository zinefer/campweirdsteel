<?php
/**
 * Thumbnail Regeneration Utility
 * 
 * This script regenerates thumbnails for a specific year, applying EXIF orientation fixes.
 * Run this after updating the thumbnail generation code to fix orientation issues.
 * 
 * Usage:
 * - Via browser: /gallery/regenerate_thumbnails.php?year=2024&confirm=yes
 * - Via command line: php regenerate_thumbnails.php 2024
 */

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Set content type
if (php_sapi_name() === 'cli') {
    // Command line execution
    $isCli = true;
    $year = isset($argv[1]) ? (int)$argv[1] : 0;
    $confirm = isset($argv[2]) ? $argv[2] === 'confirm' : false;
} else {
    // Web execution - require authentication
    $isCli = false;
    header('Content-Type: text/html; charset=utf-8');
    
    require_once '../../includes/gallery/auth.php';
    
    // Require authentication
    $auth = new GalleryAuth();
    $auth->requireAuth();

    // Rebuilding every thumbnail for a year is expensive, and this bypasses
    // the API (which is admin-gated for the same action), so gate it here too.
    if (!$auth->isAdmin()) {
        http_response_code(403);
        echo '<p>Regenerating thumbnails is limited to admins.</p>';
        exit;
    }

    $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $confirm = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';
}

require_once '../../includes/gallery/manager.php';

function output($message, $isError = false) {
    global $isCli;
    
    if ($isCli) {
        echo $message . "\n";
        if ($isError) {
            fwrite(STDERR, "ERROR: " . $message . "\n");
        }
    } else {
        $class = $isError ? 'error' : 'info';
        echo "<div class='message {$class}'>" . htmlspecialchars($message) . "</div>\n";
    }
}

function showUsage() {
    global $isCli;
    
    if ($isCli) {
        echo "Usage: php regenerate_thumbnails.php <year> [confirm]\n";
        echo "Example: php regenerate_thumbnails.php 2024 confirm\n";
    } else {
        echo "<h1>Thumbnail Regeneration Utility</h1>";
        echo "<p>This tool regenerates thumbnails for a specific year, applying EXIF orientation fixes.</p>";
        echo "<p>Usage: <code>/gallery/regenerate_thumbnails.php?year=2024&confirm=yes</code></p>";
        
        // Show available years
        try {
            $years = GalleryConfig::getAvailableYears();
            if (!empty($years)) {
                echo "<h2>Available Years:</h2>";
                echo "<ul>";
                foreach ($years as $availableYear) {
                    $url = "regenerate_thumbnails.php?year={$availableYear}&confirm=yes";
                    echo "<li><a href=\"{$url}\">{$availableYear}</a></li>";
                }
                echo "</ul>";
            }
        } catch (Exception $e) {
            output("Error getting available years: " . $e->getMessage(), true);
        }
    }
}

// Add basic CSS for web interface
if (!$isCli) {
    echo "<style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .message.info { background: #e7f3ff; border: 1px solid #b3d9ff; color: #004085; }
        .message.error { background: #ffe6e6; border: 1px solid #ffb3b3; color: #721c24; }
        .message.success { background: #e6ffed; border: 1px solid #b3f0c2; color: #155724; }
        code { background: #f8f9fa; padding: 2px 4px; border-radius: 3px; font-family: monospace; }
        ul { list-style-type: none; padding: 0; }
        li { margin: 5px 0; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>";
}

// Validate year
if (!$year || $year < 2000 || $year > 3000) {
    output("Invalid year specified. Please provide a valid year (2000-3000).", true);
    showUsage();
    exit(1);
}

// Check if year exists
try {
    $availableYears = GalleryConfig::getAvailableYears();
    if (!in_array($year, $availableYears)) {
        output("Year {$year} not found in gallery. Available years: " . implode(', ', $availableYears), true);
        exit(1);
    }
} catch (Exception $e) {
    output("Error checking available years: " . $e->getMessage(), true);
    exit(1);
}

// Require confirmation
if (!$confirm) {
    output("This will regenerate ALL thumbnails for year {$year}.", false);
    output("This process will delete existing thumbnails and create new ones with proper EXIF orientation handling.", false);
    
    if ($isCli) {
        output("To proceed, run: php regenerate_thumbnails.php {$year} confirm", false);
    } else {
        $confirmUrl = "regenerate_thumbnails.php?year={$year}&confirm=yes";
        echo "<p><a href=\"{$confirmUrl}\" style=\"background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;\">Confirm Regeneration for {$year}</a></p>";
    }
    exit(0);
}

// Perform the regeneration
output("Starting thumbnail regeneration for year {$year}...", false);

try {
    $results = GalleryManager::regenerateThumbnails($year);
    
    output("Thumbnail regeneration completed!", false);
    output("Processed: {$results['processed']} images", false);
    output("Succeeded: {$results['succeeded']} thumbnails", false);
    output("Failed: {$results['failed']} thumbnails", false);
    
    if (!empty($results['errors'])) {
        output("Errors encountered:", true);
        foreach ($results['errors'] as $error) {
            output("  - " . $error, true);
        }
    }
    
    if ($results['succeeded'] > 0) {
        if (!$isCli) {
            echo "<div class='message success'>Successfully regenerated {$results['succeeded']} thumbnails with proper orientation handling!</div>";
        } else {
            output("SUCCESS: Regenerated {$results['succeeded']} thumbnails with proper orientation handling!");
        }
    }
    
} catch (Exception $e) {
    output("Error during thumbnail regeneration: " . $e->getMessage(), true);
    exit(1);
}

if (!$isCli) {
    echo "<p><a href=\"index.php\">← Back to Gallery</a></p>";
}
?>

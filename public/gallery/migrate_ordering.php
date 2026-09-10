<?php
/**
 * Ordering Migration Utility
 *
 * Re-keys a year's files onto FractionalOrdering. The write paths do this by
 * themselves the first time anyone uploads to or rearranges a year — this is
 * for doing it deliberately, and for looking at the plan first.
 *
 * Usage:
 * - Via browser: /gallery/migrate_ordering.php?year=2026 (dry run)
 *                /gallery/migrate_ordering.php?year=2026&confirm=yes
 * - Via command line: php migrate_ordering.php 2026
 *                     php migrate_ordering.php 2026 confirm
 *                     php migrate_ordering.php all confirm
 *                     php migrate_ordering.php auto     <- deploy step
 *
 * `auto` is the unattended form: it checks every year, converts only the ones
 * holding invalid keys, and prints nothing but a single line when there is
 * nothing to do. It is what the deploy playbook runs.
 */

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (php_sapi_name() === 'cli') {
    $isCli = true;
    $yearArg = isset($argv[1]) ? $argv[1] : '';
    $confirm = isset($argv[2]) && $argv[2] === 'confirm';
} else {
    $isCli = false;
    header('Content-Type: text/html; charset=utf-8');

    require_once __DIR__ . '/../../includes/gallery/auth.php';

    $auth = new GalleryAuth();
    $auth->requireAuth();

    // Renaming every file in a year is an archive-wide operation, and the
    // reordering it is part of is admin-gated in the API. Same bar here.
    if (!$auth->isAdmin()) {
        http_response_code(403);
        echo '<p>Migrating the gallery ordering is limited to admins.</p>';
        exit;
    }

    $yearArg = isset($_GET['year']) ? $_GET['year'] : '';
    $confirm = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';
}

// __DIR__, not a bare relative path: PHP resolves a relative include against
// include_path — which begins with the *current working directory* — before it
// falls back to the script's own directory, and the deployed app root holds
// Flarum's config.php. See the same note at the top of manager.php.
require_once __DIR__ . '/../../includes/gallery/config.php';
require_once __DIR__ . '/../../includes/gallery/ordering-migration.php';

function output($message, $isError = false) {
    global $isCli;

    if ($isCli) {
        if ($isError) {
            fwrite(STDERR, "ERROR: " . $message . "\n");
        } else {
            echo $message . "\n";
        }
    } else {
        $class = $isError ? 'error' : 'info';
        echo "<div class='message {$class}'>" . htmlspecialchars($message) . "</div>\n";
    }
}

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

try {
    $availableYears = GalleryConfig::getAvailableYears();
} catch (Exception $e) {
    output('Error listing years: ' . $e->getMessage(), true);
    exit(1);
}

if ($yearArg === '') {
    if ($isCli) {
        output("Usage: php migrate_ordering.php <year|all|auto> [confirm]");
        output("Without 'confirm' this only prints what it would rename.");
        output("");
    } else {
        echo "<h1>Gallery Ordering Migration</h1>";
        echo "<p>Re-keys a year onto the current ordering scheme. Renames files; changes no content and no order.</p>";
    }

    foreach ($availableYears as $availableYear) {
        try {
            $state = OrderingMigration::isMigrated($availableYear)
                ? 'already migrated'
                : count(OrderingMigration::plan($availableYear)) . ' file(s) to re-key';
        } catch (Exception $e) {
            $state = 'error: ' . $e->getMessage();
        }

        if ($isCli) {
            output("  {$availableYear} — {$state}");
        } else {
            $url = "migrate_ordering.php?year={$availableYear}";
            echo "<li><a href=\"{$url}\">{$availableYear}</a> — " . htmlspecialchars($state) . "</li>";
        }
    }
    exit(0);
}

if ($yearArg === 'auto') {
    try {
        $renamed = OrderingMigration::ensureArchiveMigrated(function ($line) {
            output($line);
        });
    } catch (Exception $e) {
        output('Error migrating the archive: ' . $e->getMessage(), true);
        exit(1);
    }

    output($renamed > 0
        ? "Migrated {$renamed} file(s) onto the current ordering scheme."
        : 'Nothing to do.');
    exit(0);
}

$years = ($yearArg === 'all')
    ? $availableYears
    : [(int) $yearArg];

foreach ($years as $year) {
    if ($year < 2000 || $year > 3000) {
        output("Invalid year: {$year}", true);
        exit(1);
    }
    if (!in_array($year, $availableYears)) {
        output("Year {$year} not found. Available: " . implode(', ', $availableYears), true);
        exit(1);
    }
}

$total = 0;

foreach ($years as $year) {
    try {
        $total += OrderingMigration::runLocked($year, !$confirm, function ($line) {
            output($line);
        });
    } catch (Exception $e) {
        output("Error migrating {$year}: " . $e->getMessage(), true);
        exit(1);
    }
}

if (!$confirm && $total > 0) {
    output('');
    output('Nothing was renamed — this was a dry run.');
    if ($isCli) {
        output("To proceed: php migrate_ordering.php {$yearArg} confirm");
    } else {
        $url = "migrate_ordering.php?year=" . urlencode($yearArg) . "&confirm=yes";
        echo "<p><a href=\"{$url}\" style=\"background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;\">Run the migration</a></p>";
    }
}

if (!$isCli) {
    echo "<p><a href=\"index.php\">← Back to Gallery</a></p>";
}

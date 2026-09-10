<?php
/**
 * Tests for OrderingMigration.
 *
 *   php tests/ordering_migration_test.php
 *
 * Builds a throwaway year (2999) under the real storage root, migrates it and
 * checks the result, then removes it. It does not touch any other year.
 *
 * The property that matters is not "the keys are valid" — it is that the
 * gallery looks exactly the same afterwards. A migration that silently
 * reshuffles a year is worse than the bug it fixes, because nobody can tell
 * it happened.
 */

require_once __DIR__ . '/../includes/gallery/ordering.php';
require_once __DIR__ . '/../includes/gallery/config.php';
require_once __DIR__ . '/../includes/gallery/ordering-migration.php';

const TEST_YEAR = 2999;
const SWEEP_YEAR = 2998;

// The storage root exists on a deployed box but not in a fresh checkout.
$createdStorageRoot = false;
$storageRoot = __DIR__ . '/../gallery-storage';
if (!is_dir($storageRoot)) {
    mkdir($storageRoot, 0755, true);
    $createdStorageRoot = true;
}

$tests = 0;
$failures = [];

function check($condition, $message) {
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function yearPath($year = TEST_YEAR) {
    return GalleryConfig::getYearPath($year);
}

/** Lay down a year containing exactly $filenames, plus a thumbnail for each. */
function seedYear(array $filenames, $year = TEST_YEAR) {
    resetYear($year);
    $path = yearPath($year);
    $thumbs = $path . DIRECTORY_SEPARATOR . 'thumbs';
    @mkdir($thumbs, 0755, true);

    foreach ($filenames as $filename) {
        file_put_contents($path . DIRECTORY_SEPARATOR . $filename, 'original:' . $filename);
        $thumbName = GalleryConfig::isImage($filename)
            ? $filename
            : pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        file_put_contents($thumbs . DIRECTORY_SEPARATOR . $thumbName, 'thumb:' . $filename);
    }
}

function resetYear($year = TEST_YEAR) {
    $path = GalleryConfig::getBasePath() . DIRECTORY_SEPARATOR . $year;
    if (!is_dir($path)) {
        return;
    }
    foreach (['thumbs', ''] as $sub) {
        $dir = $sub === '' ? $path : $path . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..' || is_dir($dir . DIRECTORY_SEPARATOR . $item)) {
                continue;
            }
            unlink($dir . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path . DIRECTORY_SEPARATOR . 'thumbs');
    @rmdir($path);
}

/** The original names of a year, in the order the gallery would show them. */
function displayedNames($year = TEST_YEAR) {
    return array_map(
        ['GalleryConfig', 'extractOriginalName'],
        GalleryConfig::getOrderedFiles($year)
    );
}

// The exact shape the old scheme produced: a batch picked newest-first walked
// the alphabet down to 'a' and then jammed, so everything after that is 'Z'.
$legacy = [];
foreach (str_split('nmlkjihgfedcba') as $key) {
    $legacy[] = $key . '_1_IMG_' . (1000 + count($legacy)) . '.jpg';
}
for ($i = 0; $i < 6; $i++) {
    $legacy[] = 'Z_1_IMG_' . (2000 + $i) . ($i % 3 === 0 ? '.mp4' : '.jpg');
}

seedYear($legacy);

check(!OrderingMigration::isMigrated(TEST_YEAR), 'a legacy year should report as unmigrated');

$before = displayedNames();
check(count($before) === count($legacy), 'the seeded year should list every file');

$plan = OrderingMigration::plan(TEST_YEAR);
check(count($plan) === count($legacy), 'every legacy file should need a rename');

$renamed = OrderingMigration::apply(TEST_YEAR, false, function () {});
check($renamed === count($legacy), "apply should rename every file, renamed {$renamed}");

check(OrderingMigration::isMigrated(TEST_YEAR), 'the year should report as migrated afterwards');
check(displayedNames() === $before, 'migration must not change the displayed order');

$path = yearPath();
foreach (GalleryConfig::getOrderedFiles(TEST_YEAR) as $filename) {
    check(FractionalOrdering::isValidKey(GalleryConfig::extractOrdering($filename)),
        "{$filename} should carry a valid key");
    check(GalleryConfig::extractUserId($filename) === 1, "{$filename} should keep its owner");

    // The file has to still be the file, not just the right name.
    $contents = file_get_contents($path . DIRECTORY_SEPARATOR . $filename);
    check(strpos($contents, 'original:') === 0, "{$filename} should still hold its own content");

    $thumbName = GalleryConfig::isImage($filename)
        ? $filename
        : pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    $thumbPath = $path . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . $thumbName;
    check(file_exists($thumbPath), "the thumbnail for {$filename} should have moved with it");
    if (file_exists($thumbPath)) {
        check(strpos(file_get_contents($thumbPath), 'thumb:') === 0,
            "the thumbnail for {$filename} should be its own thumbnail");
    }
}

$thumbsDir = $path . DIRECTORY_SEPARATOR . 'thumbs';
$strays = array_values(array_diff(scandir($thumbsDir), ['.', '..']));
check(count($strays) === count($legacy), 'no thumbnail should be left behind under an old name');

// Running it again is a no-op, which is what makes it safe to leave on the
// upload path and safe to re-run after a crash.
check(OrderingMigration::plan(TEST_YEAR) === [], 'a migrated year should plan no renames');
check(OrderingMigration::ensureMigrated(TEST_YEAR) === 0, 'ensureMigrated should no-op when clean');

// Uploads keep working against the migrated year, and land at the end.
$appended = GalleryConfig::generateSafeFilename('IMG_9999.jpg', 7, TEST_YEAR);
file_put_contents($path . DIRECTORY_SEPARATOR . $appended, 'original:' . $appended);
$after = displayedNames();
check(end($after) === 'IMG_9999', 'a new upload should sort to the end of the year');
check(count($after) === count($before) + 1, 'a new upload should not displace anything');

// A target name occupied by a file that is itself still moving: the pass has
// to defer it rather than overwrite. Same owner and same original name, which
// is what makes two filenames collide in the first place (iOS calls every
// HEIC it transcodes image.jpg).
seedYear(['a1_1_image.jpg', 'n_1_image.jpg']);
$before = GalleryConfig::getOrderedFiles(TEST_YEAR);
OrderingMigration::apply(TEST_YEAR, false, function () {});
$files = GalleryConfig::getOrderedFiles(TEST_YEAR);
check(count($files) === 2, 'a deferred rename must not lose a file, got ' . count($files));
check(count(array_unique($files)) === 2, 'a deferred rename must not merge two files');
foreach ($files as $index => $filename) {
    $contents = file_get_contents($path . DIRECTORY_SEPARATOR . $filename);
    check($contents === 'original:' . $before[$index],
        "{$filename} should still be the file that was in that position");
}

// A dry run reports without touching anything.
seedYear(['n_1_a.jpg', 'm_1_b.jpg']);
$snapshot = GalleryConfig::getOrderedFiles(TEST_YEAR);
$lines = [];
$count = OrderingMigration::apply(TEST_YEAR, true, function ($line) use (&$lines) { $lines[] = $line; });
check($count === 2, 'a dry run should report the number of renames');
check(GalleryConfig::getOrderedFiles(TEST_YEAR) === $snapshot, 'a dry run must not rename anything');
check(count($lines) === 3, 'a dry run should log a header and one line per rename');

// ------------------------------------------------------- archive-wide sweep

// The sweep walks every year in the archive, so it only runs here when the
// archive holds nothing but this test's own years. On a deployed box the
// suite must not go migrating real galleries as a side effect.
$realYears = array_diff(GalleryConfig::getAvailableYears(), [TEST_YEAR, SWEEP_YEAR]);

if ($realYears) {
    echo "skipping the sweep tests: storage holds real years ("
        . implode(', ', $realYears) . ")\n";
} else {
    $stampPath = GalleryConfig::getBasePath() . DIRECTORY_SEPARATOR . OrderingMigration::SCHEME_STAMP;
    @unlink($stampPath);

    seedYear(['n_1_a.jpg', 'm_1_b.jpg']);
    seedYear(['Z_1_c.jpg', 'Z_1_d.jpg'], SWEEP_YEAR);

    $renamed = OrderingMigration::ensureArchiveMigrated();
    check($renamed === 4, "a sweep should convert every year that needs it, renamed {$renamed}");
    check(OrderingMigration::isMigrated(TEST_YEAR), 'the sweep should have converted the first year');
    check(OrderingMigration::isMigrated(SWEEP_YEAR), 'the sweep should have converted the second year');
    check(is_file($stampPath), 'a completed sweep should leave a scheme stamp');
    check((int) trim(file_get_contents($stampPath)) === OrderingMigration::SCHEME_VERSION,
        'the stamp should name the scheme version');

    check(OrderingMigration::ensureArchiveMigrated() === 0, 'a second sweep should do nothing');

    // A stamped archive is not walked again — that is the whole point of the
    // stamp — so a year that somehow reverts is caught by the write path
    // instead, not by the sweep.
    seedYear(['n_1_e.jpg', 'm_1_f.jpg'], SWEEP_YEAR);
    check(OrderingMigration::ensureArchiveMigrated() === 0, 'a stamped archive should not be re-walked');
    check(!OrderingMigration::isMigrated(SWEEP_YEAR), 'the reverted year should still look unmigrated');

    GalleryConfig::generateSafeFilename('IMG_0001.jpg', 3, SWEEP_YEAR);
    check(OrderingMigration::isMigrated(SWEEP_YEAR),
        'an upload should convert a year the sweep skipped');

    @unlink($stampPath);
    resetYear(SWEEP_YEAR);
}

resetYear();
$locksPath = GalleryConfig::getBasePath() . DIRECTORY_SEPARATOR . '.locks';
if (is_dir($locksPath)) {
    foreach (glob($locksPath . DIRECTORY_SEPARATOR . 'ordering-migration-*.lock') as $lock) {
        unlink($lock);
    }
    @rmdir($locksPath);
}
if ($createdStorageRoot) {
    @rmdir($storageRoot);
}

if ($failures) {
    echo "FAILED: " . count($failures) . " of {$tests} checks\n\n";
    foreach (array_slice($failures, 0, 20) as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "ok — {$tests} checks passed\n";

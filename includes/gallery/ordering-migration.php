<?php
/**
 * Re-keying a year onto FractionalOrdering.
 *
 * The gallery's first ordering scheme was a single letter, decremented to
 * insert ahead of the first file and incremented to append. It had no room
 * below 'A' or above 'z', and both ends saturated by handing out a key that
 * was already in use — which is how a year ends up with forty files all
 * called 'Z'. The keys it produced are not valid FractionalOrdering keys, so
 * a year has to be converted wholesale rather than one file at a time.
 *
 * Conversion is a rename per file. There is no database to migrate: position,
 * owner and original name all live in the filename, which is also why this
 * has to move thumbnails and posters in lockstep with their originals.
 *
 * ensureMigrated() runs from the write paths, so a year converts itself the
 * first time anyone uploads to it or drags something in it. migrate_ordering.php
 * does the same thing on demand, with a dry run to look at first.
 */

require_once __DIR__ . '/ordering.php';
require_once __DIR__ . '/config.php';

class OrderingMigration {
    /**
     * Bumped whenever the key format changes in a way that needs files
     * renamed. 1 was the single-letter scheme; 2 is FractionalOrdering.
     */
    const SCHEME_VERSION = 2;

    /**
     * Records, at the storage root, the scheme the archive has been swept to.
     * Dot-prefixed so it sits beside the year folders without being one:
     * getAvailableYears() only matches four digits.
     */
    const SCHEME_STAMP = '.ordering-scheme';

    /**
     * Whether every file in $year already carries a valid key.
     *
     * One scandir, no renames — cheap enough to sit in front of an upload.
     */
    public static function isMigrated($year) {
        foreach (self::listFiles($year) as $filename) {
            if (!FractionalOrdering::isValidKey(GalleryConfig::extractOrdering($filename))) {
                return false;
            }
        }
        return true;
    }

    /**
     * The renames that would convert $year, as [old, new] pairs.
     *
     * Files keep the order they are displayed in today. That matters more
     * than it sounds: the duplicate keys left by the old scheme tie under
     * strcmp, and their relative order is currently decided by whatever
     * scandir returned. Sorting by key and then by filename reproduces that
     * exactly on PHP 8 (whose sort is stable) and pins it down on older
     * versions, so nobody's gallery visibly reshuffles when this runs.
     */
    public static function plan($year) {
        $files = self::listFiles($year);
        if (empty($files)) {
            return [];
        }

        usort($files, function ($a, $b) {
            $byOrdering = strcmp(
                GalleryConfig::extractOrdering($a),
                GalleryConfig::extractOrdering($b)
            );
            return $byOrdering !== 0 ? $byOrdering : strcmp($a, $b);
        });

        $keys = FractionalOrdering::generateNKeysBetween(null, null, count($files));

        $plan = [];
        foreach ($files as $index => $filename) {
            $newFilename = self::withOrdering($filename, $keys[$index]);
            if ($newFilename !== $filename) {
                $plan[] = [$filename, $newFilename];
            }
        }

        return $plan;
    }

    /**
     * Bring the whole archive onto the current scheme. Returns the number of
     * files renamed.
     *
     * This is what makes a deploy self-sufficient: every year is checked, only
     * years actually holding invalid keys are touched, and a year that is
     * already current costs one directory listing. It is safe to call on every
     * request, and safe to call from a deploy step, and safe to call from both
     * at once.
     *
     * Steady state costs a single stat(), not a listing per year: a completed
     * sweep leaves a stamp at the storage root naming the scheme version it
     * brought the archive up to. Years created afterwards are written with
     * current keys from the start, so the stamp does not go stale on its own —
     * and if legacy names ever appear in a year again (a restore from an old
     * backup, say), ensureMigrated() on the write paths still catches that year
     * on its own.
     */
    public static function ensureArchiveMigrated(callable $log = null) {
        $basePath = GalleryConfig::getBasePath();
        if ($basePath === null) {
            // No storage yet — a box that has never taken an upload.
            return 0;
        }

        $stampPath = $basePath . DIRECTORY_SEPARATOR . self::SCHEME_STAMP;
        if (self::stampIsCurrent($stampPath)) {
            return 0;
        }

        return self::withLock('archive', function () use ($stampPath, $log) {
            // Another request may have swept while this one waited.
            if (self::stampIsCurrent($stampPath)) {
                return 0;
            }

            $renamed = 0;
            foreach (GalleryConfig::getAvailableYears() as $year) {
                // Per-year lock as well: a sweep must not rename a year out
                // from under an upload that is filing into it.
                $renamed += self::ensureMigrated($year, $log);
            }

            if (@file_put_contents($stampPath, self::SCHEME_VERSION . "\n") === false) {
                // Not fatal — every later request just re-checks the years,
                // which is a listing each and no renames.
                error_log('Gallery: could not write the ordering scheme stamp at ' . $stampPath);
            }

            return $renamed;
        });
    }

    /**
     * Whether a completed sweep has already brought the archive up to the
     * current scheme.
     */
    private static function stampIsCurrent($stampPath) {
        if (!is_file($stampPath)) {
            return false;
        }
        $stamp = @file_get_contents($stampPath);
        return $stamp !== false && (int) trim($stamp) >= self::SCHEME_VERSION;
    }

    /**
     * Convert $year if it needs it. Returns the number of files renamed.
     *
     * Holds an exclusive lock for the duration, so two uploads arriving at
     * once cannot both decide to migrate. The check is repeated under the
     * lock — by the time a waiter gets in, the work is usually already done.
     */
    public static function ensureMigrated($year, callable $log = null) {
        if (self::isMigrated($year)) {
            return 0;
        }

        return self::withLock('year-' . (int) $year, function () use ($year, $log) {
            if (self::isMigrated($year)) {
                return 0;
            }
            return self::apply($year, false, $log);
        });
    }

    /**
     * Convert $year on demand, under the same lock the write paths use.
     *
     * With $dryRun the plan is reported and nothing is touched. This is what
     * migrate_ordering.php calls; ordinary use never needs it, since
     * ensureMigrated() gets there first.
     */
    public static function runLocked($year, $dryRun = false, callable $log = null) {
        return self::withLock('year-' . (int) $year, function () use ($year, $dryRun, $log) {
            return self::apply($year, $dryRun, $log);
        });
    }

    /**
     * Run $work holding a migration lock — one per year, plus one for the
     * archive-wide sweep.
     *
     * The sweep takes the archive lock and then each year's in turn, and
     * nothing ever takes them the other way round, so the nesting cannot
     * deadlock.
     *
     * Blocking rather than LOCK_NB: an upload that arrives mid-migration
     * should wait for the renames to finish and then file itself against the
     * new keys, not fail.
     */
    private static function withLock($key, callable $work) {
        $lockPath = GalleryConfig::getLocksPath()
            . DIRECTORY_SEPARATOR . 'ordering-migration-'
            . preg_replace('/[^a-z0-9-]/i', '', (string) $key) . '.lock';

        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new Exception('Failed to open the ordering migration lock');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new Exception('Failed to lock the ordering migration');
            }
            return $work();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Perform (or, with $dryRun, describe) the renames for $year.
     *
     * Call under the lock. ensureMigrated() is the entry point that takes it;
     * the CLI takes it too.
     *
     * Nothing is ever renamed onto an occupied path. Targets are taken as
     * they come free, and if what is left is a cycle — file A wants B's name
     * while B wants A's, which a re-run over a partly converted year can
     * produce — one member is parked on a temporary name to break it. The
     * temporary keeps the extension, so its thumbnail keeps pace, and takes a
     * 'tmp'-prefixed ordering that is not a valid key: a crash mid-pass
     * leaves a file the next run simply re-keys along with everything else.
     */
    public static function apply($year, $dryRun = false, callable $log = null) {
        $log = $log ?: function () {};
        $yearPath = GalleryConfig::getYearPath($year);

        $pending = self::plan($year);
        if (empty($pending)) {
            $log("Year {$year} is already migrated.");
            return 0;
        }

        $log("Year {$year}: " . count($pending) . " file(s) to re-key.");

        if ($dryRun) {
            foreach ($pending as [$old, $new]) {
                $log("  {$old}  ->  {$new}");
            }
            return count($pending);
        }

        $renamed = 0;

        while (!empty($pending)) {
            $progressed = false;
            $blocked = [];

            foreach ($pending as $entry) {
                [$old, $new] = $entry;
                if (file_exists($yearPath . DIRECTORY_SEPARATOR . $new)) {
                    $blocked[] = $entry;
                    continue;
                }
                GalleryConfig::renameWithThumbnail($year, $old, $new);
                $log("  {$old}  ->  {$new}");
                $renamed++;
                $progressed = true;
            }

            if (!$progressed && !empty($blocked)) {
                // Everything left is waiting on something else in the batch.
                // Park one on a temporary name and go round again.
                [$old, $new] = array_shift($blocked);
                $temporary = self::withOrdering($old, 'tmp' . bin2hex(random_bytes(4)));
                GalleryConfig::renameWithThumbnail($year, $old, $temporary);
                $blocked[] = [$temporary, $new];
            }

            $pending = $blocked;
        }

        $log("Year {$year}: re-keyed {$renamed} file(s).");
        return $renamed;
    }

    /**
     * Files in $year that take part in ordering.
     *
     * Deliberately the same set as GalleryConfig::getOrderedFiles(): real
     * files, of an allowed type, not deleted. Deleted files keep the key they
     * were carrying when they were hidden — they are never sorted against
     * anything, and leaving them alone keeps this from touching data whose
     * only purpose is to be recoverable by hand.
     */
    private static function listFiles($year) {
        $yearPath = GalleryConfig::getYearPath($year);

        $files = [];
        foreach (scandir($yearPath) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (is_dir($yearPath . DIRECTORY_SEPARATOR . $item)) {
                continue;
            }
            if (strpos($item, GalleryConfig::DELETED_PREFIX) === 0) {
                continue;
            }
            if (!GalleryConfig::isAllowedFileType($item)) {
                continue;
            }
            $files[] = $item;
        }

        return $files;
    }

    /**
     * $filename with its ordering replaced, everything else preserved.
     */
    private static function withOrdering($filename, $ordering) {
        $ownerId = GalleryConfig::extractUserId($filename);
        $originalName = GalleryConfig::extractOriginalName($filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return "{$ordering}_{$ownerId}_{$originalName}.{$extension}";
    }
}

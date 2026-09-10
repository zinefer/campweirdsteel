<?php
/**
 * Tests for FractionalOrdering.
 *
 * Deliberately dependency-free — there is no PHPUnit in this project, and the
 * point of these is that they can be run on the box in front of you:
 *
 *   php tests/ordering_test.php
 *
 * Exits non-zero if anything fails.
 *
 * Key algebra is the kind of code that fails silently: a duplicate key does
 * not throw, it just quietly ties in the sort and — before claimFilename()
 * existed — let one upload overwrite another. So most of what is below is
 * property-based rather than example-based. Insert in every pattern that
 * matters and assert the invariant that actually has to hold: the keys are
 * unique and they sort into the order they were inserted in.
 */

require_once __DIR__ . '/../includes/gallery/ordering.php';
require_once __DIR__ . '/../includes/gallery/config.php';

$tests = 0;
$failures = [];

function check($condition, $message) {
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function checkThrows(callable $fn, $message) {
    global $tests, $failures;
    $tests++;
    try {
        $fn();
        $failures[] = $message . ' (expected an exception, none thrown)';
    } catch (Exception $e) {
        // expected
    }
}

/** Assert a list of keys is strictly ascending under strcmp and has no dupes. */
function checkOrdered(array $keys, $label) {
    check(count(array_unique($keys)) === count($keys), "{$label}: keys are not unique");
    $sorted = $keys;
    usort($sorted, 'strcmp');
    check($sorted === $keys, "{$label}: keys do not sort into insertion order");
    foreach ($keys as $key) {
        check(FractionalOrdering::isValidKey($key), "{$label}: invalid key {$key}");
    }
}

// ---------------------------------------------------------------- basics

check(FractionalOrdering::firstKey() === 'a0', 'first key should be a0');
check(FractionalOrdering::generateKeyBetween(null, null) === 'a0', 'empty sequence starts at a0');
check(FractionalOrdering::generateKeyBetween('a0', null) === 'a1', 'a0 appends to a1');
check(FractionalOrdering::generateKeyBetween(null, 'a0') === 'Zz', 'a0 prepends to Zz');
check(FractionalOrdering::generateKeyBetween('a0', 'a1') === 'a0V', 'a0/a1 subdivides to a0V');
check(FractionalOrdering::generateKeyBetween('a0V', 'a1') === 'a0l', 'a0V/a1 subdivides again');

checkThrows(function () { FractionalOrdering::generateKeyBetween('a1', 'a0'); },
    'reversed bounds should be rejected');
checkThrows(function () { FractionalOrdering::generateKeyBetween('a1', 'a1'); },
    'equal bounds should be rejected');
checkThrows(function () { FractionalOrdering::generateKeyBetween('n', null); },
    'a legacy single-letter key should be rejected');
checkThrows(function () { FractionalOrdering::generateKeyBetween('a00', null); },
    'a key ending in a zero digit should be rejected');

check(!FractionalOrdering::isValidKey('n'), 'legacy key n is not valid');
check(!FractionalOrdering::isValidKey('Z'), 'legacy key Z is not valid');
check(!FractionalOrdering::isValidKey('Lm'), 'legacy key Lm is not valid');
check(!FractionalOrdering::isValidKey(''), 'empty string is not a valid key');
check(FractionalOrdering::isValidKey('a0'), 'a0 is a valid key');

// ------------------------------------------------- appending (the common case)

$keys = [];
$previous = null;
for ($i = 0; $i < 2000; $i++) {
    $previous = FractionalOrdering::generateKeyBetween($previous, null);
    $keys[] = $previous;
}
checkOrdered($keys, 'append 2000');
// The payoff over the old scheme: appending does not grow the key. 62 files
// fit in two characters, 3844 in three.
check(strlen($keys[61]) === 2, 'the 62nd appended key should still be two characters');
check(strlen($keys[1999]) === 3, 'the 2000th appended key should be three characters');

// ------------------------------------------------------------- prepending

// This is the exact shape that broke: a batch picked newest-first, every file
// inserting ahead of the one before it. The old scheme spent a character per
// file and then jammed on a repeated 'Z'.
$keys = [];
$next = null;
for ($i = 0; $i < 2000; $i++) {
    $next = FractionalOrdering::generateKeyBetween(null, $next);
    array_unshift($keys, $next);
}
checkOrdered($keys, 'prepend 2000');

// ------------------------------------------------------- inserting in the middle

$sequence = FractionalOrdering::generateNKeysBetween(null, null, 10);
checkOrdered($sequence, 'initial batch of 10');

mt_srand(20260910); // fixed seed: a failure here has to be reproducible
for ($i = 0; $i < 3000; $i++) {
    $at = mt_rand(0, count($sequence));
    $before = $at > 0 ? $sequence[$at - 1] : null;
    $after = $at < count($sequence) ? $sequence[$at] : null;
    $key = FractionalOrdering::generateKeyBetween($before, $after);
    array_splice($sequence, $at, 0, [$key]);
}
checkOrdered($sequence, 'random insertion of 3000');

// Repeatedly splitting the *same* gap is the worst case for key length: each
// insert can only add precision, never reuse the space beside it.
$left = 'a0';
$right = 'a1';
$deep = [];
for ($i = 0; $i < 200; $i++) {
    $left = FractionalOrdering::generateKeyBetween($left, $right);
    $deep[] = $left;
}
check(count(array_unique($deep)) === 200, 'repeated splits of one gap stay unique');
check(strlen($left) < 60, 'repeated splits of one gap grow sub-linearly, got ' . strlen($left));

// ------------------------------------------------------------ batch generation

foreach ([1, 2, 5, 50, 500] as $n) {
    $batch = FractionalOrdering::generateNKeysBetween(null, null, $n);
    check(count($batch) === $n, "batch of {$n} should have {$n} keys");
    checkOrdered($batch, "batch of {$n} from empty");

    $bounded = FractionalOrdering::generateNKeysBetween('a0', 'a1', $n);
    checkOrdered($bounded, "batch of {$n} between a0 and a1");
    check(strcmp('a0', $bounded[0]) < 0 && strcmp(end($bounded), 'a1') < 0,
        "batch of {$n} should stay inside its bounds");

    $appended = FractionalOrdering::generateNKeysBetween('a0', null, $n);
    checkOrdered($appended, "batch of {$n} appended after a0");
    check(strcmp('a0', $appended[0]) < 0, "batch of {$n} should start after a0");

    $prepended = FractionalOrdering::generateNKeysBetween(null, 'a0', $n);
    checkOrdered($prepended, "batch of {$n} prepended before a0");
    check(strcmp(end($prepended), 'a0') < 0, "batch of {$n} should end before a0");
}

check(FractionalOrdering::generateNKeysBetween(null, null, 0) === [], 'a batch of 0 is empty');

// -------------------------------------------- keys have to survive the filename

// Ordering, owner and original name are packed into one filename and pulled
// back out by splitting on the first two underscores, so a key containing '_'
// (which the previous midpoint could produce — ord('_') sits in the ASCII gap
// between 'Z' and 'a') silently reassigns ownership of the file.
$sample = array_merge(
    FractionalOrdering::generateNKeysBetween(null, null, 200),
    $sequence,
    $deep
);
foreach ($sample as $key) {
    check(preg_match('/^[a-zA-Z0-9]+$/', $key) === 1, "key {$key} is not alphanumeric");
    $filename = $key . '_42_IMG_0001.jpg';
    check(GalleryConfig::extractOrdering($filename) === $key, "key {$key} does not round-trip");
    check(GalleryConfig::extractUserId($filename) === 42, "owner is lost for key {$key}");
    check(GalleryConfig::extractOriginalName($filename) === 'IMG_0001',
        "original name is lost for key {$key}");
    check(preg_match('/^[a-zA-Z0-9._-]+$/', $filename) === 1,
        "filename for key {$key} fails the API's validation");
}

// ------------------------------------------------------------------- report

if ($failures) {
    echo "FAILED: " . count($failures) . " of {$tests} checks\n\n";
    foreach (array_slice($failures, 0, 20) as $failure) {
        echo "  - {$failure}\n";
    }
    if (count($failures) > 20) {
        echo "  … and " . (count($failures) - 20) . " more\n";
    }
    exit(1);
}

echo "ok — {$tests} checks passed\n";

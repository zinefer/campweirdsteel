<?php
/**
 * Fractional index keys.
 *
 * Every gallery file carries its position in its own name, as the part before
 * the first underscore. Inserting between two neighbours has to produce a
 * string that sorts strictly between their two keys under strcmp(), for any
 * pair, without ever renaming anything else.
 *
 * The keys are base-62 over '0'-'9', 'A'-'Z', 'a'-'z' — an alphabet whose
 * order is also its ASCII order, so strcmp() is the comparator and no
 * decoding is needed to sort. They contain no '_' and no '.', which keeps
 * them clear of the filename parsing in GalleryConfig::extract*() and of the
 * [a-zA-Z0-9._-] validation the API and serve.php apply.
 *
 * A key is an integer part, optionally followed by a fraction:
 *
 *   a0    a1    a2 …    az    b00   b01 …          appending, two chars for
 *                                                  the first 62 files
 *   Zz    Zy …    Z0    Yzz   Yzy …                prepending
 *   a0V                                            between a0 and a1
 *
 * The first character of the integer part encodes both sign and length:
 * 'a'-'z' are positive with 1-26 digits following, 'Z'-'A' negative with the
 * same. That is what makes the sequence unbounded in *both* directions — the
 * magnitude prefix can always grow — where a plain decrement bottoms out at
 * the smallest character in the alphabet and starts handing out duplicates.
 *
 * The other rule doing real work: no key may end in the lowest digit ('0').
 * That leaves room below every key, so midpoint() can always subdivide.
 *
 * This is a PHP port of the algorithm described in David Greenspan's
 * "Implementing Fractional Indexing"; the structure is kept close to the
 * reference implementation deliberately, so the two can be read side by side.
 */

class FractionalOrdering {
    const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * The floor of the integer space: 'A' followed by 26 zeros. Never handed
     * out as a key — it is the bound that generateKeyBetween() subdivides
     * against once prepending has run all the way down.
     */
    const SMALLEST_INTEGER = 'A00000000000000000000000000';

    /**
     * The very first key in an empty sequence.
     */
    public static function firstKey() {
        return 'a' . self::DIGITS[0];
    }

    /**
     * Whether $key is a well-formed key.
     *
     * Used to tell keys in this scheme apart from the single-letter keys the
     * gallery used before it, which is what OrderingMigration keys off.
     */
    public static function isValidKey($key) {
        if (!is_string($key) || $key === '') {
            return false;
        }
        try {
            self::validateKey($key);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * A key strictly between $a and $b.
     *
     * Either bound may be null, meaning "nothing on that side": (null, $b)
     * prepends, ($a, null) appends, (null, null) is the first key of an empty
     * sequence.
     */
    public static function generateKeyBetween($a, $b) {
        if ($a !== null) {
            self::validateKey($a);
        }
        if ($b !== null) {
            self::validateKey($b);
        }
        if ($a !== null && $b !== null && strcmp($a, $b) >= 0) {
            throw new Exception("Ordering keys out of order: {$a} >= {$b}");
        }

        if ($a === null) {
            if ($b === null) {
                return self::firstKey();
            }

            $ib = self::integerPart($b);
            $fb = substr($b, strlen($ib));

            if ($ib === self::SMALLEST_INTEGER) {
                // Already at the floor, so the only room left is fractional.
                return $ib . self::midpoint('', $fb);
            }
            if (strcmp($ib, $b) < 0) {
                // $b has a fraction, so its own integer part sorts before it.
                return $ib;
            }

            $res = self::decrementInteger($ib);
            if ($res === null) {
                throw new Exception('Ordering space exhausted below ' . $b);
            }
            return $res;
        }

        if ($b === null) {
            $ia = self::integerPart($a);
            $fa = substr($a, strlen($ia));
            $i = self::incrementInteger($ia);
            return $i === null ? $ia . self::midpoint($fa, null) : $i;
        }

        $ia = self::integerPart($a);
        $fa = substr($a, strlen($ia));
        $ib = self::integerPart($b);
        $fb = substr($b, strlen($ib));

        if ($ia === $ib) {
            return $ia . self::midpoint($fa, $fb);
        }

        $i = self::incrementInteger($ia);
        if ($i === null) {
            throw new Exception('Ordering space exhausted above ' . $a);
        }
        if (strcmp($i, $b) < 0) {
            return $i;
        }
        return $ia . self::midpoint($fa, null);
    }

    /**
     * $n keys in ascending order, all strictly between $a and $b.
     *
     * Generating a batch this way rather than by calling generateKeyBetween()
     * in a loop keeps the keys short: a loop against a fixed bound subdivides
     * the same gap over and over, growing a character every time, while this
     * bisects and spreads the batch evenly across the space.
     */
    public static function generateNKeysBetween($a, $b, $n) {
        if ($n <= 0) {
            return [];
        }
        if ($n === 1) {
            return [self::generateKeyBetween($a, $b)];
        }

        if ($b === null) {
            $c = self::generateKeyBetween($a, null);
            $result = [$c];
            for ($i = 1; $i < $n; $i++) {
                $c = self::generateKeyBetween($c, null);
                $result[] = $c;
            }
            return $result;
        }

        if ($a === null) {
            $c = self::generateKeyBetween(null, $b);
            $result = [$c];
            for ($i = 1; $i < $n; $i++) {
                $c = self::generateKeyBetween(null, $c);
                $result[] = $c;
            }
            return array_reverse($result);
        }

        $mid = intdiv($n, 2);
        $c = self::generateKeyBetween($a, $b);
        return array_merge(
            self::generateNKeysBetween($a, $c, $mid),
            [$c],
            self::generateNKeysBetween($c, $b, $n - $mid - 1)
        );
    }

    /**
     * How many characters the integer part beginning with $head occupies,
     * the leading character included.
     */
    private static function integerLength($head) {
        if ($head >= 'a' && $head <= 'z') {
            return ord($head) - ord('a') + 2;
        }
        if ($head >= 'A' && $head <= 'Z') {
            return ord('Z') - ord($head) + 2;
        }
        throw new Exception('Invalid ordering key head: ' . $head);
    }

    private static function integerPart($key) {
        $length = self::integerLength($key[0]);
        if ($length > strlen($key)) {
            throw new Exception('Ordering key is shorter than its integer part: ' . $key);
        }
        return substr($key, 0, $length);
    }

    private static function validateInteger($int) {
        if (strlen($int) !== self::integerLength($int[0])) {
            throw new Exception('Invalid ordering integer: ' . $int);
        }
    }

    private static function validateKey($key) {
        if (!is_string($key) || $key === '') {
            throw new Exception('Ordering key is empty');
        }
        if ($key === self::SMALLEST_INTEGER) {
            throw new Exception('Ordering key is the reserved floor value');
        }
        if (strspn($key, self::DIGITS) !== strlen($key)) {
            throw new Exception('Ordering key has characters outside the alphabet: ' . $key);
        }

        $int = self::integerPart($key);
        $fraction = substr($key, strlen($int));
        // A trailing lowest digit would leave no room beneath the key, which
        // is the whole reason midpoint() can promise a result.
        if ($fraction !== '' && substr($fraction, -1) === self::DIGITS[0]) {
            throw new Exception('Ordering key ends in a zero digit: ' . $key);
        }
    }

    private static function incrementInteger($x) {
        self::validateInteger($x);

        $head = $x[0];
        $digits = str_split(substr($x, 1));
        $carry = true;

        for ($i = count($digits) - 1; $carry && $i >= 0; $i--) {
            $d = strpos(self::DIGITS, $digits[$i]) + 1;
            if ($d === strlen(self::DIGITS)) {
                $digits[$i] = self::DIGITS[0];
            } else {
                $digits[$i] = self::DIGITS[$d];
                $carry = false;
            }
        }

        if ($carry) {
            // Carried off the end, so the magnitude prefix moves up a step —
            // which is also where the integer part changes length.
            if ($head === 'Z') {
                return 'a' . self::DIGITS[0];
            }
            if ($head === 'z') {
                return null;
            }
            $h = chr(ord($head) + 1);
            if ($h > 'a') {
                $digits[] = self::DIGITS[0];
            } else {
                array_pop($digits);
            }
            return $h . implode('', $digits);
        }

        return $head . implode('', $digits);
    }

    private static function decrementInteger($x) {
        self::validateInteger($x);

        $head = $x[0];
        $digits = str_split(substr($x, 1));
        $borrow = true;

        for ($i = count($digits) - 1; $borrow && $i >= 0; $i--) {
            $d = strpos(self::DIGITS, $digits[$i]) - 1;
            if ($d === -1) {
                $digits[$i] = substr(self::DIGITS, -1);
            } else {
                $digits[$i] = self::DIGITS[$d];
                $borrow = false;
            }
        }

        if ($borrow) {
            if ($head === 'a') {
                return 'Z' . substr(self::DIGITS, -1);
            }
            if ($head === 'A') {
                return null;
            }
            $h = chr(ord($head) - 1);
            if ($h < 'Z') {
                $digits[] = substr(self::DIGITS, -1);
            } else {
                array_pop($digits);
            }
            return $h . implode('', $digits);
        }

        return $head . implode('', $digits);
    }

    /**
     * A fraction strictly between $a and $b, both of them fractions rather
     * than whole keys. $b may be null, meaning "no upper bound".
     *
     * Never returns something ending in the lowest digit, so the result can
     * itself be subdivided later.
     */
    private static function midpoint($a, $b) {
        if ($b !== null && strcmp($a, $b) >= 0) {
            throw new Exception("Fractions out of order: {$a} >= {$b}");
        }
        if (substr($a, -1) === self::DIGITS[0]
            || ($b !== null && substr($b, -1) === self::DIGITS[0])) {
            throw new Exception('Fraction ends in a zero digit');
        }

        if ($b !== null) {
            // Skip the shared prefix and subdivide only the part that differs.
            $n = 0;
            while (true) {
                $charA = substr($a, $n, 1);
                $charA = $charA === '' ? self::DIGITS[0] : $charA;
                $charB = substr($b, $n, 1);
                if ($charA !== $charB) {
                    break;
                }
                $n++;
            }
            if ($n > 0) {
                return substr($b, 0, $n) . self::midpoint(substr($a, $n), substr($b, $n));
            }
        }

        $digitA = $a !== '' ? strpos(self::DIGITS, $a[0]) : 0;
        $digitB = ($b !== null && $b !== '')
            ? strpos(self::DIGITS, $b[0])
            : strlen(self::DIGITS);

        if ($digitB - $digitA > 1) {
            $midDigit = (int) round(0.5 * ($digitA + $digitB));
            return self::DIGITS[$midDigit];
        }

        // The leading digits are adjacent, so there is no room at this
        // position — descend one place and try again.
        if ($b !== null && strlen($b) > 1) {
            return substr($b, 0, 1);
        }
        return self::DIGITS[$digitA] . self::midpoint(substr($a, 1), null);
    }
}

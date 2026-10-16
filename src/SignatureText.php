<?php

declare(strict_types=1);

namespace Chatly;

/**
 * Input handling for {@see ChatlySignatures} — trimming, parsing, header
 * and secret extraction, timestamp conversion — split out so the verifier
 * reads as the decision table and nothing else (and stays under the
 * repository's 650-line ceiling).
 *
 * Most of it exists because the obvious PHP built-in differs from the
 * reference implementation (JavaScript) in a way the corpus can see — see
 * `trim()` and `timestampToMs()` in particular.
 *
 * @internal Not part of the public surface; may change without notice.
 */
final class SignatureText
{
    /**
     * Trim the way JavaScript's `String.prototype.trim` does.
     *
     * PHP's `trim()` default set strips NUL (`\0`) and does NOT strip `\f` or
     * any Unicode space; JavaScript's is the reverse on all three counts. That
     * matters on a security boundary: a digest followed by a NUL byte is
     * malformed in the reference, and `trim()` would quietly make it valid
     * here. So: the ASCII set JavaScript uses (space, \t, \n, \v, \f, \r), plus
     * its Unicode whitespace when the value is valid UTF-8. On invalid UTF-8
     * the `u` pattern fails, and the ASCII trim alone stands — a value that is
     * not valid UTF-8 cannot verify anyway, since the digest must be hex.
     */
    public static function trim(string $value): string
    {
        $ws = '\t\n\x{0B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
        $trimmed = preg_replace('/^[' . $ws . ']+|[' . $ws . ']+$/uD', '', $value);

        return \is_string($trimmed) ? $trimmed : trim($value, " \t\n\x0B\f\r");
    }

    /**
     * Parse a `t=…,v1=…[,b=…]` value into `[t, list of v1, b|null]`, or null.
     *
     * Tolerates spaces and unknown fields — a future `v3` must not break a
     * `v1` verifier, which is the whole point of the prefix. Keeps the FIRST
     * `t` and the FIRST `b`, so a crafted second one appended on the wire
     * cannot move the window or the base, and collects EVERY `v1`, because a
     * sender mid-rotation signs with both secrets. A part whose `=` is missing
     * or first is skipped, as the reference's `indexOf('=') <= 0` does.
     *
     * @return array{0:string,1:list<string>,2:?string}|null
     */
    public static function parseTv1(string $raw): ?array
    {
        $t = null;
        $b = null;
        $v1 = [];
        foreach (explode(',', $raw) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $key = self::trim(substr($part, 0, $eq));
            $value = self::trim(substr($part, $eq + 1));
            if ($key === 't') {
                $t ??= $value;
            } elseif ($key === 'b') {
                $b ??= $value;
            } elseif ($key === 'v1') {
                $v1[] = $value;
            }
        }
        if ($t === null || $v1 === []) {
            return null;
        }

        return [$t, $v1, $b];
    }

    /**
     * Clip an untrusted header value so an error message stays one line —
     * and stays valid UTF-8 where the input was, by cutting on a character
     * boundary rather than at byte 47 in the middle of one, because a
     * message that breaks `json_encode` in somebody's logger is its own bug.
     */
    public static function preview(string $value): string
    {
        if (preg_match('/^.{49}/us', $value) === 1 && preg_match('/^.{47}/us', $value, $m) === 1) {
            return $m[0] . "\u{2026}";
        }
        if (\strlen($value) > 48 && preg_match('//u', $value) !== 1) {
            return substr($value, 0, 47) . "\u{2026}";
        }

        return $value;
    }

    /**
     * Parse a run of digits into milliseconds, or return the refusal message.
     *
     * The timestamp has to stay an INT. PHP's `(int)` saturates at
     * `PHP_INT_MAX` on overflow and plain arithmetic quietly turns into a
     * float, so "99999999999999999999" would otherwise become a number that
     * compares as some far-off instant — and `n * 1000` on a seconds surface
     * can overflow on a value `(int)` accepted. Both are refused as
     * malformed: no genuine Chatly timestamp is within a factor of a million
     * of either ceiling.
     */
    public static function timestampToMs(string $raw, string $unit): int|string
    {
        // `Number.parseInt` / `(int)` read "17e9" as 17 and "12abc" as 12. A
        // timestamp is digits. `D` so a trailing newline is not admitted.
        if (preg_match('/^\d+$/D', $raw) !== 1) {
            return "The timestamp is not a run of digits: \"" . self::preview($raw) . '".';
        }
        $digits = ltrim($raw, '0');
        $max = (string) \PHP_INT_MAX;
        if (\strlen($digits) > \strlen($max) || (\strlen($digits) === \strlen($max) && strcmp($digits, $max) > 0)) {
            return "The timestamp is too large to be a time: \"" . self::preview($raw) . '".';
        }
        $n = (int) $digits;
        if ($unit !== 'seconds') {
            return $n;
        }
        if ($n > intdiv(\PHP_INT_MAX, 1000)) {
            return "The timestamp is too large to be a time in seconds: \"" . self::preview($raw) . '".';
        }

        return $n * 1000;
    }

    /**
     * Every non-empty string secret, paired with its index in what the CALLER
     * passed. `['', $s]` verifying with `$s` reports 1, not 0 — the index
     * survives the filter, or a rotation dashboard would point at the wrong
     * secret. Anything that is not a string or a list is no secret at all.
     *
     * @return list<array{0:string,1:int}>
     */
    public static function secretsFrom(mixed $secret): array
    {
        $list = \is_string($secret) ? [$secret] : (\is_array($secret) ? array_values($secret) : []);
        $out = [];
        foreach ($list as $index => $value) {
            if (\is_string($value) && $value !== '') {
                $out[] = [$value, $index];
            }
        }

        return $out;
    }

    /**
     * Every value of `$name`, across EVERY key that matches it
     * case-insensitively — so `['x-a' => v, 'X-A' => w]` is two values, which
     * is what makes the duplicate check see a header smuggled in under a
     * second casing.
     *
     * A non-string value still COUNTS, as an empty string: dropping it would
     * let `[$genuine, 123]` slip past the duplicate check as one value.
     *
     * @return list<string>
     */
    public static function headerValues(mixed $headers, string $name): array
    {
        if (!\is_array($headers)) {
            return [];
        }
        $found = [];
        foreach ($headers as $key => $value) {
            // HTTP header names are ASCII; `strtolower` is locale-independent from
            // PHP 8.2, and on 8.1 only a non-C `setlocale()` could bend it.
            if (strtolower((string) $key) !== $name || $value === null) {
                continue;
            }
            foreach (\is_array($value) ? $value : [$value] as $one) {
                $found[] = \is_string($one) ? $one : '';
            }
        }

        return $found;
    }

    /**
     * A tolerance, rendered for a human, WITHOUT coercing NAN or INF.
     *
     * Two reasons, and the first is a bug this replaced. PHP 8.5 raises
     * `Warning: unexpected NAN value was coerced to string` on `. NAN`, and
     * `phpunit.xml.dist` sets `failOnWarning="true"` — so the NAN-tolerance
     * case, which is there to prove a NaN tolerance REFUSES rather than
     * disabling the freshness check, was itself failing the suite on a modern
     * interpreter. Only the message was ever affected; the refusal was always
     * correct. The mirror CI matrix is 8.1 and 8.3, where the warning does not
     * exist, which is how a red `vendor/bin/phpunit` stayed invisible.
     *
     * Second: the words match the reference. `String(NaN)` in JavaScript is
     * "NaN" and `String(Infinity)` is "Infinity", where PHP's casts give "NAN"
     * and "INF". An integrator comparing our SDKs' refusals should not have to
     * wonder whether two spellings mean two things.
     */
    public static function describeTolerance(int|float $toleranceSeconds): string
    {
        if (\is_float($toleranceSeconds)) {
            if (is_nan($toleranceSeconds)) {
                return 'NaN';
            }
            if (is_infinite($toleranceSeconds)) {
                return $toleranceSeconds > 0 ? 'Infinity' : '-Infinity';
            }
        }

        return (string) $toleranceSeconds;
    }
}

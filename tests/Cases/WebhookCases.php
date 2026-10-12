<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\Tests\Assert;
use Chatly\Webhooks;

/** CONTRACT §6 (webhook verification), §10.7 and §10.9. */
final class WebhookCases
{
    private const SECRET = 'whsec_test_secret';

    /** Build the header the platform sends: HMAC-SHA256 over `<t>.<body>`. */
    private static function sign(string $body, int $timestamp, string $secret = self::SECRET): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            '§10.7 a genuine t=…,v1= over `<t>.<body>` is accepted' => static function (): void {
                $body = '{"type":"conversation.created","id":"evt_7"}';
                $now = 1_767_225_600;
                Assert::true(
                    Webhooks::verifySignature($body, self::sign($body, $now), self::SECRET, now: $now),
                    'a correctly signed delivery must verify',
                );
            },

            '§10.7 a digest over the body ALONE is rejected' => static function (): void {
                /*
                 * An earlier Node implementation HMAC'd the body alone and so
                 * rejected 100% of genuine deliveries. This is the inverse
                 * guard: binding the timestamp into the digest is what makes
                 * the replay window enforceable, so a body-only digest must
                 * never pass.
                 */
                $body = '{"type":"conversation.created","id":"evt_7"}';
                $now = 1_767_225_600;
                $header = 't=' . $now . ',v1=' . hash_hmac('sha256', $body, self::SECRET);

                Assert::false(
                    Webhooks::verifySignature($body, $header, self::SECRET, now: $now),
                    'a digest that omits the timestamp must be refused',
                );
            },

            '§10.7 a stale timestamp is rejected' => static function (): void {
                $body = '{"id":"evt_1"}';
                $signedAt = 1_767_225_600;
                $header = self::sign($body, $signedAt);

                Assert::true(
                    Webhooks::verifySignature($body, $header, self::SECRET, 300, $signedAt + 299),
                    'inside the window it verifies',
                );
                Assert::false(
                    Webhooks::verifySignature($body, $header, self::SECRET, 300, $signedAt + 301),
                    'outside the window it does not',
                );
                Assert::false(
                    Webhooks::verifySignature($body, $header, self::SECRET, 300, $signedAt - 301),
                    'a timestamp from the future is just as stale',
                );
                Assert::true(
                    Webhooks::verifySignature($body, $header, self::SECRET, \INF, $signedAt + 10_000_000),
                    'INF tolerance admits an archived delivery',
                );
            },

            '§10.7 a malformed header is rejected and nothing is thrown' => static function (): void {
                $body = '{"id":"evt_1"}';
                $now = 1_700_000_000;
                $good = self::sign($body, $now);
                $digest = substr($good, strpos($good, 'v1=') + 3);

                $malformed = [
                    '' => 'an empty header',
                    'garbage' => 'a header with no fields',
                    'v1=' . $digest => 'a header with no timestamp',
                    't=' . $now => 'a header with no digest',
                    't=notanumber,v1=' . $digest => 'a non-numeric timestamp',
                    't=,v1=' . $digest => 'an empty timestamp',
                    't=' . $now . ',v1=' => 'an empty digest',
                    't=' . $now . ',v1=zzzz' => 'a non-hex digest',
                    't=' . $now . ',v1=' . substr($digest, 0, 32) => 'a truncated digest',
                    't=' . $now . ',v2=' . $digest => 'a version we do not know',
                    '=' . $now => 'a field with an empty name',
                    't=17e9,v1=' . $digest => 'a timestamp in scientific notation',
                ];

                foreach ($malformed as $header => $what) {
                    /*
                     * Each of these is a `false`, never a throw. A verifier
                     * that throws is an ACCEPTED webhook in a handler that
                     * forgot to catch — the failure mode is silent and the
                     * blast radius is an unauthenticated write API.
                     */
                    $result = Webhooks::verifySignature($body, (string) $header, self::SECRET, now: $now);
                    Assert::false($result, "{$what} must be rejected");
                }
            },

            '§6 whitespace and field order do not matter' => static function (): void {
                $body = '{"id":"evt_1"}';
                $now = 1_700_000_000;
                $digest = hash_hmac('sha256', $now . '.' . $body, self::SECRET);

                Assert::true(
                    Webhooks::verifySignature($body, " t = {$now} , v1 = {$digest} ", self::SECRET, now: $now),
                    'padding around the fields must be tolerated',
                );
                Assert::true(
                    Webhooks::verifySignature($body, "v1={$digest},t={$now}", self::SECRET, now: $now),
                    'v1 before t must be tolerated',
                );
                Assert::true(
                    Webhooks::verifySignature($body, "t={$now},v1=" . strtoupper($digest), self::SECRET, now: $now),
                    'an upper-case hex digest must still compare equal',
                );
            },

            '§6 the wrong secret is rejected, and an empty secret fails closed' => static function (): void {
                $body = '{"id":"evt_1"}';
                $now = 1_700_000_000;
                $header = self::sign($body, $now);

                Assert::false(
                    Webhooks::verifySignature($body, $header, 'whsec_the_wrong_one', now: $now),
                    'another endpoint\'s secret must not verify this delivery',
                );
                Assert::false(
                    Webhooks::verifySignature($body, $header, '', now: $now),
                    'a missing env var must fail CLOSED, not throw and not pass',
                );
            },

            '§6 one altered byte in the body is rejected' => static function (): void {
                $body = '{"amount":100}';
                $now = 1_700_000_000;
                $header = self::sign($body, $now);

                Assert::true(Webhooks::verifySignature($body, $header, self::SECRET, now: $now), 'the real body verifies');
                Assert::false(
                    Webhooks::verifySignature('{"amount":900}', $header, self::SECRET, now: $now),
                    'a tampered amount must be refused',
                );
                Assert::false(
                    Webhooks::verifySignature($body . "\n", $header, self::SECRET, now: $now),
                    'even a trailing newline changes the digest — which is why the RAW bytes matter',
                );
            },

            '§6 re-serialised JSON does not verify, which is why raw bytes are required' => static function (): void {
                /*
                 * The producer sends this body with spaces after the colons,
                 * as a pretty-printing gateway or a hand-built payload might.
                 * `json_decode` then `json_encode` strips them — same data,
                 * different bytes, different digest. PHP preserves key order
                 * through a round trip, so whitespace is the realistic
                 * difference to pin here; a language that does NOT preserve
                 * order would fail this for a second reason.
                 */
                $raw = '{"a": 1, "b": 2}';
                $now = 1_700_000_000;
                $header = self::sign($raw, $now);
                $reserialised = json_encode(json_decode($raw, true), \JSON_THROW_ON_ERROR);

                Assert::true(Webhooks::verifySignature($raw, $header, self::SECRET, now: $now), 'the raw bytes verify');
                Assert::notSame($raw, $reserialised, 'decode+encode changed the bytes');
                Assert::false(
                    Webhooks::verifySignature($reserialised, $header, self::SECRET, now: $now),
                    'so a handler that re-serialises its parsed body will reject every genuine delivery',
                );
            },

            '§10.9 the pinned delivery vector verifies verbatim' => static function (): void {
                /*
                 * Cross-language pinned vector. Computed and cross-checked in
                 * two implementations; if this SDK disagrees, this SDK is
                 * wrong. Do not "fix" it by changing the expectation.
                 */
                $secret = 'whsec_test';
                $body = '{"id":"evt_1"}';
                $header = 't=1700000000,v1=c89214b5b5da833daed6f0b8c5bb6bd58cea9022bd80ccc78230f3942d632925';

                Assert::same(14, \strlen($body), 'the pinned body is exactly 14 bytes, with no trailing newline');
                Assert::true(
                    Webhooks::verifySignature($body, $header, $secret, now: 1_700_000_000),
                    'the pinned delivery must verify with a fixed now',
                );
                Assert::true(
                    Webhooks::verifySignature($body, $header, $secret, \INF),
                    'and with an infinite tolerance, against the real clock',
                );

                $bodyOnly = 't=1700000000,v1=' . hash_hmac('sha256', $body, $secret);
                Assert::same(
                    't=1700000000,v1=030fa3b2413d1993c551364bd53bb9b3edb5c0c34d55dba6ada6041245632811',
                    $bodyOnly,
                    'the body-alone digest for the pinned vector, recorded so a reader can compare',
                );
                Assert::false(
                    Webhooks::verifySignature($body, $bodyOnly, $secret, \INF),
                    'the same delivery signed over the body alone must be rejected',
                );
            },

            '§6 the header name and default tolerance are the documented ones' => static function (): void {
                Assert::same(
                    'x-livechat-signature',
                    Webhooks::SIGNATURE_HEADER,
                    'the header is x-livechat-signature — not x-chatly-signature, which is a different surface',
                );
                Assert::same(300, Webhooks::DEFAULT_TOLERANCE_SECONDS, 'the default window is 300 seconds');
            },

            '§6 the default tolerance is applied when none is passed' => static function (): void {
                $body = '{"id":"evt_1"}';
                $fresh = time() - 10;
                $stale = time() - 1_000;

                Assert::true(
                    Webhooks::verifySignature($body, self::sign($body, $fresh), self::SECRET),
                    'a delivery from ten seconds ago verifies against the real clock',
                );
                Assert::false(
                    Webhooks::verifySignature($body, self::sign($body, $stale), self::SECRET),
                    'one from sixteen minutes ago does not',
                );
            },
        ];
    }
}

<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlySignatureResult;
use Chatly\ChatlySignatures;
use Chatly\Tests\Assert;
use Chatly\Webhooks;

/**
 * `verifyChatlySignature` — the cross-SDK corpus, then what the corpus cannot
 * express in JSON.
 *
 * ═══ THE CORPUS IS THE CONTRACT ═══════════════════════════════════════════
 *
 * `tests/fixtures/signature_vectors.json` is a byte-identical copy of the
 * corpus every SDK runs; sdk-node's own spec fails if any copy drifts, so it
 * is never edited here — a vector this SDK fails is a bug in this SDK. Each
 * vector is its OWN case, named after the vector, so a failure names the rule
 * that broke rather than "the corpus failed". Every expected field is
 * asserted, and every refusal must carry a non-empty message.
 *
 * The path is relative to this file and stays inside the package, because in
 * the public mirror this directory is the repository root and there is no
 * `../sdk-node` to reach for.
 *
 * ═══ THE FLOOR ════════════════════════════════════════════════════════════
 *
 * A loader that silently finds zero vectors produces zero failing cases,
 * which is a green suite that checked nothing. So loading is its own case and
 * it asserts at least {@see self::FLOOR} vectors — the count on the day this
 * was written. The corpus may grow; it may not shrink past that unnoticed.
 */
final class ChatlySignatureCases
{
    /*
     * 59 -> 61 on 2026-10-16: the corpus holds 61, so this was blind to the
     * two newest vectors. Go, Rust, Java and .NET already floored at the
     * count.
     */
    private const FLOOR = 61;

    private const CORPUS = __DIR__ . '/../fixtures/signature_vectors.json';

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        $cases = [
            'corpus loads at least ' . self::FLOOR . ' vectors' => static function (): void {
                $vectors = self::vectors();
                Assert::true($vectors !== null, 'the corpus must load from ' . self::CORPUS);
                Assert::true(
                    \count((array) $vectors) >= self::FLOOR,
                    'the corpus must hold at least ' . self::FLOOR . ' vectors; got ' . \count((array) $vectors),
                );
            },
        ];

        foreach (self::vectors() ?? [] as $i => $vector) {
            $name = 'corpus :: ' . (\is_string($vector['name'] ?? null) ? $vector['name'] : "#{$i}");
            $cases[$name] = static fn () => self::runVector($vector);
        }

        return $cases + self::extraCases();
    }

    /** @return list<array<string,mixed>>|null */
    private static function vectors(): ?array
    {
        $raw = @file_get_contents(self::CORPUS);
        if (!\is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !\is_array($decoded['vectors'] ?? null)) {
            return null;
        }

        return array_values($decoded['vectors']);
    }

    /** @param array<string,mixed> $vector */
    private static function runVector(array $vector): void
    {
        $input = $vector['input'];
        $expect = $vector['expect'];
        $result = ChatlySignatures::verify($input);
        $why = $result->ok ? '' : " (reason {$result->reason}: {$result->message})";

        Assert::same($expect['ok'], $result->ok, 'ok' . $why);
        Assert::same($expect['surface'], $result->surface, 'surface' . $why);
        if ($expect['ok'] === true) {
            Assert::same($expect['base'], $result->base, 'base');
            Assert::same($expect['secretIndex'], $result->secretIndex, 'secretIndex');
            Assert::same($expect['timestampMs'], $result->timestampMs, 'timestampMs');
            Assert::same(null, $result->reason, 'an accepted result carries no reason');
        } else {
            Assert::same($expect['reason'], $result->reason, 'reason');
            Assert::true(\is_string($result->message) && $result->message !== '', 'a refusal must carry a message');
            Assert::same(null, $result->base, 'a refusal carries no base');
            Assert::same(null, $result->secretIndex, 'a refusal carries no secretIndex');
        }
    }

    /** One known-good request, for the cases that mutate it. */
    private static function good(array $overrides = []): array
    {
        $body = '{"id":"evt_1"}';
        $t = '1760000000';

        return $overrides + [
            'surface' => 'event_webhook',
            'body' => $body,
            'headers' => ['x-livechat-signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", 'whsec_x')],
            'secret' => 'whsec_x',
            'nowMs' => 1_760_000_000_000,
        ];
    }

    /** @return array<string,\Closure():void> */
    private static function extraCases(): array
    {
        return [
            'the table has a row for every surface, and nothing else' => static function (): void {
                Assert::same(ChatlySignatures::SURFACES, array_keys(ChatlySignatures::SURFACE_TABLE), 'table keys');
                Assert::same(300, ChatlySignatures::TOLERANCE_SECONDS, 'tolerance');
                Assert::same('x-chatly-signature-v2', ChatlySignatures::V2_SIGNATURE_HEADER, 'v2 header');
                Assert::same(11, \count(ChatlySignatures::REASONS), 'eleven reasons');
            },

            'Webhooks::verifyChatlySignature is the same verifier' => static function (): void {
                $result = Webhooks::verifyChatlySignature(self::good());
                Assert::true($result->ok, 'the alias must verify a good request');
                Assert::same(1_760_000_000_000, $result->timestampMs, 'and report the timestamp in ms');
            },

            'the original verifySignature is unchanged beside it' => static function (): void {
                $good = self::good();
                $header = $good['headers']['x-livechat-signature'];
                Assert::true(
                    Webhooks::verifySignature($good['body'], $header, 'whsec_x', now: 1_760_000_000),
                    'the bool verifier still accepts the same delivery',
                );
            },

            'a timestamp past PHP_INT_MAX is malformed, not a float' => static function (): void {
                $digest = str_repeat('a', 64);
                foreach (['99999999999999999999', '9223372036854775808'] as $t) {
                    $result = ChatlySignatures::verify(self::good([
                        'headers' => ['x-livechat-signature' => "t={$t},v1={$digest}"],
                    ]));
                    Assert::same('malformed_timestamp', $result->reason, "t={$t} overflows an int");
                }
                // Fits an int, but ×1000 on a seconds surface would not.
                $result = ChatlySignatures::verify(self::good([
                    'headers' => ['x-livechat-signature' => 't=' . \PHP_INT_MAX . ",v1={$digest}"],
                ]));
                Assert::same('malformed_timestamp', $result->reason, 'seconds × 1000 overflow');
                // On a MILLISECONDS surface the same digits are an int, so it
                // gets as far as the clock — and is refused there, honestly.
                $result = ChatlySignatures::verify([
                    'surface' => 'customer_card', 'body' => '', 'secret' => 's', 'nowMs' => 0,
                    'headers' => ['x-chatly-signature' => "sha256={$digest}", 'x-chatly-timestamp' => (string) \PHP_INT_MAX],
                ]);
                Assert::same('timestamp_outside_tolerance', $result->reason, 'PHP_INT_MAX ms is far future');
            },

            'a NUL after a digest is malformed; JavaScript whitespace is trimmed' => static function (): void {
                /*
                 * PHP's `trim()` default set strips NUL and leaves `\f` and
                 * NBSP; JavaScript's `trim()` — the reference — does the
                 * opposite. A NUL-suffixed digest is malformed in the
                 * reference, so plain `trim()` here would have accepted a
                 * value every other SDK refuses.
                 */
                $good = self::good();
                $header = $good['headers']['x-livechat-signature'];
                $result = ChatlySignatures::verify(self::good(['headers' => ['x-livechat-signature' => $header . "\0"]]));
                Assert::same('malformed_signature_header', $result->reason, 'a NUL at the end of the value');
                $result = ChatlySignatures::verify(self::good(['headers' => ['x-livechat-signature' => $header . "\0,b=t"]]));
                Assert::same('malformed_signature_header', $result->reason, 'a NUL inside the v1 part');
                $result = ChatlySignatures::verify(self::good(['headers' => ['x-livechat-signature' => "\f{$header}\u{00A0}"]]));
                Assert::true($result->ok, 'JavaScript trims \\f and NBSP, so this verifies in every SDK');
            },

            'a Stringable body and PSR-7 list headers work as they arrive' => static function (): void {
                $good = self::good();
                $stream = new class ($good['body']) implements \Stringable {
                    public function __construct(private readonly string $bytes)
                    {
                    }

                    public function __toString(): string
                    {
                        return $this->bytes;
                    }
                };
                $result = ChatlySignatures::verify(self::good([
                    'body' => $stream,
                    'headers' => ['X-Livechat-Signature' => [$good['headers']['x-livechat-signature']]],
                ]));
                Assert::true($result->ok, 'a PSR-7 stream body and string[] headers must verify');
            },

            'a decoded array body is refused with a message that says so' => static function (): void {
                $result = ChatlySignatures::verify(self::good(['body' => ['id' => 'evt_1']]));
                Assert::same('signature_mismatch', $result->reason, 'an array body cannot match');
                Assert::contains('raw request bytes', (string) $result->message, 'and the message names the cause');
            },

            'never throws, never warns, on garbage' => static function (): void {
                $good = self::good();
                $garbage = [
                    'empty input' => [],
                    'int surface' => ['surface' => 7] + $good,
                    'array surface' => ['surface' => ['event_webhook']] + $good,
                    'null body' => ['body' => null] + $good,
                    'int body' => ['body' => 42] + $good,
                    'object body' => ['body' => new \stdClass()] + $good,
                    'string headers' => ['headers' => 'x-livechat-signature: t=1'] + $good,
                    'null headers' => ['headers' => null] + $good,
                    'int header value' => ['headers' => ['x-livechat-signature' => 123]] + $good,
                    'nested header value' => ['headers' => ['x-livechat-signature' => [['a']]]] + $good,
                    'object header value' => ['headers' => ['x-livechat-signature' => new \stdClass()]] + $good,
                    'genuine value beside an int' => ['headers' => ['x-livechat-signature' => [$good['headers']['x-livechat-signature'], 5]]] + $good,
                    'int secret' => ['secret' => 123] + $good,
                    'secret list of junk' => ['secret' => [null, 1, [], new \stdClass()]] + $good,
                    'null secret' => ['secret' => null] + $good,
                    'string nowMs' => ['nowMs' => 'soon'] + $good,
                    'array method' => ['surface' => 'ai_tool_call', 'method' => ['POST'], 'path' => '/x'] + $good,
                    'odd-length hex' => ['headers' => ['x-livechat-signature' => 't=1760000000,v1=' . str_repeat('a', 63)]] + $good,
                    'invalid UTF-8 value' => ['headers' => ['x-livechat-signature' => "t=\xff\xfe," . str_repeat("\xc3", 60)]] + $good,
                    'legacy replay flag as string' => ['surface' => 'workflow_webhook_legacy', 'allowUnprotectedReplay' => 'true'] + $good,
                ];

                set_error_handler(static function (int $no, string $msg): never {
                    throw new \ErrorException($msg, 0, $no);
                });
                try {
                    foreach ($garbage as $what => $input) {
                        $result = ChatlySignatures::verify($input);
                        Assert::true($result instanceof ChatlySignatureResult, "{$what}: a result");
                        Assert::false($result->ok, "{$what}: refused");
                        Assert::true(\in_array($result->reason, ChatlySignatures::REASONS, true), "{$what}: a known reason");
                        Assert::true(\is_string($result->message) && $result->message !== '', "{$what}: a message");
                    }
                } finally {
                    restore_error_handler();
                }
            },

            'NAN tolerance refuses, even a FRESH timestamp, as sdk-node does' => static function (): void {
                // A fresh request, so "fall back to 300" (accept) and "refuse"
                // give different answers. NaN is a caller's configuration bug;
                // all SDKs that can express it refuse rather than paper over it.
                $result = ChatlySignatures::verify(self::good(['toleranceSeconds' => \NAN]));
                Assert::same('timestamp_outside_tolerance', $result->reason, 'NAN refuses');
                $stale = ChatlySignatures::verify(self::good(['toleranceSeconds' => \NAN, 'nowMs' => 1_770_000_000_000]));
                Assert::same('timestamp_outside_tolerance', $stale->reason, 'NAN refuses a stale one too');
            },
        ];
    }
}

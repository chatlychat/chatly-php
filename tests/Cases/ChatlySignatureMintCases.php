<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlySignatureMint;
use Chatly\ChatlySignatures;
use Chatly\Tests\Assert;
use Chatly\Webhooks;

/**
 * `signChatlyV2` — against the reference's bytes, and round-tripped.
 *
 * Three claims, and only the first two are worth much on their own:
 *
 *   1. The base string is the documented one. Pinned as a literal, computed
 *      nowhere in this file, because a test that derives the expected string
 *      the way the code does agrees with the code's mistakes. The frozen value
 *      is the one `packages/shared/src/webhook-signature/v2.spec.ts` carries.
 *   2. What this mints verifies through this package's OWN
 *      `verifyChatlySignature`, and a one-byte change to the body, path,
 *      method, secret or timestamp makes it stop. Round-tripping alone proves
 *      only that the two halves of this package agree — which they would if
 *      both were wrong the same way — so (1) is what anchors it.
 *   3. The workflow action's DUAL-DIGEST value, `t=<T>,v1=<target>,v1=<body>`
 *      with NO marker, verifies both ways. That is the shape production sends
 *      today (`WORKFLOW_WEBHOOK_V2_PHASE = 'dual'`), it is in the published
 *      docs, and the cross-SDK corpus has no vector for it — so without this
 *      file nothing here covers the one wire value a workflow-webhook
 *      integrator actually receives.
 */
final class ChatlySignatureMintCases
{
    private const SECRET = 'whsec_mint_secret';

    private const BODY = '{"id":"evt_1","type":"message.created"}';

    private const T = 1_760_000_000;

    private const NOW_MS = 1_760_000_000_000;

    private const METHOD = 'POST';

    private const PATH = '/hooks/chatly?tenant=42';

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return self::baseStringCases() + self::refusalCases() + self::roundTripCases() + self::dualCases();
    }

    /**
     * The `v1=` field out of a minted value.
     *
     * The producer does exactly this to assemble the dual-digest value
     * (`digestOf` in `apps/worker/src/handlers/action-handlers/fire-webhook.ts`),
     * so the dual case below is built the way the bytes on the wire are.
     */
    private static function digestOf(string $value): string
    {
        foreach (explode(',', $value) as $part) {
            if (str_starts_with($part, 'v1=')) {
                return substr($part, 3);
            }
        }
        Assert::true(false, "no v1 field in {$value}");

        return '';
    }

    private static function mint(string $base = 'target', ?string $method = self::METHOD, ?string $path = self::PATH, int $t = self::T, string $body = self::BODY): string
    {
        return Webhooks::signChatlyV2(
            base: $base,
            secret: self::SECRET,
            timestampSeconds: $t,
            body: $body,
            method: $method,
            path: $path,
        );
    }

    /** @param array<string,mixed> $overrides */
    private static function verify(string $header, array $overrides = []): \Chatly\ChatlySignatureResult
    {
        return ChatlySignatures::verify($overrides + [
            'surface' => 'unified_v2',
            'body' => self::BODY,
            'headers' => ['x-chatly-signature-v2' => $header],
            'secret' => self::SECRET,
            'method' => self::METHOD,
            'path' => self::PATH,
            'nowMs' => self::NOW_MS,
        ]);
    }

    /** @return array<string,\Closure():void> */
    private static function baseStringCases(): array
    {
        return [
            'mint: the target base is t.METHOD.path.body' => static function (): void {
                Assert::same(
                    '1760000000.POST./hooks/chatly?tenant=42.{"id":"evt_1","type":"message.created"}',
                    ChatlySignatureMint::baseString('target', self::T, self::BODY, self::METHOD, self::PATH),
                    'the target base string',
                );
            },

            'mint: the body base is t.body' => static function (): void {
                Assert::same(
                    '1760000000.{"id":"evt_1","type":"message.created"}',
                    ChatlySignatureMint::baseString('body', self::T, self::BODY),
                    'the body base string',
                );
            },

            'mint: the method is upper-cased and the path is not' => static function (): void {
                // The verifier upper-cases the method and leaves the path
                // alone, so a mint that normalised the path would produce a
                // value nothing verifies.
                Assert::same(
                    '1760000000.GET./Mixed/Case?A=1.',
                    ChatlySignatureMint::baseString('target', self::T, '', 'get', '/Mixed/Case?A=1'),
                    'GET upper-cased, the path left alone',
                );
            },

            'mint: the frozen vector matches the shared producer, byte for byte' => static function (): void {
                /*
                 * The frozen vector from `packages/shared/src/webhook-signature/
                 * v2.spec.ts` ("matches a frozen vector, byte for byte"),
                 * minted here. Two independent implementations of one
                 * construction (ADR 0056) held against the same bytes — field
                 * order, separators, digest case and marker letter included,
                 * which is why it is a whole value and not just a digest.
                 */
                Assert::same(
                    't=1700000000,v1=a22f16be6cc2f88da16b175e5057406cbe820e116b9e74375a0020f1c94f5975,b=t',
                    Webhooks::signChatlyV2(
                        base: 'target',
                        secret: 'whsec_frozen',
                        timestampSeconds: 1_700_000_000,
                        body: '{"a":1}',
                        method: 'POST',
                        path: '/x',
                    ),
                    'the frozen cross-implementation vector',
                );
            },

            'mint: the marker says which base, and the two digests differ' => static function (): void {
                $target = self::mint();
                $body = self::mint('body', null, null);
                Assert::true(str_ends_with($target, ',b=t'), "target marker: {$target}");
                Assert::true(str_ends_with($body, ',b=b'), "body marker: {$body}");
                // Different bases must be different digests, or the marker
                // would be the only thing telling them apart and `b=` is free
                // for anyone on the wire to write.
                Assert::notSame(self::digestOf($target), self::digestOf($body), 'distinct digests');
            },

            'mint: a non-UTF-8 body is signed as the bytes given' => static function (): void {
                // PHP strings are bytes, and so is the thing being MACed.
                Assert::same(
                    "1760000000.\xC3\x28",
                    ChatlySignatureMint::baseString('body', self::T, "\xC3\x28"),
                    'the bytes given, not transcoded',
                );
            },
        ];
    }

    /** @return array<string,\Closure():void> */
    private static function refusalCases(): array
    {
        return [
            'mint: refuses rather than signing a string nothing can reproduce' => static function (): void {
                /*
                 * The mint THROWS where `verify` must not. An unmintable input
                 * has to stop: the alternative is a digest over a base string
                 * the receiver cannot rebuild, which arrives as "signature
                 * mismatch" and sends them hunting their own secret.
                 */
                $refused = [
                    'an unknown base' => static fn () => ChatlySignatureMint::sign('unified', self::SECRET, self::T, self::BODY, self::METHOD, self::PATH),
                    'target with no method or path' => static fn () => ChatlySignatureMint::sign('target', self::SECRET, self::T, self::BODY),
                    'target with no path' => static fn () => ChatlySignatureMint::sign('target', self::SECRET, self::T, self::BODY, self::METHOD),
                    'target with no method' => static fn () => ChatlySignatureMint::sign('target', self::SECRET, self::T, self::BODY, null, self::PATH),
                    'target with empty strings' => static fn () => ChatlySignatureMint::sign('target', self::SECRET, self::T, self::BODY, '', ''),
                    // An empty string is a valid HMAC key, which is the
                    // problem: the likeliest cause is an env var never set.
                    'an empty secret' => static fn () => ChatlySignatureMint::sign('body', '', self::T, self::BODY),
                    'a negative timestamp' => static fn () => ChatlySignatureMint::sign('body', self::SECRET, -1, self::BODY),
                ];

                foreach ($refused as $what => $call) {
                    $threw = false;
                    try {
                        $call();
                    } catch (\InvalidArgumentException $e) {
                        $threw = true;
                        Assert::true($e->getMessage() !== '', "{$what}: the exception carries a message");
                    }
                    Assert::true($threw, "{$what}: must throw InvalidArgumentException");
                }
            },
        ];
    }

    /** @return array<string,\Closure():void> */
    private static function roundTripCases(): array
    {
        return [
            'mint: a target value verifies as unified_v2' => static function (): void {
                $result = self::verify(self::mint());
                Assert::true($result->ok, 'must verify: ' . (string) $result->message);
                Assert::same('target', $result->base, 'base');
                Assert::same(self::NOW_MS, $result->timestampMs, 'timestampMs');
            },

            'mint: a body value verifies as workflow_webhook' => static function (): void {
                $result = self::verify(self::mint('body', null, null), [
                    'surface' => 'workflow_webhook', 'method' => null, 'path' => null,
                ]);
                Assert::true($result->ok, 'must verify: ' . (string) $result->message);
                Assert::same('body', $result->base, 'base');
            },

            'mint: one changed byte stops it verifying' => static function (): void {
                // Each row changes exactly ONE thing between the mint and the
                // verify. Without these, "it verifies" could be an
                // unconditional yes.
                $header = self::mint();
                Assert::same('signature_mismatch', self::verify($header, ['path' => self::PATH . '0'])->reason, 'path');
                Assert::same('signature_mismatch', self::verify($header, ['method' => 'PUT'])->reason, 'method');
                Assert::same('signature_mismatch', self::verify($header, ['body' => self::BODY . ' '])->reason, 'body');
                Assert::same('signature_mismatch', self::verify($header, ['secret' => 'whsec_other'])->reason, 'secret');
                Assert::same(
                    'timestamp_outside_tolerance',
                    self::verify(self::mint('target', self::METHOD, self::PATH, self::T - 301))->reason,
                    'a t 301s old',
                );
            },

            'mint: a forged b= marker cannot move the base' => static function (): void {
                // `b=` is something anyone on the wire can write, so
                // re-labelling a body digest as the target base must not make
                // it verify.
                $body = self::mint('body', null, null);
                $forged = substr($body, 0, -\strlen(',b=b')) . ',b=t';
                Assert::same('signature_mismatch', self::verify($forged)->reason, 'forged marker');
            },
        ];
    }

    /**
     * The workflow action's value TODAY: two digests, one value, no marker.
     *
     * `WORKFLOW_WEBHOOK_V2_PHASE = 'dual'` in
     * `apps/worker/src/handlers/action-handlers/fire-webhook.ts`, documented on
     * `/docs/api-external-agents`.
     *
     * Unified first, body last, and NO marker — the order and the absence are
     * both load-bearing. A `b=b` would make `unified_v2` refuse the value
     * outright; a `b=t` would send a verifier that keeps only the last `v1` to
     * compare the target base against the body digest.
     *
     * @return array<string,\Closure():void>
     */
    private static function dualCases(): array
    {
        $dual = static fn (): string => 't=' . self::T
            . ',v1=' . self::digestOf(self::mint())
            . ',v1=' . self::digestOf(self::mint('body', null, null));

        return [
            'mint: the dual-digest shape is two v1 fields and no marker' => static function () use ($dual): void {
                $value = $dual();
                Assert::same(2, substr_count($value, ',v1='), "two v1 fields: {$value}");
                Assert::false(str_contains($value, 'b='), "no marker: {$value}");
            },

            'mint: the dual-digest value verifies as unified_v2 with a target' => static function () use ($dual): void {
                $result = self::verify($dual());
                Assert::true($result->ok, 'must verify: ' . (string) $result->message);
                Assert::same('target', $result->base, 'base');
            },

            'mint: the dual-digest value verifies as workflow_webhook either way' => static function () use ($dual): void {
                // With a target it takes the unified digest; without one it
                // falls to the body digest, which is the receiver that has not
                // migrated. Both must pass, or the migration this shape exists
                // to enable is broken in one direction.
                $withTarget = self::verify($dual(), ['surface' => 'workflow_webhook']);
                Assert::true($withTarget->ok, 'with a target: ' . (string) $withTarget->message);
                Assert::same('target', $withTarget->base, 'the unified digest');

                $without = self::verify($dual(), [
                    'surface' => 'workflow_webhook', 'method' => null, 'path' => null,
                ]);
                Assert::true($without->ok, 'without a target: ' . (string) $without->message);
                Assert::same('body', $without->base, 'the body digest');
            },

            'mint: a tampered body fails both dual digests' => static function () use ($dual): void {
                // Two valid digests must not mean two chances at a forgery.
                foreach ([[], ['method' => null, 'path' => null]] as $i => $target) {
                    $result = self::verify($dual(), $target + [
                        'surface' => 'workflow_webhook', 'body' => self::BODY . 'x',
                    ]);
                    Assert::same('signature_mismatch', $result->reason, "tampered body #{$i}");
                }
            },

            'mint: a NAN tolerance refuses without a PHP warning' => static function (): void {
                /*
                 * Two things at once, and the second is why this case is here
                 * rather than only in ChatlySignatureCases.
                 *
                 * A NaN tolerance must REFUSE — `$drift <= NAN` is false, the
                 * same answer sdk-node gives — and a caller whose tolerance
                 * came out NaN has a configuration bug that substituting 300
                 * would hide.
                 *
                 * And rendering it must not raise `Warning: unexpected NAN
                 * value was coerced to string`, which PHP 8.5 added and which
                 * `failOnWarning="true"` turns into a suite failure. The
                 * existing NAN case did not install an error handler, so the
                 * warning escaped as a PHPUnit "issue" rather than a failure
                 * naming the line. The mirror CI matrix is 8.1 and 8.3, where
                 * the warning does not exist at all.
                 */
                set_error_handler(static function (int $no, string $msg): never {
                    throw new \ErrorException($msg, 0, $no);
                });
                try {
                    $result = self::verify(self::mint(), ['toleranceSeconds' => \NAN]);
                    Assert::same('timestamp_outside_tolerance', $result->reason, 'NAN refuses');
                    Assert::true(
                        str_contains((string) $result->message, 'NaN'),
                        'and the message spells it the way the reference does: ' . (string) $result->message,
                    );
                    $infinite = self::verify(self::mint(), ['toleranceSeconds' => \INF]);
                    Assert::true($infinite->ok, 'an infinite tolerance accepts anything');
                } finally {
                    restore_error_handler();
                }
            },
        ];
    }
}

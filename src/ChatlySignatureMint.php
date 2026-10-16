<?php

declare(strict_types=1);

namespace Chatly;

/**
 * Minting a v2 signature — the other direction from {@see ChatlySignatures}.
 *
 * A port of `signChatlyV2` / `chatlyV2BaseString` from the Node SDK
 * (`packages/sdk-node/src/signature-mint.ts` in the monorepo), which is the
 * reference: where this class and that module disagree about the bytes, that
 * one is right and this one has a bug.
 *
 * Verifying is what a customer's handler does, and it lives next door. Minting
 * is what a TEST does — standing up a fake Chatly and firing a signed request
 * at your own endpoint — and until this class existed a PHP integrator writing
 * that test had to re-derive `<t>.<METHOD>.<path>.<rawBody>` by hand from the
 * documentation. A hand-derived base string is the one thing a signature test
 * must not have: get it wrong the same way in the test and nowhere else, and
 * the test passes while production refuses every delivery. `packages/shared`
 * shipped the mirror image of that once — a header in milliseconds over a
 * seconds value — and a verifier following our own published docs refused 100%
 * of real traffic.
 *
 * Its own class because {@see ChatlySignatures} is 612 lines against the
 * repository's 650-line ceiling, which is the same reason the Node reference
 * split it out. {@see Webhooks::signChatlyV2()} is the thin alias, so there is
 * one class to look in.
 *
 * It is deliberately NOT for signing real outbound traffic: nothing in this
 * package sends a signed request to a customer. Chatly's own producers are
 * `packages/shared/src/webhook-signature/v2.ts`.
 *
 * ═══ IT THROWS, WHERE THE VERIFIER MUST NOT ═══════════════════════════════
 *
 * {@see ChatlySignatures::verify()} never throws, because a verifier that
 * throws is an accepted webhook in a handler that forgot to catch. A mint has
 * the opposite duty: an unmintable input must stop, because the alternative is
 * a signature over a string the receiver cannot reproduce, which reaches them
 * as "signature mismatch" and sends them hunting their own secret. So every
 * refusal here is an `InvalidArgumentException`.
 *
 * That is also why these take TYPED, NAMED parameters where
 * {@see ChatlySignatures::verify()} takes one untyped `array $input`. The
 * verifier's array exists so that PHP's own `TypeError` on a mistyped argument
 * cannot be the thing that escapes a never-throws promise. A mint has no such
 * promise, so the stricter signature is free, and a mistyped argument fails at
 * the call rather than signing something unexpected.
 *
 * ═══ THE MARKER, WHICH IS WHY `base` HAS NO DEFAULT ═══════════════════════
 *
 * `signChatlyV2` emits `b=t` for the unified base and `b=b` for the body base,
 * and there is no default: the two constructions are indistinguishable from
 * the value alone, which is precisely why the marker exists, so a caller has
 * to say which one it means. An ABSENT marker means the BODY base — every v2
 * signature Chatly minted before the marker existed was `<t>.<rawBody>` — and
 * this method never emits an absent one.
 *
 * The one wire value it cannot produce on its own is the workflow action's
 * current dual-digest form, `t=<T>,v1=<target>,v1=<body>` with no marker. That
 * is built from two mints by taking the `v1=` field out of each, which is what
 * the producer itself does (`digestOf` in
 * `apps/worker/src/handlers/action-handlers/fire-webhook.ts`);
 * `tests/Cases/ChatlySignatureMintCases.php` does the same and verifies the
 * result.
 */
final class ChatlySignatureMint
{
    /**
     * Which string a v2 signature was taken over.
     *
     * `target` is the unified base `<t>.<METHOD>.<path>.<rawBody>`; `body` is
     * `<t>.<rawBody>`.
     */
    public const BASES = ['target', 'body'];

    /**
     * The exact bytes the MAC is taken over, so a test can pin them.
     *
     * The method is upper-cased, as the verifier upper-cases it; `$path` is
     * `pathname + query` of the request line and never the scheme or host.
     *
     * @param 'target'|'body' $base
     *
     * @throws \InvalidArgumentException on an unknown base, on a `target`
     *                                   mint with no method or path, or on a
     *                                   timestamp that is not a non-negative
     *                                   int
     */
    public static function baseString(
        string $base,
        int $timestampSeconds,
        string $body,
        ?string $method = null,
        ?string $path = null,
    ): string {
        if (!\in_array($base, self::BASES, true)) {
            throw new \InvalidArgumentException(
                "base must be \"target\" or \"body\", got \"{$base}\". There is no default: the "
                . 'two constructions are indistinguishable without the marker signChatlyV2 emits.',
            );
        }
        if ($timestampSeconds < 0) {
            throw new \InvalidArgumentException('timestampSeconds must not be negative');
        }
        if ($base === 'body') {
            return $timestampSeconds . '.' . $body;
        }
        if ($method === null || $method === '') {
            throw new \InvalidArgumentException('base "target" signs the METHOD, so method is required');
        }
        if ($path === null || $path === '') {
            throw new \InvalidArgumentException(
                'base "target" signs the path, so path is required — pathname + query of the '
                . 'request line, not the scheme or host',
            );
        }

        /*
         * `strtoupper`, not `mb_strtoupper`: the verifier's `baseStringPrefix`
         * uses `strtoupper` and ext-mbstring is not a dependency of this
         * package. For an HTTP method the two agree; for anything else they
         * might not, and the mint has to produce what THIS SDK's verifier
         * reproduces.
         */
        return $timestampSeconds . '.' . strtoupper($method) . '.' . $path . '.' . $body;
    }

    /**
     * The `x-chatly-signature-v2` value for one request: `t=…,v1=…,b=…`.
     *
     * ```php
     * $header = Webhooks::signChatlyV2(
     *     base: 'target',
     *     secret: $secret,
     *     timestampSeconds: 1760000000,
     *     body: $raw,
     *     method: 'POST',
     *     path: '/hooks/chatly',
     * );
     * // t=1760000000,v1=<64 hex>,b=t
     * ```
     *
     * `$timestampSeconds` is unix **seconds**, never milliseconds. Two
     * surfaces send millisecond timestamps in their LEGACY headers; the v2
     * header is seconds on every one of them.
     *
     * An empty secret is refused: an empty string is a perfectly valid HMAC
     * key, which is the problem, and the likeliest cause is an environment
     * variable that never got set.
     *
     * @param 'target'|'body' $base
     *
     * @throws \InvalidArgumentException see {@see self::baseString()}
     */
    public static function sign(
        string $base,
        string $secret,
        int $timestampSeconds,
        string $body,
        ?string $method = null,
        ?string $path = null,
    ): string {
        if ($secret === '') {
            throw new \InvalidArgumentException('secret must not be empty');
        }
        $message = self::baseString($base, $timestampSeconds, $body, $method, $path);
        $digest = hash_hmac('sha256', $message, $secret);
        $marker = $base === 'target' ? 't' : 'b';

        return "t={$timestampSeconds},v1={$digest},b={$marker}";
    }
}

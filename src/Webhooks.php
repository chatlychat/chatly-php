<?php

declare(strict_types=1);

namespace Chatly;

/**
 * Webhook signature verification.
 *
 * Every delivery Chatly POSTs to your endpoint carries
 * `x-livechat-signature: t=<unix seconds>,v1=<hex>`. Verify it before you act
 * on the payload — an unverified webhook endpoint is an unauthenticated write
 * API into your own system.
 */
final class Webhooks
{
    /** The header to read. Not `x-chatly-signature`; that is a different product surface. */
    public const SIGNATURE_HEADER = 'x-livechat-signature';

    /** Default freshness window for `t`, in seconds. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify one delivery. Returns true or false; it never throws.
     *
     * ─── THE DIGEST IS OVER `<t>.<body>`, NOT THE BODY ────────────────────
     *
     * `v1` is `HMAC-SHA256("<t>." + raw body, secret)`. The timestamp is bound
     * INTO the digest, and that is what makes the replay window enforceable:
     * an attacker who replays a captured body cannot move `t` forward without
     * invalidating `v1`.
     *
     * An earlier implementation of this in another language HMAC'd the body
     * alone and therefore rejected 100% of genuine deliveries. The pinned
     * vector in this package's test suite exists so that cannot recur.
     *
     * ─── PASS THE RAW BYTES ───────────────────────────────────────────────
     *
     * `file_get_contents('php://input')` in plain PHP,
     * `$request->getContent()` in Symfony, `$request->getContent()` in
     * Laravel. **Never** `json_encode(json_decode($body))`: re-serialising
     * reorders keys and changes whitespace, and the digest will not match.
     *
     * @param string     $body            The exact bytes received.
     * @param string     $signatureHeader The `x-livechat-signature` value.
     * @param string     $secret          The endpoint's `whsec_…` signing secret.
     * @param float      $tolerance       Seconds `t` may differ from now. Pass
     *                                    `INF` to skip the check when
     *                                    re-verifying an archived delivery.
     * @param int|null   $now             Unix seconds to compare against.
     *                                    For tests and for replaying an
     *                                    archive; defaults to the system clock.
     */
    public static function verifySignature(
        string $body,
        string $signatureHeader,
        string $secret,
        float $tolerance = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        /*
         * A verifier that throws is an accepted webhook in a handler that
         * forgot to catch. Every refusal below is a `return false`, and the
         * empty-secret case is one of them rather than an exception for
         * exactly that reason: a missing env var must fail CLOSED.
         */
        if ($secret === '') {
            return false;
        }

        $parsed = self::parseHeader($signatureHeader);
        if ($parsed === null) {
            return false;
        }
        [$timestamp, $provided] = $parsed;

        $now ??= time();
        if (!is_infinite($tolerance) && abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        /*
         * Length first, then a constant-time compare. `hash_equals` returns
         * false on a length mismatch rather than throwing, so the explicit
         * check is belt-and-braces here — but it is the contract across every
         * Chatly SDK, because the constant-time compare in some languages
         * DOES throw on unequal lengths, and a thrown verifier is an accepted
         * webhook.
         */
        if (\strlen($provided) !== \strlen($expected)) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Verify a signed request from ANY Chatly surface, with a reason on
     * refusal. Never throws.
     *
     * The successor to {@see self::verifySignature()}, which stays exactly as
     * it was: it verifies one surface (`event_webhook`) and answers a bare
     * bool. This one takes the surface, so the same call verifies an agent
     * delivery, a tool call, a canvas step, a customer-card fetch or a
     * workflow action, picks up the unified `x-chatly-signature-v2` header
     * when it appears, accepts a list of secrets during a rotation, and says
     * WHY it refused — see {@see ChatlySignatures} for the table and every key
     * `$input` takes.
     *
     * ```php
     * $result = Webhooks::verifyChatlySignature([
     *     'surface' => 'event_webhook',
     *     'body'    => file_get_contents('php://input'),
     *     'headers' => getallheaders(),
     *     'secret'  => $_ENV['CHATLY_WEBHOOK_SECRET'],
     *     'method'  => $_SERVER['REQUEST_METHOD'],
     *     'path'    => $_SERVER['REQUEST_URI'],
     * ]);
     * if (!$result->ok) {
     *     error_log("{$result->reason}: {$result->message}");
     * }
     * ```
     *
     * @param array<string,mixed> $input
     */
    public static function verifyChatlySignature(array $input): ChatlySignatureResult
    {
        return ChatlySignatures::verify($input);
    }

    /**
     * Split `t=…,v1=…` into `[timestamp, digest]`, or null if unusable.
     *
     * Returns null — never throws — for a missing field, a non-numeric `t`, a
     * non-hex `v1`, an empty header, or any other malformed input. Extra
     * fields and whitespace are ignored, and the order of `t` and `v1` does
     * not matter.
     *
     * @return array{0:string,1:string}|null
     */
    private static function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $digest = null;

        foreach (explode(',', $header) as $part) {
            $equals = strpos($part, '=');
            if ($equals === false || $equals === 0) {
                continue;
            }
            $name = trim(substr($part, 0, $equals));
            $value = trim(substr($part, $equals + 1));
            if ($name === 't' && $timestamp === null) {
                $timestamp = $value;
            } elseif ($name === 'v1' && $digest === null) {
                $digest = $value;
            }
        }

        if ($timestamp === null || $digest === null) {
            return null;
        }
        // Digits only. `(int) '17abc'` is 17, so a cast would quietly accept a
        // corrupt timestamp; 19 digits is the ceiling a 64-bit int holds.
        if (preg_match('/^\d{1,19}$/', $timestamp) !== 1) {
            return null;
        }
        /*
         * Hex, of a plausible length — NOT pinned to 64 characters. Pinning
         * it would make the length check in `verifySignature` dead code, and
         * that check is the one the cross-language contract calls for. A
         * digest of the wrong length is rejected there, on the comparison
         * path, where it belongs.
         */
        if (preg_match('/^[0-9a-fA-F]{2,128}$/', $digest) !== 1) {
            return null;
        }

        // Lowercased: `hash_hmac` emits lowercase hex and `hash_equals` is
        // case-sensitive, so an upper-case digest from a future producer
        // would otherwise fail a comparison it should pass.
        return [$timestamp, strtolower($digest)];
    }
}

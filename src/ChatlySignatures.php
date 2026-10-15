<?php

declare(strict_types=1);

namespace Chatly;

/**
 * One verifier for every signature Chatly puts on an outbound request.
 *
 * A port of `verifyChatlySignature` in `packages/sdk-node/src/signatures.ts`,
 * which is the reference: where this file and that one disagree, that one is
 * right and this one has a bug. The cross-SDK corpus at
 * `tests/fixtures/signature_vectors.json` is what proves they agree — every
 * vector in it runs through this class in `Cases/ChatlySignatureCases.php`.
 *
 * Most callers want {@see Webhooks::verifyChatlySignature()}, which is a thin
 * alias kept beside the original `verifySignature` so there is one class to
 * look in. The table, the constants and the algorithm live here.
 *
 * ═══ WHY IT TAKES A `surface` ═════════════════════════════════════════════
 *
 * Because the header name does not determine the construction. Three surfaces
 * send `x-chatly-signature` and sign three different strings, and two of the
 * eight send MILLISECONDS. The caller knows which route they are terminating;
 * this class cannot. {@see self::SURFACE_TABLE} has the detail.
 *
 *   | surface                   | header                      | base string                     | unit    |
 *   | ------------------------- | --------------------------- | ------------------------------- | ------- |
 *   | `unified_v2`              | `x-chatly-signature-v2`     | `<t>.<METHOD>.<path>.<rawBody>` | seconds |
 *   | `agent_delivery`          | `x-chatly-signature`        | `<t>.<rawBody>`                 | seconds |
 *   | `event_webhook`           | `x-livechat-signature`      | `<t>.<rawBody>`                 | seconds |
 *   | `ai_tool_call`            | `x-chatly-signature`        | `<t>.<METHOD>.<path>.<rawBody>` | seconds |
 *   | `canvas_step`             | `x-chatly-canvas-signature` | `<ts>.<rawBody>` (+ `-ts` hdr)  | **ms**  |
 *   | `customer_card`           | `x-chatly-signature`        | `<ts>.<rawBody>` (+ ts hdr)     | **ms**  |
 *   | `workflow_webhook`        | `x-chatly-signature-v2`     | `<t>.<rawBody>` (or target)     | seconds |
 *   | `workflow_webhook_legacy` | `x-chatly-signature`        | `<rawBody>` — NO timestamp      | n/a     |
 *
 * ═══ THE UNIFIED v2 CONSTRUCTION ══════════════════════════════════════════
 *
 * `x-chatly-signature-v2: t=<unix seconds>,v1=<hex>` over
 * `<t>.<METHOD>.<path>.<rawBody>` is where every surface is going, sent beside
 * each one's legacy header. `v2` on the input chooses how hard to insist:
 *
 *   - `prefer` (default) — if the v2 header is present AND `method` and `path`
 *     were supplied, verify THAT and nothing else; otherwise the surface's own
 *     legacy construction. A receiver that passes method and path gets the
 *     stronger check the day the sender starts emitting v2, with no code change.
 *   - `require` — refuse unless the v2 header is there.
 *   - `ignore`  — the surface's own construction only.
 *
 * `prefer` never cross-falls-back: once v2 is chosen, a bad v2 digest is a
 * refusal, not a reason to try the legacy header. Both come from the same
 * secret, so a good legacy digest beside a bad v2 one is tampering or a sender
 * bug, and accepting it would hide both.
 *
 * ═══ WHY IT NEVER THROWS ══════════════════════════════════════════════════
 *
 * CONTRACT.md §6: "a verifier that throws is an accepted webhook in a handler
 * that forgot to catch". Every input — a missing header, a duplicated header,
 * a non-hex digest, an unknown surface, an empty secret, a body that is an
 * array, a header value that is an int — comes back as `ok === false`. The
 * input is an untyped array precisely so that PHP's own `TypeError` on a
 * mistyped argument cannot be the thing that escapes; and the whole body is
 * wrapped in a catch-all that maps anything unforeseen to `signature_mismatch`
 * (the refusal that grants nothing), because "never throws" is a promise and a
 * promise wants a backstop.
 */
final class ChatlySignatures
{
    /** Five minutes, matching `SIGNATURE_TOLERANCE_SECONDS` on the producing side. */
    public const TOLERANCE_SECONDS = 300;

    /** The header the unified construction travels in, on every surface. */
    public const V2_SIGNATURE_HEADER = 'x-chatly-signature-v2';

    /**
     * Not a header name: the timestamp is the digest header's own `t=` field.
     * A sentinel rather than a boolean, so a surface that moves its timestamp
     * into a header of its own needs only a table edit.
     */
    public const IN_SIGNATURE = 'in-signature';

    /** Every surface, in the reference's order. */
    public const SURFACES = [
        'unified_v2',
        'agent_delivery',
        'event_webhook',
        'ai_tool_call',
        'canvas_step',
        'customer_card',
        'workflow_webhook',
        'workflow_webhook_legacy',
    ];

    /** Why a verification failed. Stable strings, safe to branch on. */
    public const REASONS = [
        'unknown_surface',
        'missing_secret',
        'missing_signature_header',
        'duplicate_signature_header',
        'malformed_signature_header',
        'missing_timestamp',
        'malformed_timestamp',
        'timestamp_outside_tolerance',
        'missing_request_target',
        'replay_unprotected',
        'signature_mismatch',
    ];

    /**
     * The table, as data — the thing to read when a verification fails.
     *
     * Kept as data rather than a `match` so a test can assert every name in
     * {@see self::SURFACES} has a row: a surface added to the list and
     * forgotten here would otherwise be an `unknown_surface` refusal at
     * runtime with a green suite.
     *
     * `timestampFrom` is a header name, {@see self::IN_SIGNATURE}, or null
     * when the surface sends none. `unit` is a property of the SURFACE, never
     * of the value: a millisecond timestamp read as seconds lands ~54,000
     * years out and the refusal looks like a wrong secret — this repository
     * has shipped that mistake in both directions.
     *
     * `alsoAcceptsTarget` is `workflow_webhook` only: it sent the v2 header
     * BEFORE the base gained the method and path, so the same header name
     * carries two base strings until its sender moves.
     *
     * @var array<string,array{header:string,timestampFrom:?string,format:string,unit:?string,base:string,alsoAcceptsTarget:bool,describe:string}>
     */
    public const SURFACE_TABLE = [
        'unified_v2' => [
            'header' => self::V2_SIGNATURE_HEADER, 'timestampFrom' => self::IN_SIGNATURE,
            'format' => 'tv1', 'unit' => 'seconds', 'base' => 'target', 'alsoAcceptsTarget' => false,
            'describe' => "the unified v2 signature, sent alongside every surface\u{2019}s legacy header, over t.METHOD.path.body",
        ],
        'agent_delivery' => [
            'header' => 'x-chatly-signature', 'timestampFrom' => self::IN_SIGNATURE,
            'format' => 'tv1', 'unit' => 'seconds', 'base' => 'body', 'alsoAcceptsTarget' => false,
            'describe' => "external-agent push delivery, signed with the bot's signingSecret",
        ],
        'event_webhook' => [
            'header' => 'x-livechat-signature', 'timestampFrom' => self::IN_SIGNATURE,
            'format' => 'tv1', 'unit' => 'seconds', 'base' => 'body', 'alsoAcceptsTarget' => false,
            'describe' => "event webhook, signed with that subscription's whsec_ secret",
        ],
        'ai_tool_call' => [
            'header' => 'x-chatly-signature', 'timestampFrom' => self::IN_SIGNATURE,
            'format' => 'tv1', 'unit' => 'seconds', 'base' => 'target', 'alsoAcceptsTarget' => false,
            'describe' => "custom AI tool call, signed with that tool's secret over t.METHOD.path.body",
        ],
        'canvas_step' => [
            'header' => 'x-chatly-canvas-signature', 'timestampFrom' => 'x-chatly-canvas-ts',
            'format' => 'sha256', 'unit' => 'milliseconds', 'base' => 'body', 'alsoAcceptsTarget' => false,
            'describe' => 'canvas / interactive-step callback, timestamp in MILLISECONDS',
        ],
        'customer_card' => [
            'header' => 'x-chatly-signature', 'timestampFrom' => 'x-chatly-timestamp',
            'format' => 'sha256', 'unit' => 'milliseconds', 'base' => 'body', 'alsoAcceptsTarget' => false,
            'describe' => 'customer-card fetch, timestamp in MILLISECONDS',
        ],
        'workflow_webhook' => [
            'header' => self::V2_SIGNATURE_HEADER, 'timestampFrom' => self::IN_SIGNATURE,
            'format' => 'tv1', 'unit' => 'seconds', 'base' => 'body', 'alsoAcceptsTarget' => true,
            'describe' => 'workflow "call a webhook" action, replay-bounded v2 header',
        ],
        'workflow_webhook_legacy' => [
            'header' => 'x-chatly-signature', 'timestampFrom' => null,
            'format' => 'sha256', 'unit' => null, 'base' => 'body_only', 'alsoAcceptsTarget' => false,
            'describe' => "workflow action, LEGACY header over the body alone \u{2014} no replay protection",
        ],
    ];

    /**
     * Verify one signed request from Chatly. Never throws.
     *
     * Keys of `$input` (camelCase, matching the reference and the corpus):
     *
     *   - `surface`  string, one of {@see self::SURFACES}
     *   - `body`     the EXACT bytes received (a string, or a `Stringable` such
     *                as a PSR-7 stream) — never `json_encode(json_decode(…))`
     *   - `headers`  `array<string, string|string[]>`, looked up
     *                case-insensitively — `getallheaders()`, PSR-7
     *                `getHeaders()` and Symfony `$request->headers->all()`
     *                all fit as they are
     *   - `secret`   string, or a list of every secret live on this surface
     *   - `method`, `path`  the request's method and `pathname + search`
     *   - `toleranceSeconds`  int|float, default 300, both directions; `INF`
     *                only when re-verifying an archive
     *   - `v2`       `prefer` (default) | `require` | `ignore`
     *   - `allowUnprotectedReplay`  `true` to accept `workflow_webhook_legacy`
     *   - `nowMs`    unix MILLISECONDS, for tests; default the system clock
     *
     * @param array<string,mixed> $input
     */
    public static function verify(array $input): ChatlySignatureResult
    {
        try {
            return self::verifyInput($input);
        } catch (\Throwable) {
            /*
             * Unreachable by construction — every branch below type-checks
             * what it reads — and kept anyway. The mapping is to the refusal
             * that grants nothing, as SPEC.md asks; it deliberately does not
             * echo the exception, which may quote the secret or the body.
             */
            $surface = \is_string($input['surface'] ?? null) ? $input['surface'] : '';

            return ChatlySignatureResult::refused(
                $surface,
                'signature_mismatch',
                'The request could not be verified: an input was not of a usable type. Pass the raw '
                    . 'body as a string, headers as an array of strings, and the secret as a string.',
            );
        }
    }

    /** @param array<string,mixed> $input */
    private static function verifyInput(array $input): ChatlySignatureResult
    {
        $rawSurface = $input['surface'] ?? null;
        $surface = \is_string($rawSurface) ? $rawSurface : '';
        // `isset` on the const array rather than `in_array`: a non-string key
        // cannot reach here, and `in_array` without `true` would let `0` match.
        if (!\is_string($rawSurface) || !isset(self::SURFACE_TABLE[$rawSurface])) {
            $shown = \is_string($rawSurface) ? $rawSurface : get_debug_type($rawSurface);

            return ChatlySignatureResult::refused(
                $surface,
                'unknown_surface',
                "\"{$shown}\" is not a Chatly signature surface. One of: " . implode(', ', self::SURFACES) . '.',
            );
        }
        $spec = self::SURFACE_TABLE[$surface];

        $secrets = SignatureText::secretsFrom($input['secret'] ?? null);
        if ($secrets === []) {
            return ChatlySignatureResult::refused(
                $surface,
                'missing_secret',
                "No secret given for {$surface}. Each surface has its own secret \u{2014} {$spec['describe']}. "
                    . 'A secret from another surface will never verify.',
            );
        }

        if ($surface === 'workflow_webhook_legacy' && ($input['allowUnprotectedReplay'] ?? false) !== true) {
            return ChatlySignatureResult::refused(
                $surface,
                'replay_unprotected',
                'The legacy workflow header signs the body with no timestamp, so a captured request '
                    . 'verifies forever and nothing here can bound a replay. The same call also sends '
                    . 'x-chatly-signature-v2 — verify that instead, with surface "workflow_webhook". '
                    . "If you must accept the legacy header, pass 'allowUnprotectedReplay' => true and make "
                    . 'the endpoint idempotent.',
            );
        }

        $viaV2 = self::applyV2Policy($input, $surface, $spec);
        if ($viaV2 !== null) {
            return $viaV2;
        }

        // ── The digest header ───────────────────────────────────────────────
        $sigHeaders = SignatureText::headerValues($input['headers'] ?? null, $spec['header']);
        if ($sigHeaders === []) {
            return ChatlySignatureResult::refused(
                $surface,
                'missing_signature_header',
                "No {$spec['header']} header. " . ucfirst($spec['describe']) . ' always sends one; '
                    . 'if the request carries a differently named signature header, you have the wrong surface.',
            );
        }
        if (\count($sigHeaders) > 1) {
            // Two values means something between Chatly and you merged or
            // injected one, and picking either is a guess. Refuse rather than
            // trial-verify both: trial-verifying lets an attacker who can
            // append a header ride along beside a genuine one.
            return ChatlySignatureResult::refused(
                $surface,
                'duplicate_signature_header',
                \count($sigHeaders) . " {$spec['header']} headers arrived. Chatly sends exactly one; "
                    . 'a proxy merged or added one, and choosing between them would be a guess.',
            );
        }
        $rawSignature = SignatureText::trim($sigHeaders[0]);

        $rawTimestamp = null;
        $marker = null;
        if ($spec['format'] === 'tv1') {
            $parsed = SignatureText::parseTv1($rawSignature);
            if ($parsed === null) {
                return ChatlySignatureResult::refused(
                    $surface,
                    'malformed_signature_header',
                    "{$spec['header']} should be \"t=<unix seconds>,v1=<hex>\" on {$surface}; got \""
                        . SignatureText::preview($rawSignature) . '".',
                );
            }
            [$rawTimestamp, $digestsHex, $marker] = $parsed;
        } else {
            if (!str_starts_with($rawSignature, 'sha256=')) {
                return ChatlySignatureResult::refused(
                    $surface,
                    'malformed_signature_header',
                    "{$spec['header']} should be \"sha256=<hex>\" on {$surface}; got \""
                        . SignatureText::preview($rawSignature) . '". A "t=…,v1=…" value here means you '
                        . 'have the wrong surface — that is the other signature family.',
                );
            }
            $digestsHex = [SignatureText::trim(substr($rawSignature, \strlen('sha256=')))];
        }

        foreach ($digestsHex as $digestHex) {
            /*
             * Shape-checked BEFORE decoding. `hex2bin` on odd-length or
             * non-hex input emits a warning and returns false — a warning
             * that a framework error handler may well turn into an
             * exception. `D` because a bare `$` also matches before a
             * trailing newline, which would let "<64 hex>\n" through.
             */
            if (preg_match('/^[0-9a-fA-F]{64}$/D', $digestHex) !== 1) {
                return ChatlySignatureResult::refused(
                    $surface,
                    'malformed_signature_header',
                    "A digest in {$spec['header']} is not 64 hex characters (got " . \strlen($digestHex) . ').'
                        . (\count($digestsHex) > 1
                            ? ' ' . \count($digestsHex) . ' v1 values arrived; every one has to be well formed.'
                            : ''),
                );
            }
        }

        // ── The timestamp, and its unit ─────────────────────────────────────
        $timestampMs = null;
        if ($spec['unit'] !== null) {
            if ($spec['timestampFrom'] !== self::IN_SIGNATURE && $spec['timestampFrom'] !== null) {
                $tsHeaders = SignatureText::headerValues($input['headers'] ?? null, $spec['timestampFrom']);
                if ($tsHeaders === []) {
                    return ChatlySignatureResult::refused(
                        $surface,
                        'missing_timestamp',
                        "No {$spec['timestampFrom']} header. On {$surface} the timestamp travels in its own "
                            . 'header and is part of the signed string, so it cannot be reconstructed.',
                    );
                }
                $rawTimestamp = SignatureText::trim($tsHeaders[0]);
            }
            if ($rawTimestamp === null || $rawTimestamp === '') {
                return ChatlySignatureResult::refused($surface, 'missing_timestamp', "No timestamp found for {$surface}.");
            }
            $timestampMs = SignatureText::timestampToMs($rawTimestamp, $spec['unit']);
            if (\is_string($timestampMs)) {
                return ChatlySignatureResult::refused($surface, 'malformed_timestamp', $timestampMs);
            }
            $outside = self::checkTolerance($input, $surface, $spec['unit'], $timestampMs);
            if ($outside !== null) {
                return $outside;
            }
        }

        // ── The base string, and the compare ────────────────────────────────
        $bases = self::candidateBases($input, $surface, $spec, $marker);
        if ($bases instanceof ChatlySignatureResult) {
            return $bases;
        }
        if (\in_array('target', $bases, true) && !self::hasTarget($input)) {
            return ChatlySignatureResult::refused(
                $surface,
                'missing_request_target',
                "{$surface} signs <t>.<METHOD>.<path>.<rawBody>, so both method and path are required. "
                    . 'path is pathname + search of the request as Chatly rendered it — not the host, not the scheme.',
            );
        }

        $body = $input['body'] ?? null;
        if ($body instanceof \Stringable) {
            $body = (string) $body;
        }
        if (!\is_string($body)) {
            return ChatlySignatureResult::refused(
                $surface,
                'signature_mismatch',
                'The body is a ' . get_debug_type($body) . ', not the raw request bytes. Pass the body exactly '
                    . "as received — file_get_contents('php://input'), \$request->getContent() — never a decoded array.",
            );
        }

        // Every digest is 64 validated hex characters, so this cannot fail.
        $given = array_map(static fn (string $hex): string => (string) hex2bin($hex), $digestsHex);

        foreach ($bases as $base) {
            $prefix = self::baseStringPrefix($base, $rawTimestamp, $input);
            foreach ($secrets as [$secret, $index]) {
                // Raw binary output, compared against the decoded digests, so
                // an upper-case hex digest verifies exactly like a lower one.
                $expected = hash_hmac('sha256', $prefix . $body, $secret, true);
                foreach ($given as $candidate) {
                    /*
                     * Length first, then a constant-time compare. `hash_equals`
                     * returns false on unequal lengths rather than throwing, so
                     * here this is belt-and-braces — but it is the contract in
                     * every Chatly SDK, because in some languages the
                     * constant-time compare DOES throw, and a thrown verifier
                     * is an accepted webhook.
                     */
                    if (\strlen($candidate) === \strlen($expected) && hash_equals($expected, $candidate)) {
                        return ChatlySignatureResult::accepted($surface, $timestampMs, $base, $index);
                    }
                }
            }
        }

        return ChatlySignatureResult::refused(
            $surface,
            'signature_mismatch',
            "The digest does not match for {$surface}. The three usual causes, in order: a re-serialised "
                . 'body (pass the RAW bytes, not json_encode of the decoded array), the secret from a different '
                . "surface, or the wrong surface \u{2014} {$spec['describe']}."
                . (\count($secrets) > 1 ? ' None of the ' . \count($secrets) . ' secrets matched.' : ''),
        );
    }

    /**
     * The v2 decision, taken before anything is parsed.
     *
     * Returns a result when it has answered the whole question — by delegating
     * to `unified_v2` or by refusing — and null when verification should carry
     * on with the surface asked for.
     *
     * @param array<string,mixed> $input
     * @param array{header:string,describe:string} $spec
     */
    private static function applyV2Policy(array $input, string $surface, array $spec): ?ChatlySignatureResult
    {
        $policy = self::policy($input);
        // A surface whose OWN header is the v2 header resolves the two bases
        // in its candidate list instead; there is nothing to delegate to.
        if ($policy === 'ignore' || $surface === 'unified_v2' || $spec['header'] === self::V2_SIGNATURE_HEADER) {
            return null;
        }

        $present = SignatureText::headerValues($input['headers'] ?? null, self::V2_SIGNATURE_HEADER) !== [];
        if ($policy === 'require' && !$present) {
            return ChatlySignatureResult::refused(
                $surface,
                'missing_signature_header',
                'v2: "require" was asked for and this request carries no ' . self::V2_SIGNATURE_HEADER . ' header. '
                    . "{$spec['header']} alone means the sender has not moved to the unified construction yet "
                    . '— use the default v2: "prefer" until it has.',
            );
        }
        if (!$present) {
            return null;
        }
        if (!self::hasTarget($input)) {
            if ($policy === 'require') {
                return ChatlySignatureResult::refused(
                    $surface,
                    'missing_request_target',
                    'The unified ' . self::V2_SIGNATURE_HEADER . ' signs <t>.<METHOD>.<path>.<rawBody>, so both '
                        . 'method and path are required to verify it. path is pathname + search of the request as '
                        . 'it arrived — not the host, not the scheme.',
                );
            }
            // `prefer` with no target to verify against: the legacy header is
            // still sent beside it, so fall through rather than refuse a
            // request the caller CAN verify.
            return null;
        }

        $result = self::verifyInput(['surface' => 'unified_v2', 'v2' => 'ignore'] + $input);
        if ($result->ok) {
            return $result;
        }

        return $result->withSurfaceAndMessage(
            'unified_v2',
            $result->message . ' (This request carried ' . self::V2_SIGNATURE_HEADER . ', so it was verified as '
                . "the unified v2 construction rather than as {$surface}. Pass v2: \"ignore\" to verify "
                . "{$spec['header']} instead.)",
        );
    }

    /**
     * Which strings the MAC may have been taken over, most specific first — or
     * a refusal when the value's `b=` marker rules every candidate out.
     *
     * `b=t` means `<t>.<METHOD>.<path>.<rawBody>`, `b=b` means `<t>.<rawBody>`.
     * It is read ONLY off the v2 header (no legacy header carries one), and it
     * NARROWS the candidates; it never widens them past what the surface and
     * the policy allow, because anyone on the wire can write a marker. An
     * absent or unrecognised marker (`b=z`) changes nothing: every v2 value
     * minted before the marker existed is absent, and keeps verifying as it
     * did. What the marker buys is the error — a receiver passing no method
     * and path to a `b=t` workflow value gets `missing_request_target`, not a
     * `signature_mismatch` that sends them hunting their secret.
     *
     * @param array<string,mixed> $input
     * @param array{header:string,base:string,alsoAcceptsTarget:bool} $spec
     * @return list<string>|ChatlySignatureResult
     */
    private static function candidateBases(array $input, string $surface, array $spec, ?string $marker): array|ChatlySignatureResult
    {
        $policy = self::policy($input);
        $fromV2 = $spec['header'] === self::V2_SIGNATURE_HEADER;
        if ($fromV2 && $marker === 'b' && ($spec['base'] === 'target' || $policy === 'require')) {
            return ChatlySignatureResult::refused(
                $surface,
                'signature_mismatch',
                'This ' . self::V2_SIGNATURE_HEADER . " is marked b=b: it was signed over <t>.<rawBody>, the workflow action's pre-unified base. "
                    . ($surface === 'unified_v2'
                        ? 'unified_v2 accepts only b=t. Verify it with surface "workflow_webhook", which accepts both.'
                        : 'v2: "require" accepts only the unified base (b=t).'),
            );
        }
        if (!$spec['alsoAcceptsTarget'] || $policy === 'ignore') {
            return [$spec['base']];
        }
        if ($marker === 't') {
            return ['target'];
        }
        if ($marker === 'b' || !self::hasTarget($input)) {
            return [$spec['base']];
        }

        // `require` on this surface means the unified base and nothing else.
        return $policy === 'require' ? ['target'] : ['target', $spec['base']];
    }

    /**
     * Both directions are refused, and the message says which. A stale
     * timestamp is a replay or a slow queue; a FAR-FUTURE one is a sender
     * clock or — far more often — the wrong unit, which lands ~54,000 years
     * out one way or ~54 the other and reads as "expired" unless something
     * says so. Exactly the tolerance is allowed (`>` not `>=`), matching the
     * producer.
     *
     * @param array<string,mixed> $input
     */
    private static function checkTolerance(array $input, string $surface, string $unit, int $signedMs): ?ChatlySignatureResult
    {
        $tolerance = $input['toleranceSeconds'] ?? null;
        // A NaN tolerance is kept, and REFUSES below (`$drift <= NAN` is
        // false) — the same answer sdk-node gives. A caller whose tolerance
        // came out NaN has a configuration bug, and quietly substituting 300
        // would hide it in this SDK alone.
        $toleranceSeconds = \is_int($tolerance) || \is_float($tolerance)
            ? $tolerance
            : self::TOLERANCE_SECONDS;
        $now = $input['nowMs'] ?? null;
        // A non-numeric `nowMs` falls back to the clock rather than poisoning
        // the comparison: in the reference a NaN drift compares false and
        // would wave the timestamp through.
        $nowMs = \is_int($now) || (\is_float($now) && is_finite($now)) ? $now : (int) floor(microtime(true) * 1000);

        // Int arithmetic; on overflow PHP promotes to float rather than
        // wrapping or throwing, which is the right answer for a comparison.
        $driftMs = abs($nowMs - $signedMs);
        if ($driftMs <= $toleranceSeconds * 1000) {
            return null;
        }
        $driftYears = $driftMs / (1000 * 60 * 60 * 24 * 365);
        $unitHint = $driftYears > 1
            ? ' That is ' . number_format($driftYears, 0, '.', '') . " year(s) out, which is what a wrong UNIT looks like: {$surface} sends {$unit}."
            : '';

        return ChatlySignatureResult::refused(
            $surface,
            'timestamp_outside_tolerance',
            "The timestamp on {$surface} is " . number_format(round($driftMs / 1000), 0, '.', '') . 's '
                . ($signedMs > $nowMs ? 'in the FUTURE' : 'in the past') . ', outside the '
                . $toleranceSeconds . "s tolerance.{$unitHint}",
        );
    }

    /** @param array<string,mixed> $input */
    private static function baseStringPrefix(string $base, ?string $rawTimestamp, array $input): string
    {
        if ($base === 'body_only') {
            return '';
        }
        // The RAW timestamp text as received — never re-formatted, so a
        // leading zero the sender signed is a leading zero here.
        $t = $rawTimestamp ?? '';
        if ($base === 'body') {
            return $t . '.';
        }

        return $t . '.' . strtoupper((string) $input['method']) . '.' . $input['path'] . '.';
    }

    /** @param array<string,mixed> $input */
    private static function hasTarget(array $input): bool
    {
        $method = $input['method'] ?? null;
        $path = $input['path'] ?? null;

        return \is_string($method) && $method !== '' && \is_string($path) && $path !== '';
    }

    /**
     * `require` and `ignore` are honoured; anything else — absent, or a value
     * this version does not know — is `prefer`, as in the reference.
     *
     * @param array<string,mixed> $input
     */
    private static function policy(array $input): string
    {
        $v2 = $input['v2'] ?? null;

        return $v2 === 'require' || $v2 === 'ignore' ? $v2 : 'prefer';
    }
}

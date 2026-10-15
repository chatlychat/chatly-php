<?php

declare(strict_types=1);

namespace Chatly;

/**
 * What {@see ChatlySignatures::verify()} decided, and why.
 *
 * ═══ WHY AN OBJECT AND NOT A BOOLEAN ══════════════════════════════════════
 *
 * {@see Webhooks::verifySignature()} returns `false` for nine distinct
 * failures, and an integrator debugging a rejected delivery cannot tell "your
 * clock is wrong" from "your secret is wrong" from "you passed the parsed
 * body". Each of those is a support ticket. This carries a stable `reason`
 * to branch on and a `message` written to be logged.
 *
 * It is the PHP shape of the TypeScript discriminated union: `ok` is the
 * discriminant, and the fields that belong to the other arm are `null`. So on
 * `ok === true`, `reason` and `message` are null and `base` / `secretIndex`
 * are set; on `ok === false` it is the other way round. `timestampMs` is null
 * on every refusal AND on an accepted `workflow_webhook_legacy` request,
 * which carries no timestamp at all — so test `ok`, never `timestampMs`.
 *
 * Immutable (readonly properties, private constructor) because a verdict a
 * later line of the handler can flip is not a verdict.
 */
final class ChatlySignatureResult
{
    /**
     * @param string      $surface     On `ok`, what actually verified — `unified_v2`
     *                                 when the v2 header was preferred over the
     *                                 surface asked for. On a refusal, the surface
     *                                 the refusal is about.
     * @param string|null $reason      One of {@see ChatlySignatures::REASONS}; null on `ok`.
     * @param string|null $message     A sentence for whoever has to fix it; null on `ok`.
     * @param int|null    $timestampMs When the sender says it signed, in unix
     *                                 MILLISECONDS whatever unit the surface sends.
     * @param string|null $base        `body`, `target` or `body_only` — which string
     *                                 the matching MAC was taken over; null on a refusal.
     * @param int|null    $secretIndex Index into the `secret` list YOU passed (0 for
     *                                 a single string) — during a rotation this is how
     *                                 you learn the old secret is no longer used.
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $surface,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly ?int $timestampMs,
        public readonly ?string $base,
        public readonly ?int $secretIndex,
    ) {
    }

    /** @internal Constructed by {@see ChatlySignatures} only. */
    public static function accepted(string $surface, ?int $timestampMs, string $base, int $secretIndex): self
    {
        return new self(true, $surface, null, null, $timestampMs, $base, $secretIndex);
    }

    /** @internal Constructed by {@see ChatlySignatures} only. */
    public static function refused(string $surface, string $reason, string $message): self
    {
        return new self(false, $surface, $reason, $message, null, null, null);
    }

    /**
     * The same verdict re-labelled with a different surface and message.
     *
     * Used once: a refusal from the delegated `unified_v2` verification is
     * reported AS `unified_v2`, with a sentence explaining why the surface the
     * caller asked for is not the one named.
     *
     * @internal
     */
    public function withSurfaceAndMessage(string $surface, string $message): self
    {
        return new self($this->ok, $surface, $this->reason, $message, $this->timestampMs, $this->base, $this->secretIndex);
    }
}

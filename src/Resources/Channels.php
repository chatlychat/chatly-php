<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Channels — the configured mediums a workspace talks on (web, email,
 * WhatsApp, SMS, …).
 *
 * `$chatly->channels->…`
 *
 * ─── WRITEABLE, AND THIS DOCBLOCK USED TO DENY IT ────────────────────────
 *
 * It said channel configuration "needs `channels:write`, which no
 * key-accessible route grants", and there was no create or update here as a
 * result. Both halves were wrong: `channels:write` **is** in
 * `API_KEY_SCOPES`, and it was put there deliberately so a developer can
 * stand a widget up entirely from code. {@see create()},
 * {@see update()} and {@see rotateSecret()} all carry `@AllowApiKey()`.
 *
 * `channels:write` is the widest scope a key can hold, so hold it
 * deliberately. **`DELETE /v1/channels/{id}` is the one write that stays
 * dashboard-only** — it carries no `@AllowApiKey()` at all — which is why
 * there is still no `delete()` here and why the suite asserts there never
 * is: a method that always 403s is worse than a missing one.
 */
final class Channels extends AbstractResource
{
    /**
     * `GET /v1/channels` — every configured channel.
     *
     * A plain array, not a page: the list is bounded by the workspace's plan
     * limit.
     *
     * Each row carries `originsRestricted`, which is the field to check for a
     * web channel: an EMPTY allowed-origins list means "any origin", and that
     * is invisible if you only look at the list itself.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/channels');
    }

    /** `GET /v1/channels/{id}` — one channel. */
    public function get(string $id): mixed
    {
        return $this->client->request('GET', '/v1/channels/' . self::seg($id));
    }

    /**
     * `GET /v1/channels/adapters` — the channel types this install supports.
     *
     * **A bare array of STRINGS**, not of objects — the only route on this
     * SDK's surface shaped that way, so `$adapters[0]` is `'web'` and not
     * `['type' => 'web']`. A bounded in-process registry; check a type
     * against this before sending it to {@see create()}, which refuses an
     * unknown one with a 400.
     */
    public function adapters(): mixed
    {
        return $this->httpGet('/v1/channels/adapters');
    }

    /**
     * `POST /v1/channels` — create a channel.
     *
     * Subject to the plan's channel limit (`@PlanLimit`), so this is one of
     * the few writes that can fail for billing reasons rather than bad input.
     *
     * ─── AN EMPTY `allowedOrigins` ON A WEB CHANNEL MEANS *ANY* ORIGIN ────
     *
     * Not "none". A web channel created without that field will serve the
     * widget to anybody who embeds it. The list rows carry
     * `originsRestricted` precisely because the distinction is invisible if
     * you only look at the array.
     *
     * @param array<string,mixed> $channel `type` (required — one of
     *                                     {@see adapters()}) and `name`
     *                                     (required, ≤120); `brandId`, plus
     *                                     the per-type `config`.
     */
    public function create(array $channel): mixed
    {
        return $this->httpPost('/v1/channels', $channel);
    }

    /**
     * `PATCH /v1/channels/{id}` — rename, enable/disable, change help-desk
     * behaviour.
     *
     * A narrow patch surface by design: `name`, `enabled`, and the help-desk
     * columns (`autoCreateTickets`, `autoAcknowledge` and its template). It
     * does **not** patch `config` — that goes through {@see create()}'s
     * upsert, which avoids the merge-or-replace question a partial `config`
     * would raise.
     *
     * `autoAcknowledge` is guarded twice at send time — never answer a robot,
     * never twice to one contact inside the cooldown — so switching it on
     * cannot create a mail loop. A null acknowledgement template restores the
     * built-in copy.
     *
     * @param array<string,mixed> $changes
     */
    public function update(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/channels/' . self::seg($id), $changes);
    }

    /**
     * `POST /v1/channels/{id}/secret/rotate` — new inbound webhook secret.
     *
     * The value comes back once here, but unlike most secrets on this API it
     * is **re-readable** afterwards at `GET /v1/channels/{id}/secret` — so
     * losing this response is recoverable.
     *
     * Note this route declares `@NoIdempotency()`, overriding its class's
     * `@RequireIdempotencyKey()`: the handler wins over the class. This SDK
     * sends a key anyway, which is honoured and never demanded.
     *
     * Takes no body.
     */
    public function rotateSecret(string $id): mixed
    {
        return $this->httpPost('/v1/channels/' . self::seg($id) . '/secret/rotate');
    }
}

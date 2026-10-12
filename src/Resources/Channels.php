<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Channels — the configured mediums a workspace talks on (web, email,
 * WhatsApp, SMS, …).
 *
 * `$chatly->channels->…`
 *
 * Read-only from an API key. Channel CONFIGURATION needs `channels:write`,
 * which no key-accessible route grants, so there is no create or update
 * method here — one would fail at runtime in someone else's integration,
 * which is worse than not existing.
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
}

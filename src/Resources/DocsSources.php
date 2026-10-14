<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Documentation sources — a KB fed from docs you already maintain elsewhere.
 *
 * `$chatly->docsSources->…`
 *
 * Connect a space on a documentation provider and its pages become KB
 * articles that the help centre serves and RAG retrieves over. The articles
 * are ours; the source of truth stays yours, and a sync brings changes
 * across.
 *
 * No response on this resource carries the provider token back, on any route.
 */
final class DocsSources extends AbstractResource
{
    /**
     * `GET /v1/docs-sources/providers` — what can be connected.
     *
     * A bare array, a static registry. Each row carries a flag for whether the
     * provider runs on the customer's own server, which decides whether
     * `baseUrl` is needed later. Build a connect form from this rather than
     * hard-coding provider names.
     */
    public function providers(): mixed
    {
        return $this->httpGet('/v1/docs-sources/providers');
    }

    /**
     * `GET /v1/docs-sources` — connected sources.
     *
     * A bare array, `@PaginationExempt` — a workspace connects a handful.
     * Each row carries `syncStatus` and the article counts from its last run.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/docs-sources');
    }

    /**
     * `POST /v1/docs-sources/discover` — spaces this token can reach.
     *
     * The picker step: call it with a token and get back the spaces, then
     * {@see create()} one of them. Answers a **bare array** with a 201 (Nest's
     * default for a POST), and connects nothing — it is a read wearing a POST
     * because the token does not belong in a query string.
     *
     * ─── `organizationId` IS NOT ALWAYS OPTIONAL IN PRACTICE ──────────────
     *
     * An ORG-SCOPED token cannot list organisations, so discovery without it
     * fails the whole connect flow with an error naming a path the operator
     * never asked about. An account-wide token without it gets every space in
     * every organisation, which is usually what you want. If discovery fails
     * oddly, pass the org.
     *
     * @param array<string,mixed> $request `provider` and `token` (required);
     *                                     `baseUrl` for a self-hosted
     *                                     provider, `organizationId` to
     *                                     narrow.
     */
    public function discover(array $request): mixed
    {
        return $this->httpPost('/v1/docs-sources/discover', $request);
    }

    /**
     * `POST /v1/docs-sources` — connect a space.
     *
     * The first sync is enqueued **before this returns**, so the source comes
     * back with `syncStatus: 'queued'` and no counts yet. Poll
     * {@see list()} rather than treating the 201 as "the articles are there".
     *
     * ─── `pathPrefix` IS HOW PER-AUDIENCE HELP IS EXPRESSED ───────────────
     *
     * Two sources over ONE space with different prefixes get their own
     * collections — and since a channel's help scoping can only point at
     * collections, splitting a space is the only way to put the right pages
     * in front of the right audience. Supported where the provider has a path
     * worth slicing.
     *
     * @param array<string,mixed> $source `provider`, `token`,
     *                                    `organizationId`, `spaceId`
     *                                    (required); `baseUrl`, `label`,
     *                                    `publishOnSync`, `pathPrefix`, and
     *                                    `syncIntervalMinutes` — floored at
     *                                    15 because that matches the scan
     *                                    cadence, and a shorter interval
     *                                    could not be honoured and would only
     *                                    look like it was.
     */
    public function create(array $source): mixed
    {
        return $this->httpPost('/v1/docs-sources', $source);
    }

    /**
     * `PATCH /v1/docs-sources/{id}` — rename, pause, re-schedule, or rotate
     * the token.
     *
     * A replacement `token` is **verified against the provider before it is
     * stored**, so a bad one is a 400 here rather than a sync that starts
     * failing at 3am. No field in the response carries the token back.
     *
     * @param array<string,mixed> $changes `label`, `status` (`active` or
     *                                     `paused`), `publishOnSync`,
     *                                     `syncIntervalMinutes` (15–10,080),
     *                                     `token`.
     */
    public function update(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/docs-sources/' . self::seg($id), $changes);
    }

    /**
     * `POST /v1/docs-sources/{id}/sync` — sync now.
     *
     * Enqueues the same job the scheduler runs, so this is a nudge rather
     * than a different code path. Answers that it was QUEUED — the articles
     * are not updated when this returns.
     *
     * @param bool|null $full Null (the default) sends no flag and lets the
     *                        server decide, which normally means skipping
     *                        spaces that have not changed. Pass `true` to
     *                        re-read everything, which is what you want after
     *                        changing `pathPrefix`.
     */
    public function sync(string $id, ?bool $full = null): mixed
    {
        return $this->httpPost(
            '/v1/docs-sources/' . self::seg($id) . '/sync',
            $full === null ? [] : ['full' => $full],
        );
    }

    /**
     * `POST /v1/docs-sources/{id}/webhook` — subscribe to the provider's
     * change events, so edits arrive without waiting for the next scan.
     *
     * ─── A REFUSAL COMES BACK AS A 201, NOT AN ERROR ──────────────────────
     *
     * `subscribed: false` with a reason is the common case and a **success**:
     * a read-only token cannot manage webhooks, and that is a fact to report
     * rather than a failure to raise. **Read the flag.** A 201 here does not
     * mean you are subscribed.
     *
     * Takes no body.
     */
    public function webhook(string $id): mixed
    {
        return $this->httpPost('/v1/docs-sources/' . self::seg($id) . '/webhook');
    }

    /**
     * `DELETE /v1/docs-sources/{id}` — disconnect, KEEPING the articles.
     *
     * Nothing is deleted. `kept` in the response is how many articles stayed
     * behind as ordinary KB articles, now editable here and no longer
     * overwritten by a sync. Answers 200 with that body, not a 204.
     */
    public function delete(string $id): mixed
    {
        return $this->httpDelete('/v1/docs-sources/' . self::seg($id));
    }
}

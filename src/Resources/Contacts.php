<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Contacts — the people your agents talk to.
 *
 * `$chatly->contacts->…`
 */
final class Contacts extends AbstractResource
{
    /**
     * `GET /v1/contacts` — one page, newest first.
     *
     * Blocked contacts are INCLUDED by default: a block is a flag, not a
     * deletion, and hiding them is how an operator ends up unable to find the
     * person they need to unblock. Pass `['blocked' => false]` to exclude
     * them, or `true` for only those.
     *
     * @param array<string,mixed> $query `cursor`, `limit` (1–100), `blocked`.
     */
    public function list(array $query = []): Page
    {
        return $this->httpPage('/v1/contacts', $query);
    }

    /**
     * Every contact, across pages.
     *
     * ```php
     * foreach ($chatly->contacts->autoList(['limit' => 100]) as $contact) {
     *     echo $contact['email'] ?? '(no email)', PHP_EOL;
     * }
     * ```
     *
     * @param array<string,mixed> $query    As {@see list()}.
     * @param int|null            $maxPages Stop early. Null walks to the end.
     *
     * @return \Generator<int,mixed>
     */
    public function autoList(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/contacts', $query, $maxPages);
    }

    /** `GET /v1/contacts/{id}` — one contact. */
    public function get(string $id): mixed
    {
        return $this->client->request('GET', '/v1/contacts/' . self::seg($id));
    }

    /**
     * `POST /v1/contacts` — create or update, keyed on `externalId`.
     *
     * An upsert: sending the same `externalId` twice updates rather than
     * duplicating. Recognised fields include `externalId`, `email`, `phone`,
     * `name` and `attributes` (a free-form object).
     *
     * @param array<string,mixed> $contact
     */
    public function upsert(array $contact): mixed
    {
        return $this->httpPost('/v1/contacts', $contact);
    }

    /**
     * `POST /v1/contacts/bulk` — up to 5000 rows in one request.
     *
     * Each row is upserted by `externalId`. The response carries per-row
     * outcome counts plus up to 100 row errors, so a partial failure is
     * reported rather than thrown: **check the response**, do not assume a
     * 200 means every row landed.
     *
     * @param array<int,array<string,mixed>> $contacts A list of contact objects.
     */
    public function bulkUpsert(array $contacts): mixed
    {
        return $this->httpPost('/v1/contacts/bulk', ['contacts' => array_values($contacts)]);
    }

    /**
     * `POST /v1/contacts/{id}/merge` — fold `$mergedId` into `$id`.
     *
     * Irreversible. `$id` survives and keeps the merged contact's history;
     * `$mergedId` is consumed.
     */
    public function merge(string $id, string $mergedId): mixed
    {
        return $this->httpPost(
            '/v1/contacts/' . self::seg($id) . '/merge',
            ['mergedId' => $mergedId],
        );
    }

    /**
     * `POST /v1/contacts/{id}/tags` — add ONE tag.
     *
     * The plural name is the cross-SDK surface name; the route takes a single
     * tag per call, as the signature says. Call it once per tag — each call
     * carries its own idempotency key, so a failure part-way through leaves
     * the tags that succeeded applied and is safe to repeat.
     */
    public function addTags(string $id, string $tag): mixed
    {
        return $this->httpPost(
            '/v1/contacts/' . self::seg($id) . '/tags',
            ['tag' => $tag],
        );
    }

    /**
     * `DELETE /v1/contacts/{id}/tags/{tag}` — remove one tag.
     *
     * The tag travels as a path segment and is percent-encoded here, so a tag
     * with a space or an accent matches the one stored.
     */
    public function removeTag(string $id, string $tag): mixed
    {
        return $this->httpDelete(
            '/v1/contacts/' . self::seg($id) . '/tags/' . self::seg($tag, 'tag'),
        );
    }

    /**
     * `POST /v1/contacts/{id}/unsubscribe` — record a contact's outbound
     * preference.
     *
     * **OUTBOUND only.** An unsubscribed contact can still open a
     * conversation and still lands in the inbox; this stops campaigns and
     * proactive messages, it is not a block.
     *
     * `$unsubscribed` is sent explicitly, so `false` re-subscribes. The API
     * requires the field — a bodyless call to this route is a 400.
     */
    public function unsubscribe(string $id, bool $unsubscribed = true, ?string $reason = null): mixed
    {
        $body = ['unsubscribed' => $unsubscribed];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return $this->httpPost('/v1/contacts/' . self::seg($id) . '/unsubscribe', $body);
    }

    /**
     * `GET /v1/contacts/{id}/journey` — one page of a contact's timeline.
     *
     * Merges CDP events, conversations and contact changes into one stream,
     * newest first. Returns the whole `{items, nextCursor}` envelope.
     *
     * @param array<string,mixed> $query `days` (1–365, default 90), `limit`
     *                                   (1–100, default 25), `cursor`.
     */
    public function journey(string $id, array $query = []): mixed
    {
        return $this->client->request('GET', '/v1/contacts/' . self::seg($id) . '/journey', $query);
    }

    /**
     * The whole timeline, across pages.
     *
     * ─── THE CURSOR IS AN INSTANT, AND IT IS STILL A CURSOR ───────────────
     *
     * `nextCursor` here is the `occurredAt` of the last item returned — an
     * ISO-8601 timestamp, not the opaque base64 keyset the list endpoints
     * hand back (`journeyQuerySchema` declares
     * `cursor: z.string().datetime({ offset: true })`, and the service reads
     * `< cursor` to advance strictly into older items).
     *
     * **Walk it; never construct one.** A timestamp you picked is not a
     * cursor: it skips every item that shares a boundary instant, and an
     * offset the schema will not parse is a 400. Pass it back exactly as
     * received, which is what this does — including the percent-encoding a
     * `+00:00` offset needs, since a bare `+` in a query string decodes to a
     * space and the datetime parse then fails.
     *
     * ```php
     * foreach ($chatly->contacts->autoJourney($contactId, ['days' => 365]) as $moment) {
     *     echo $moment['occurredAt'], ' ', $moment['kind'] ?? '', PHP_EOL;
     * }
     * ```
     *
     * @param array<string,mixed> $query    As {@see journey()}.
     * @param int|null            $maxPages Stop early. Null walks to the end.
     *
     * @return \Generator<int,mixed>
     */
    public function autoJourney(string $id, array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/contacts/' . self::seg($id) . '/journey', $query, $maxPages);
    }
}

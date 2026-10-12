<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * CDP events — what your product knows about a contact's behaviour.
 *
 * `$chatly->events->…`
 */
final class Events extends AbstractResource
{
    /**
     * `POST /v1/cdp/events` — record one event against a contact.
     *
     * ```php
     * $chatly->events->track($contactId, 'order_placed', ['total' => 4999, 'currency' => 'EUR']);
     * ```
     *
     * This is the call customers copy into every service they run, so it has
     * a scope of its own: `contacts:write:events`. Issue keys with that scope
     * rather than `contacts:write`, which also reaches
     * `POST /v1/contacts/{id}/merge` and destroys a contact row irreversibly.
     * An existing key holding `contacts:write` still works — the broader
     * scope implies this one.
     *
     * @param array<string,mixed> $properties Free-form; a flat object is safest.
     */
    public function track(string $contactId, string $name, array $properties = []): mixed
    {
        return $this->httpPost('/v1/cdp/events', [
            'contactId' => $contactId,
            'name' => $name,
            'properties' => $properties,
        ]);
    }

    /**
     * `GET /v1/cdp/events` — read back what was recorded, newest first.
     *
     * The answer to "did my tracking call actually land?". Pass
     * `['contactId' => …]` to scope the stream to one person.
     *
     * @param array<string,mixed> $query `contactId`, `cursor`, `limit`,
     *                                   `filter[]`, `sort`, `fields`.
     */
    public function list(array $query = []): Page
    {
        return $this->httpPage('/v1/cdp/events', $query);
    }

    /**
     * Every event, across pages.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function autoList(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/cdp/events', $query, $maxPages);
    }
}

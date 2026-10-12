<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\ChatlyClient;
use Chatly\Pagination\Page;

/**
 * Shared plumbing for the resource namespaces.
 *
 * Every method here is a thin pass-through to {@see ChatlyClient}, so a
 * resource reads as a list of routes and nothing else. Nothing in this class
 * decides anything.
 */
abstract class AbstractResource
{
    public function __construct(protected readonly ChatlyClient $client)
    {
    }

    /** @param array<string,mixed> $query */
    protected function httpGet(string $path, array $query = []): mixed
    {
        return $this->client->request('GET', $path, $query);
    }

    /** @param array<string,mixed> $query */
    protected function httpPage(string $path, array $query = []): Page
    {
        return $this->client->requestPage($path, $query);
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    protected function httpWalk(string $path, array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->client->paginate($path, $query, $maxPages);
    }

    /** @param array<string,mixed> $query */
    protected function httpPost(string $path, mixed $body = null, array $query = []): mixed
    {
        return $this->client->request('POST', $path, $query, $body);
    }

    protected function httpPatch(string $path, mixed $body = null): mixed
    {
        return $this->client->request('PATCH', $path, [], $body);
    }

    protected function httpPut(string $path, mixed $body = null): mixed
    {
        return $this->client->request('PUT', $path, [], $body);
    }

    /** @param array<string,mixed> $query */
    protected function httpDelete(string $path, array $query = []): mixed
    {
        return $this->client->request('DELETE', $path, $query);
    }

    /**
     * One path segment, percent-encoded.
     *
     * `rawurlencode`, not `urlencode`: the latter encodes a space as `+`,
     * which is a literal plus inside a path. Tags are user-typed and reach
     * `DELETE /v1/contacts/{id}/tags/{tag}` as a segment, so this is load-
     * bearing — the API `decodeURIComponent`s it on the way in.
     *
     * An empty segment is refused rather than encoded. `'/v1/contacts/' . ''`
     * is `/v1/contacts/`, which routes to the COLLECTION: a `delete()` meant
     * for one row would be aimed at the list, and a `get()` would silently
     * return a page where the caller expected an object.
     */
    protected static function seg(string $value, string $what = 'id'): string
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('Chatly: %s must not be empty', $what));
        }

        return rawurlencode($value);
    }

    /**
     * An instant in the format the API's schemas accept.
     *
     * `z.string().datetime()` with no `offset` option — which is what
     * `sendAt` is validated by — accepts **only** a UTC `Z` suffix and
     * rejects `+02:00`. `DateTimeInterface::format(DATE_ATOM)` on a non-UTC
     * object produces an offset and a 400, so convert first.
     */
    protected static function instant(\DateTimeInterface|string $when): string
    {
        if (\is_string($when)) {
            return $when;
        }

        return (new \DateTimeImmutable('@' . $when->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}

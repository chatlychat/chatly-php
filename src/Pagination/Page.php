<?php

declare(strict_types=1);

namespace Chatly\Pagination;

/**
 * One page of a list endpoint, exactly as the API returned it.
 *
 * `foreach ($page as $row)` walks this page's items. For every page without
 * writing the loop, use the matching `auto…()` method, which returns a
 * `Generator`.
 *
 * ─── NEVER PAGE ON `id` ───────────────────────────────────────────────────
 *
 * Ids in this product are a mix of UUID v4 and v7, so `id` order is not time
 * order and `WHERE id > …` skips rows. `nextCursor` is the only correct
 * sequence. Lists are returned newest-first by their own timestamp.
 *
 * @implements \IteratorAggregate<int,mixed>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param array<int,mixed>    $items      This page's rows.
     * @param string|null         $nextCursor Pass as `cursor` to get the next
     *                                        page; null on the last page.
     * @param array<string,mixed> $raw        The whole decoded body, so nothing
     *                                        the API sends is lost here — some
     *                                        endpoints add `hasMore` or
     *                                        counters alongside `items`.
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Read a decoded response body into a page.
     *
     * Tolerant on purpose, in three directions that all occur on this API:
     *
     *  * `{"items":[…],"nextCursor":null}` — the documented shape.
     *  * `{"items":[…],"next_cursor":…}` — accepted so a snake_case
     *    deployment or proxy rewrite does not silently paginate once and stop.
     *  * a bare JSON array — several routes are deliberately unpaginated
     *    (`GET /v1/kb/collections`, `/v1/channels`, `/v1/labels`) and return
     *    a plain list. Those become a single page with a null cursor, so the
     *    same code reads both.
     */
    public static function fromDecoded(mixed $decoded): self
    {
        if (!\is_array($decoded)) {
            return new self([], null, []);
        }

        // A bare list: `array_is_list` distinguishes `[…]` from `{…}` after
        // json_decode(…, true) has flattened both into PHP arrays.
        if (array_is_list($decoded)) {
            return new self($decoded, null, ['items' => $decoded]);
        }

        $items = [];
        if (isset($decoded['items']) && \is_array($decoded['items'])) {
            $items = array_values($decoded['items']);
        }

        $cursor = $decoded['nextCursor'] ?? $decoded['next_cursor'] ?? null;
        if (!\is_string($cursor) || $cursor === '') {
            // Anything that is not a usable cursor string — null, false, 0,
            // an object — means "no next page". Coercing a non-string to a
            // string here would send `cursor=1` and get a 400 or, worse, a
            // first page forever.
            $cursor = null;
        }

        return new self($items, $cursor, $decoded);
    }

    /**
     * The API's own `hasMore`, when the endpoint sends one.
     *
     * Informational. `nextCursor` decides whether to fetch again — `hasMore`
     * is absent on several routes, and a missing boolean is not `false`.
     */
    public function hasMore(): ?bool
    {
        $value = $this->raw['hasMore'] ?? $this->raw['has_more'] ?? null;

        return \is_bool($value) ? $value : null;
    }

    public function isLastPage(): bool
    {
        return $this->nextCursor === null;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}

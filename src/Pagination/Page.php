<?php

declare(strict_types=1);

namespace Chatly\Pagination;

use Chatly\Errors\ChatlyException;

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
     * Requires a cursor envelope: an `items` LIST inside a JSON object.
     * `nextCursor` may be absent or null — that is the last page — and
     * `next_cursor` is accepted beside it, so a snake_case deployment or a
     * proxy rewrite does not silently paginate once and stop.
     *
     * ─── A NON-PAGE BODY IS AN ERROR, NOT A ONE-ROW PAGE ──────────────────
     *
     * This used to accept a bare JSON array and hand back a single page
     * holding its rows, on the reasoning that several routes are deliberately
     * unpaginated (`GET /v1/kb/collections`, `/v1/channels`, `/v1/labels`)
     * and "the same code reads both". No method in this SDK ever took that
     * path — every unpaginated route returns its array through
     * `request()` — so the tolerance only ever applied to a body the caller
     * did NOT expect, and there it was the bug it looked like a convenience
     * for. A `GET /v1/contacts` that answered a bare array would become a
     * page with no cursor: a walk stops after one page and reports success,
     * and the caller cannot tell a complete result from a truncated one. It
     * is the same failure the Rust client shipped, where a derived
     * deserializer read a bare array as an EMPTY page and returned it with a
     * 200 — a wrong answer rather than a missing one.
     *
     * To read a genuinely unpaginated route as a page, say so:
     * {@see fromList()}.
     *
     * @param mixed  $decoded The decoded response body.
     * @param string $context The route this body came from, named in the
     *                        error so a reader knows WHICH endpoint
     *                        misbehaved and not merely that a shape was
     *                        wrong. Empty when the caller has no path to
     *                        hand over.
     *
     * @throws ChatlyException When the body is not a cursor envelope.
     */
    public static function fromDecoded(mixed $decoded, string $context = ''): self
    {
        $where = $context === '' ? '' : ' from ' . $context;

        if (!\is_array($decoded)) {
            throw self::notAPage(
                sprintf('a %s body%s cannot be read as a page', get_debug_type($decoded), $where),
                $decoded,
            );
        }

        /*
         * A bare list. `array_is_list` is the discriminator because
         * `json_decode(…, true)` flattens `[…]` and `{…}` into the same PHP
         * type, so `is_array()` cannot tell a page from a plain list.
         */
        if (array_is_list($decoded) && $decoded !== []) {
            throw self::notAPage(
                sprintf(
                    'expected a page envelope%s but the API sent a bare JSON array of %d row(s). '
                    . 'If the route is deliberately unpaginated, read it with request() — or with '
                    . 'Page::fromList() to wrap it in a page on purpose.',
                    $where,
                    \count($decoded),
                ),
                $decoded,
            );
        }

        $items = $decoded['items'] ?? null;
        if (!\is_array($items) || !array_is_list($items)) {
            /*
             * `[]` is the one ambiguous body: an empty JSON array and an
             * empty JSON object both decode to it, so it is named as either
             * rather than guessed at. Both are equally not a page.
             */
            $got = match (true) {
                $decoded === [] => 'an empty `[]` or `{}`',
                $items === null => 'an object with no `items` key',
                default => 'an `items` that is not a list',
            };
            throw self::notAPage(
                sprintf(
                    'expected a page envelope%s with an `items` list, got %s. A body with no `items` '
                    . 'is not a page, and treating it as an empty one would report a truncated walk '
                    . 'as a complete one.',
                    $where,
                    $got,
                ),
                $decoded,
            );
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
     * Wrap a genuinely unpaginated route's array in a single page.
     *
     * The deliberate version of what {@see fromDecoded()} used to do by
     * accident. `GET /v1/kb/collections`, `/v1/channels`, `/v1/labels` and
     * `/v1/macros` send a plain list and never a cursor, so the first page IS
     * the answer; this exists for code that wants to read one of those
     * through the same `Page` interface as a paged route.
     *
     * The cursor is null and `isLastPage()` is true, which is honest here and
     * was a lie on a route that does paginate — which is the whole reason the
     * two are now separate calls.
     *
     * @param array<int,mixed> $rows
     */
    public static function fromList(array $rows): self
    {
        if (!array_is_list($rows)) {
            throw new \InvalidArgumentException(
                'Page::fromList expects a list of rows. A keyed array is an object, not an '
                . 'unpaginated collection — use Page::fromDecoded() for a page envelope.',
            );
        }

        return new self($rows, null, ['items' => $rows]);
    }

    /**
     * A 2xx whose body is not the shape the caller asked to read.
     *
     * A `ChatlyException` rather than a `LogicException`: the API said
     * something unexpected, which is the same class of failure as the
     * `invalid_json` the client raises for a 2xx that is not JSON at all, and
     * a caller already catching `ChatlyException` around a walk should catch
     * this too. Status 200 because the response WAS a success — it is the
     * body that is wrong — and the decoded body is preserved in
     * `getBody()`.
     */
    private static function notAPage(string $message, mixed $decoded): ChatlyException
    {
        return new ChatlyException(
            'Chatly: ' . $message,
            200,
            'unexpected_response_shape',
            null,
            (string) json_encode($decoded, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
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

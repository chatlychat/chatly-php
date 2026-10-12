<?php

declare(strict_types=1);

namespace Chatly\Pagination;

/**
 * Walks every page of a cursor-paginated list.
 *
 * The engine behind each resource's `auto…()` method; use those unless you
 * need the pages themselves rather than the rows.
 *
 * ```php
 * foreach ($chatly->contacts->autoList(['limit' => 100]) as $contact) { … }
 * ```
 *
 * ─── IT CANNOT SPIN FOREVER ───────────────────────────────────────────────
 *
 * Two stop conditions, and the second one is the point. It stops on a null
 * cursor, which is the normal end — and it stops when the server hands back a
 * cursor it has already followed. A server bug that echoes the same cursor
 * forever would otherwise be an infinite loop inside a `foreach`, fetching
 * the same page until the process is killed; the SDK's job is to make that a
 * truncated result, not an outage. `maxPages` is a third, caller-chosen
 * bound.
 *
 * @implements \IteratorAggregate<int,mixed>
 */
final class Paginator implements \IteratorAggregate
{
    /** @var \Closure(array<string,mixed>):Page */
    private \Closure $fetch;

    /**
     * @param \Closure(array<string,mixed>):Page $fetch    Fetches one page for a query.
     * @param array<string,mixed>                $query    Starting query. `cursor` is managed here.
     * @param int|null                           $maxPages Stop after this many pages. Null = no bound.
     */
    public function __construct(\Closure $fetch, private readonly array $query = [], private readonly ?int $maxPages = null)
    {
        if ($maxPages !== null && $maxPages < 1) {
            throw new \InvalidArgumentException('Paginator: maxPages must be at least 1 when given');
        }
        $this->fetch = $fetch;
    }

    /**
     * Every page, in order.
     *
     * @return \Generator<int,Page>
     */
    public function pages(): \Generator
    {
        $query = $this->query;
        unset($query['cursor']);
        if (isset($this->query['cursor']) && \is_string($this->query['cursor']) && $this->query['cursor'] !== '') {
            // An explicit starting cursor is honoured — resuming a walk is a
            // real use for this.
            $query['cursor'] = $this->query['cursor'];
        }

        /** @var array<string,true> $seen */
        $seen = [];
        $pageNumber = 0;

        while (true) {
            $page = ($this->fetch)($query);
            ++$pageNumber;
            yield $page;

            $cursor = $page->nextCursor;
            if ($cursor === null) {
                return;
            }
            if (isset($seen[$cursor])) {
                return;
            }
            if ($this->maxPages !== null && $pageNumber >= $this->maxPages) {
                return;
            }
            $seen[$cursor] = true;
            $query['cursor'] = $cursor;
        }
    }

    /**
     * Every row across every page.
     *
     * @return \Generator<int,mixed>
     */
    public function items(): \Generator
    {
        foreach ($this->pages() as $page) {
            foreach ($page->items as $item) {
                yield $item;
            }
        }
    }

    /** @return \Generator<int,mixed> */
    public function getIterator(): \Generator
    {
        yield from $this->items();
    }
}

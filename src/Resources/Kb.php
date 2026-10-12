<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Knowledge base — help-centre articles and their collections.
 *
 * `$chatly->kb->…`
 */
final class Kb extends AbstractResource
{
    /**
     * `GET /v1/kb/articles` — one page, drafts included.
     *
     * This is the authoring view: it returns articles whatever their status.
     * Narrow it with the standard filter surface —
     * `['filter[]' => ['status:eq:published']]`.
     *
     * @param array<string,mixed> $query `cursor`, `limit`, `filter[]`, `sort`, `fields`.
     */
    public function articles(array $query = []): Page
    {
        return $this->httpPage('/v1/kb/articles', $query);
    }

    /**
     * Every article, across pages.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function autoArticles(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/kb/articles', $query, $maxPages);
    }

    /**
     * `GET /v1/kb/articles/{slug}` — one article by slug, whatever its status.
     *
     * By SLUG, not by id. The slug is percent-encoded here.
     */
    public function article(string $slug): mixed
    {
        return $this->client->request('GET', '/v1/kb/articles/' . self::seg($slug, 'slug'));
    }

    /**
     * `GET /v1/kb/collections` — the whole taxonomy.
     *
     * Returns a plain JSON array, not a page: collections are a small
     * taxonomy (typically under 50) and the route is deliberately
     * unpaginated.
     */
    public function collections(): mixed
    {
        return $this->httpGet('/v1/kb/collections');
    }
}

<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlyClient;
use Chatly\Pagination\Page;
use Chatly\Pagination\Paginator;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;

/** CONTRACT §5 (pagination) and §10.6. */
final class PaginationCases
{
    private static function client(FakeTransport $transport): ChatlyClient
    {
        return new ChatlyClient('ck_test', transport: $transport);
    }

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            '§10.6 the auto-paginator walks two pages and stops on a null cursor' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [['id' => 'a'], ['id' => 'b']], 'nextCursor' => 'cur_2']);
                $transport->push(200, ['items' => [['id' => 'c']], 'nextCursor' => null]);

                $ids = [];
                foreach (self::client($transport)->contacts->autoList(['limit' => 2]) as $contact) {
                    $ids[] = $contact['id'];
                }

                Assert::same(['a', 'b', 'c'], $ids, 'every row from both pages, in order');
                Assert::same(2, $transport->count(), 'exactly two requests: a null cursor ends the walk');
                Assert::same('limit=2', $transport->query(0), 'the first page carries no cursor');
                Assert::same('limit=2&cursor=cur_2', $transport->query(1), 'the second carries the first page\'s cursor');
            },

            '§5 a repeated cursor stops the walk instead of looping forever' => static function (): void {
                $transport = new FakeTransport();
                // A server bug: the same cursor, again and again. Without a
                // guard this is an infinite loop inside a foreach.
                $transport->pushRepeated(5, 200, ['items' => [['id' => 'a']], 'nextCursor' => 'stuck']);

                $seen = 0;
                foreach (self::client($transport)->contacts->autoList() as $_row) {
                    ++$seen;
                    if ($seen > 10) {
                        throw new \RuntimeException('the paginator did not stop on a repeated cursor');
                    }
                }

                Assert::same(2, $seen, 'the page is yielded once, then the repeat is recognised and the walk ends');
                Assert::same(2, $transport->count(), 'and only two requests were made');
            },

            '§5 maxPages bounds the walk' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [['id' => 'a']], 'nextCursor' => 'c2']);
                $transport->push(200, ['items' => [['id' => 'b']], 'nextCursor' => 'c3']);

                $ids = [];
                foreach (self::client($transport)->contacts->autoList([], maxPages: 2) as $row) {
                    $ids[] = $row['id'];
                }

                Assert::same(['a', 'b'], $ids, 'two pages, then stop');
                Assert::same(2, $transport->count(), 'the third page was never fetched');
            },

            '§5 an empty cursor string is treated as the last page' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [['id' => 'a']], 'nextCursor' => '']);

                $count = 0;
                foreach (self::client($transport)->contacts->autoList() as $_row) {
                    ++$count;
                }

                Assert::same(1, $count, 'one row');
                Assert::same(1, $transport->count(), 'sending `cursor=` would be a 400 or a first page forever');
            },

            '§5 a non-string cursor is not coerced into one' => static function (): void {
                $page = Page::fromDecoded(['items' => [['id' => 'a']], 'nextCursor' => 0]);
                Assert::same(null, $page->nextCursor, 'a 0 cursor must mean "no next page", not `cursor=0`');
                Assert::true($page->isLastPage(), 'and the page knows it is the last');
            },

            '§5 a bare JSON array becomes a single page' => static function (): void {
                $transport = new FakeTransport();
                // `GET /v1/kb/collections`, `/v1/channels` and `/v1/labels`
                // are deliberately unpaginated and return a plain list.
                $transport->push(200, [['id' => 'col_1'], ['id' => 'col_2']]);
                $collections = self::client($transport)->kb->collections();

                Assert::same(2, \count($collections), 'the array comes back as given');

                $page = Page::fromDecoded([['id' => 'col_1']]);
                Assert::same(1, \count($page->items), 'and reading one as a page yields its rows');
                Assert::same(null, $page->nextCursor, 'with no next page');
            },

            '§5 a single page exposes items, cursor, hasMore and the raw body' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, [
                    'items' => [['id' => 'a']],
                    'nextCursor' => 'cur_2',
                    'hasMore' => true,
                ]);
                $page = self::client($transport)->tickets->list();

                Assert::same(1, \count($page->items), 'items');
                Assert::same('cur_2', $page->nextCursor, 'cursor');
                Assert::same(true, $page->hasMore(), 'the API\'s own hasMore is surfaced');
                Assert::false($page->isLastPage(), 'and it is not the last page');
                Assert::same(true, $page->raw['hasMore'] ?? null, 'the whole decoded body is kept');

                $ids = [];
                foreach ($page as $row) {
                    $ids[] = $row['id'];
                }
                Assert::same(['a'], $ids, 'a page is iterable on its own');
            },

            '§5 hasMore is null when the endpoint does not send it' => static function (): void {
                $page = Page::fromDecoded(['items' => [], 'nextCursor' => null]);
                Assert::same(
                    null,
                    $page->hasMore(),
                    'a missing boolean is not false; nextCursor is what decides whether to fetch again',
                );
            },

            '§5 the generator makes no request until it is iterated' => static function (): void {
                $transport = new FakeTransport();
                $generator = self::client($transport)->contacts->autoList();

                Assert::same(0, $transport->count(), 'building the walk must not fetch anything');
                Assert::true($generator instanceof \Generator, 'autoList returns a Generator');
            },

            '§5 the Paginator can resume from an explicit cursor' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [['id' => 'c']], 'nextCursor' => null]);
                $client = self::client($transport);

                $paginator = new Paginator(
                    fn (array $query): Page => $client->requestPage('/v1/contacts', $query),
                    ['cursor' => 'saved_cursor', 'limit' => 5],
                );
                $rows = iterator_to_array($paginator->items(), false);

                Assert::same(1, \count($rows), 'one row');
                // Parameter ORDER is not asserted: a query string is a set of
                // pairs and the SDK is free to emit them in any order. What
                // matters is that the resume cursor is present and is the one
                // that was handed in.
                Assert::contains(
                    'cursor=saved_cursor',
                    $transport->query(),
                    'a starting cursor must be honoured so a walk can be resumed',
                );
                Assert::contains('limit=5', $transport->query(), 'and the rest of the query survives');
            },

            '§5 every paged namespace exposes an auto-walk over the right path' => static function (): void {
                $walks = [
                    ['contacts', 'autoList', [], '/v1/contacts'],
                    ['conversations', 'autoList', [], '/v1/conversations'],
                    ['conversations', 'autoMessages', ['conv_1'], '/v1/conversations/conv_1/messages'],
                    ['tickets', 'autoList', [], '/v1/tickets'],
                    ['macros', 'autoList', [], '/v1/macros'],
                    ['kb', 'autoArticles', [], '/v1/kb/articles'],
                    ['events', 'autoList', [], '/v1/cdp/events'],
                    ['webhooks', 'autoDeliveries', [], '/v1/webhooks/deliveries'],
                ];

                foreach ($walks as [$namespace, $method, $args, $path]) {
                    $transport = new FakeTransport();
                    $transport->push(200, ['items' => [['id' => 'x']], 'nextCursor' => null]);
                    $client = self::client($transport);
                    $rows = iterator_to_array($client->{$namespace}->{$method}(...$args), false);

                    Assert::same(1, \count($rows), "{$namespace}->{$method}() yields its rows");
                    Assert::same($path, $transport->path(), "{$namespace}->{$method}() walks {$path}");
                }
            },
        ];
    }
}

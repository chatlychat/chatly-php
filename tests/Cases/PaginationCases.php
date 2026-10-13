<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlyClient;
use Chatly\Errors\ChatlyException;
use Chatly\Pagination\Page;
use Chatly\Pagination\Paginator;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;
use Chatly\Tests\Skipped;

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

            '§5 an unpaginated route returns its bare array, and reading one AS a page is explicit' => static function (): void {
                $transport = new FakeTransport();
                // `GET /v1/kb/collections`, `/v1/channels` and `/v1/labels`
                // are deliberately unpaginated and return a plain list.
                $transport->push(200, [['id' => 'col_1'], ['id' => 'col_2']]);
                $collections = self::client($transport)->kb->collections();

                Assert::same(2, \count($collections), 'the array comes back as given');
                Assert::false($collections instanceof Page, 'and is not wrapped in a page');

                /*
                 * `fromDecoded` used to accept a bare array and hand back a
                 * single cursorless page. It no longer does — see below for
                 * why that tolerance was a bug — so wanting a page over an
                 * unpaginated route is now something you say out loud.
                 */
                $page = Page::fromList([['id' => 'col_1']]);
                Assert::same(1, \count($page->items), 'fromList yields its rows');
                Assert::same(null, $page->nextCursor, 'with no next page');
                Assert::true($page->isLastPage(), 'and knows it is the last — honest, on a route with no cursor');

                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => Page::fromList(['id' => 'col_1']),
                    'fromList must refuse a keyed array: an object is not an unpaginated collection',
                );
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

            '§5 GET /v1/macros returns the bare array it sends, not a page' => static function (): void {
                /*
                 * The route is `@PaginationExempt`: the service caps the
                 * result at 200 rows ordered by usage rank, so the first page
                 * IS the answer. A client that wrapped this in a page
                 * envelope — or worse, tried to follow a cursor it invented —
                 * would be reading a shape the API does not send. Two SDKs
                 * raised a decode error here in real use while their suites
                 * stayed green, because their fakes answered every route with
                 * `{items,nextCursor}`.
                 */
                $transport = new FakeTransport();
                $transport->push(200, [['id' => 'm_1', 'name' => 'Refund'], ['id' => 'm_2', 'name' => 'Escalate']]);
                $macros = self::client($transport)->macros->list(['scope' => 'workspace']);

                Assert::true(\is_array($macros), 'the array must come back as an array');
                Assert::false($macros instanceof Page, 'and must not be wrapped in a Page');
                Assert::same(2, \count($macros), 'both rows');
                Assert::same('m_1', $macros[0]['id'] ?? null, 'in the order the service ranked them');
                Assert::same(
                    'scope=workspace',
                    $transport->query(),
                    'the four filters this route accepts are scope, team, query and language — no cursor',
                );
                Assert::false(
                    method_exists(self::client(new FakeTransport())->macros, 'autoList'),
                    'macros->autoList() must not exist: there is no cursor to walk',
                );
            },

            '§5 the auto-walk set matches the manifest\'s paged entries, and there are 10' => static function (): void {
                /*
                 * An anti-vacuity floor on the list below.
                 *
                 * SEVEN, not eight: `macros.list` left the paged set because
                 * its ROUTE is pagination-exempt, not because a parser
                 * stopped recognising the entry. That distinction is the
                 * whole point of asserting the number — a count that shrinks
                 * because rows fell out of a parse is the bug this guards
                 * against, and it looks identical to a count that shrinks for
                 * a good reason unless somebody writes down which happened.
                 *
                 * It then went 7 -> 10: `contacts.journey`,
                 * `conversations.unattended` and `search.query` answer real
                 * `{ items, nextCursor }` envelopes and had never been
                 * flagged. So the flag has now been wrong in both directions,
                 * and neither time was a parse failure. Check that
                 * `surface.json` parses before believing the next change.
                 */
                Assert::same(
                    10,
                    \count(SurfaceCases::PAGED),
                    'the manifest carries 10 paged entries',
                );

                $manifestPath = \dirname(__DIR__, 3) . '/sdk-spec/surface.json';
                if (!is_file($manifestPath)) {
                    throw new Skipped('surface.json is not reachable from here (' . $manifestPath . ')');
                }
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, \JSON_THROW_ON_ERROR);
                $paged = [];
                foreach ($manifest['entries'] as $entry) {
                    if (($entry['paged'] ?? false) === true) {
                        $paged[] = $entry['ns'] . '.' . $entry['name'];
                    }
                }
                sort($paged);
                $mine = SurfaceCases::PAGED;
                sort($mine);
                Assert::same(
                    $paged,
                    $mine,
                    'the paged set here must be the manifest\'s, exactly',
                );
            },

            '§10.10 every auto-paginator rejects a bare array where a page is expected' => static function (): void {
                /*
                 * ─── THE NEGATIVE DIRECTION, AND WHY IT IS THE REAL TEST ──
                 *
                 * The shape pass in SurfaceCases proves each method copes
                 * with the shape its route actually sends. It CANNOT prove
                 * the declaration matters: a decoder that ignored the body
                 * entirely would pass it. This feeds every walker a bare
                 * JSON array where it expects `{items, nextCursor}` and
                 * requires it to fail loudly — which is the failure the real
                 * API handed the .NET and Rust clients on `macros.list`.
                 *
                 * It found a bug here. `Page::fromDecoded` used to accept a
                 * bare array and return a single page holding its rows, so
                 * `contacts->autoList()` over a non-page body yielded one
                 * page's worth of rows, reported a null cursor and stopped —
                 * a truncated walk indistinguishable from a complete one,
                 * with a 200 on it. The tolerance was documented as a
                 * convenience for the unpaginated routes, but no method in
                 * this SDK ever read one through `Page`, so the only bodies
                 * it ever applied to were bodies the caller did not expect.
                 * It is a `ChatlyException` now, code
                 * `unexpected_response_shape`; `Page::fromList()` is the
                 * deliberate way to wrap a plain list.
                 */
                $walkers = [
                    'contacts.list' => ['contacts', 'autoList', []],
                    'contacts.journey' => ['contacts', 'autoJourney', ['ct_1']],
                    'conversations.list' => ['conversations', 'autoList', []],
                    'conversations.messages' => ['conversations', 'autoMessages', ['conv_1']],
                    'conversations.unattended' => ['conversations', 'autoUnattended', []],
                    'tickets.list' => ['tickets', 'autoList', []],
                    'kb.articles' => ['kb', 'autoArticles', []],
                    'search.query' => ['search', 'autoQuery', ['refund']],
                    'events.list' => ['events', 'autoList', []],
                    'webhooks.deliveries' => ['webhooks', 'autoDeliveries', []],
                ];

                /*
                 * The floor that stops this test quietly shrinking.
                 *
                 * Exactly the routes declared `page` — no more, so a walker
                 * cannot be offered over a route with no cursor, and no
                 * fewer, so dropping one from the list above does not look
                 * like a pass.
                 */
                $exercised = array_keys($walkers);
                sort($exercised);
                Assert::same(
                    ResponseShapes::withShape(ResponseShapes::PAGE),
                    $exercised,
                    'the walkers exercised here must be exactly the 10 routes declared `page`',
                );
                Assert::same(10, \count($walkers), 'and there are ten of them');

                foreach ($walkers as $label => [$namespace, $method, $args]) {
                    $transport = new FakeTransport();
                    // A bare array, which is what `macros.list` really sends
                    // and what a page route must never be read as.
                    ResponseShapes::queue($transport, ResponseShapes::ARRAY_SHAPE);
                    $client = self::client($transport);

                    $error = Assert::throws(
                        ChatlyException::class,
                        static function () use ($client, $namespace, $method, $args): void {
                            foreach ($client->{$namespace}->{$method}(...$args) as $_row) {
                                // One row is enough: the decode happens
                                // before the first yield, so if this body
                                // executes at all the paginator accepted a
                                // non-page body.
                                break;
                            }
                        },
                        "{$label}'s paginator accepted a bare array as a page instead of failing",
                    );
                    Assert::same(
                        'unexpected_response_shape',
                        $error instanceof ChatlyException ? $error->getErrorCode() : null,
                        "{$label} must fail with a code a caller can branch on, not a bare message",
                    );
                    Assert::contains(
                        'bare JSON array',
                        $error->getMessage(),
                        "{$label}'s error must say what arrived, so the reader knows it is the API and not them",
                    );
                    Assert::same(1, $transport->count(), "{$label} must fail on the first page, not keep walking");
                }
            },

            '§5 a page body with no items list is refused too' => static function (): void {
                /*
                 * The other half of the same bug. An object with no `items`
                 * was read as an EMPTY page, so a walk over it yielded
                 * nothing and reported success — the exact wrong answer the
                 * Rust client gave, arrived at from the opposite direction.
                 */
                foreach ([['id' => 'x_1'], [], ['items' => ['a' => 1]]] as $index => $body) {
                    $transport = new FakeTransport();
                    $transport->push(200, $body);
                    $client = self::client($transport);

                    $error = Assert::throws(
                        ChatlyException::class,
                        static fn () => iterator_to_array($client->contacts->autoList(), false),
                        "body #{$index} is not a page and must not be read as an empty one",
                    );
                    Assert::contains('`items` list', $error->getMessage(), 'and the error says what was missing');
                }

                // A real empty page still works: `items` present, no cursor.
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [], 'nextCursor' => null]);
                Assert::same(
                    [],
                    iterator_to_array(self::client($transport)->contacts->autoList(), false),
                    'an empty page is a legitimate answer and yields nothing without complaint',
                );
            },

            '§5 every paged namespace exposes an auto-walk over the right path' => static function (): void {
                $walks = [
                    ['contacts', 'autoList', [], '/v1/contacts'],
                    ['conversations', 'autoList', [], '/v1/conversations'],
                    ['conversations', 'autoMessages', ['conv_1'], '/v1/conversations/conv_1/messages'],
                    ['tickets', 'autoList', [], '/v1/tickets'],
                    ['kb', 'autoArticles', [], '/v1/kb/articles'],
                    ['contacts', 'autoJourney', ['ct_1'], '/v1/contacts/ct_1/journey'],
                    ['conversations', 'autoUnattended', [], '/v1/conversations/unattended'],
                    ['search', 'autoQuery', ['refund'], '/v1/search'],
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

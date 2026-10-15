<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlyClient;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;
use Chatly\Tests\Skipped;

/**
 * The 163 methods of the shared SDK surface, EXECUTED, and the parity
 * evidence that execution produces.
 *
 * ─── WHY THIS RUNS THE CLIENT INSTEAD OF READING THE SOURCE ───────────────
 *
 * The first version of `scripts/check-sdk-surface-parity.mjs` matched path
 * strings in SDK source TEXT, and it was fooled: doc comments mentioning a
 * route counted as implementations of it, so it printed 55/55 for methods
 * that did not exist — a trap still live in this tree: a grep for
 * key-accessible routes counts the `@Get(':id')` written inside a docblock on
 * `bot-routes.module.ts` as a second route. Text matching cannot tell
 * documentation from code, and
 * it cannot see a path assembled through a helper either — which is how every
 * path in this package is built.
 *
 * So the evidence here is a record of what each method actually sent. Every
 * method is called against the fake transport; the request it issued is
 * observed; the interpolated segments are mapped back to the manifest's
 * parameter names BY POSITION; and the result is written to
 * `surface.lock.json`. A comment cannot fake an entry in that file, because
 * nothing but an executed request puts one there.
 */
final class SurfaceCases
{
    /** Where the lock is written, relative to the package root. */
    public const LOCK_FILE = 'surface.lock.json';

    /**
     * Sentinel arguments.
     *
     * Passed in place of every id that lands in a PATH segment, in the order
     * the placeholders appear in the manifest's template. A segment equal to
     * one of these is a placeholder position; anything else is a literal the
     * SDK chose, and is recorded verbatim so a wrong literal cannot be
     * normalised away.
     *
     * Deliberately not URL-safe-adjacent to any real path segment, and made
     * of unreserved characters so `rawurlencode()` leaves them alone — if
     * encoding mangled them this case would fail rather than silently stop
     * recognising them.
     */
    public const SENTINELS = ['__p0__', '__p1__'];

    /**
     * The `ns.name` of every manifest entry marked `paged: true`.
     *
     * THIRTEEN, and it has been 8, 7 and 10 — the manifest's `paged` flag was
     * wrong in both directions and the history is the point.
     *
     * It read 8 and included `macros.list`, whose handler is
     * `@PaginationExempt` and returns a bare array capped at 200 rows ordered
     * by usage rank. That came out: 7. Then `contacts.journey`,
     * `conversations.unattended` and `search.query` were found to answer real
     * `{ items, nextCursor }` envelopes while unflagged, and went in: 10.
     *
     * Both moves were on evidence from the handlers — `JourneyResponse` and
     * `unattendedPageSchema` (which also carries a `total`) and
     * `SearchResponse` (which carries `tier` and `facets` beside the hits).
     * Neither was a parser shrinking the audited set, which is the other
     * reason a count like this changes and the one that would be a bug. If it
     * drops again, check that `surface.json` parses before believing a route
     * stopped paginating.
     *
     * 10 -> 13 came with the rest of the surface: `bots.list`,
     * `bots.deliveries` and `triggers.list`, each read off its own
     * `@ZodResponse` schema — `botsPageSchema`, `agentDeliveriesPageSchema`
     * and `triggersPageSchema`, all three `{ items, nextCursor, hasMore }`.
     * That was a growth in what the manifest covers, not a correction to it;
     * the manifest had them flagged already.
     *
     * Used for two things: deciding what the fake transport answers each
     * route with, and as an anti-vacuity floor in {@see PaginationCases}.
     */
    public const PAGED = [
        'contacts.list',
        'contacts.journey',
        'conversations.list',
        'conversations.messages',
        'conversations.unattended',
        'tickets.list',
        'kb.articles',
        'search.query',
        'events.list',
        'webhooks.deliveries',
        'bots.list',
        'bots.deliveries',
        'triggers.list',
    ];

    /**
     * The route table, and the manifest-namespace to PHP-property mapping.
     *
     * Both live in {@see SurfaceTable} — 163 rows of data and one derivation,
     * with no assertions in them. They are re-exposed here because this is
     * the class the rest of the suite names, and because splitting data from
     * the behaviour that checks it is the only way this file stays under the
     * 650-line ceiling CONTRACT.md §9 sets. Nothing but the location changed.
     *
     * @return array<int,array{0:string,1:string,2:string,3:string,4:string,5:array<int,mixed>}>
     */
    public static function surface(): array
    {
        return SurfaceTable::surface();
    }

    /** @see SurfaceTable::property() */
    public static function property(string $namespace): string
    {
        return SurfaceTable::property($namespace);
    }

    /**
     * Call every method, observe what it sent, and rebuild the path template
     * from the OBSERVED segments.
     *
     * The rebuild is the evidence. An observed segment is emitted verbatim
     * unless it is one of the sentinels passed in, in which case it is
     * replaced by the manifest's parameter name at that index. So a method
     * that hits `/v1/contacts/__p0__/labels/__p1__` rebuilds as
     * `/v1/contacts/{id}/labels/{tag}` and fails the comparison; a method
     * that ignores its argument and hard-codes an id rebuilds with that id in
     * the path and fails too. Nothing is normalised away.
     *
     * It also records the top-level RESPONSE shape, by feeding each method the
     * shape {@see ResponseShapes} declares for its route — one fixture per
     * route, not one for all 163 — and asserting the value it hands back
     * really has it. See that class for why the declaration is hand-written
     * and not read from `surface.json`.
     *
     * @return array<int,array{ns:string,name:string,http:string,path:string,shape:string}>
     */
    private static function recordLockEntries(): array
    {
        $entries = [];

        foreach (self::surface() as [$namespace, $specName, $method, $verb, $template, $args]) {
            $label = $namespace . '.' . $specName;

            /*
             * The shape THIS route answers with, not one shape for all 163.
             *
             * A fake that answers every route with `{items,nextCursor}` is
             * how the .NET and Rust clients shipped a decode error on
             * `GET /v1/macros` — which returns a bare array — with green
             * suites. And a fake fed from the manifest's own `shape` column
             * would be no better: it would agree with the manifest even when
             * the manifest is wrong, which the `paged` flag was, in both
             * directions, for months. The fixture comes from this package's
             * own hand-written declaration.
             */
            $shape = ResponseShapes::of($label);
            $transport = ResponseShapes::queue(new FakeTransport(), $shape);
            $client = new ChatlyClient('ck_test', transport: $transport);

            $target = $namespace === 'root' ? $client : $client->{self::property($namespace)};
            Assert::true(
                method_exists($target, $method),
                "{$label} must exist as "
                . ($namespace === 'root' ? '' : self::property($namespace) . '->')
                . $method . '()',
            );

            $value = $target->{$method}(...$args);

            /*
             * The value really has the declared shape.
             *
             * This is what catches a method that wraps or unwraps: a `list`
             * handing back `$page->items` from a page route, or one reaching
             * for `['items']` on a bare-array route, disagrees here even
             * though the request it issued was perfect. `describe()` tells
             * `array` from `object` with `array_is_list()` — `is_array()`
             * cannot, because `json_decode(…, true)` collapses both into a
             * PHP array, and a check that cannot fail is not a check.
             */
            Assert::same(
                $shape,
                ResponseShapes::describe($value),
                "{$label} is declared `{$shape}` and its decoder must hand back one",
            );
            /*
             * And the fixture's row survived the decode. A method that
             * returned a constant, or dropped the body, has the right shape
             * and the wrong content — `macros.delete` excepted, which has no
             * body by definition.
             */
            Assert::same(
                $shape === ResponseShapes::EMPTY_SHAPE ? null : ResponseShapes::SENTINEL_ROW,
                ResponseShapes::sentinelIn($value, $shape),
                "{$label} must hand back the row the API sent, not an invented one",
            );

            Assert::same(1, $transport->count(), "{$label} must make exactly one request");
            Assert::same($verb, $transport->method(), "{$label} must be a {$verb}");
            Assert::same('Bearer ck_test', $transport->header('authorization'), "{$label} must authenticate");

            if (\in_array($verb, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                Assert::true(
                    $transport->header('idempotency-key') !== null,
                    "{$label} is a write and must carry an Idempotency-Key",
                );
            } else {
                Assert::same(
                    null,
                    $transport->header('idempotency-key'),
                    "{$label} is a read and must not carry an Idempotency-Key",
                );
            }

            $observed = explode('/', $transport->path());
            $expected = explode('/', $template);
            Assert::same(
                \count($expected),
                \count($observed),
                "{$label} issued a path with a different number of segments than {$template}: "
                . $transport->path(),
            );

            $rebuilt = [];
            foreach ($observed as $index => $segment) {
                if (\in_array($segment, self::SENTINELS, true)) {
                    // A placeholder position. Take the manifest's own name for
                    // it — matched by position, which is the whole convention.
                    $name = $expected[$index];
                    Assert::true(
                        str_starts_with($name, '{') && str_ends_with($name, '}'),
                        "{$label} put the argument in segment {$index}, where {$template} has a literal",
                    );
                    $rebuilt[] = $name;

                    continue;
                }
                $rebuilt[] = $segment;
            }

            $path = implode('/', $rebuilt);
            Assert::same(
                $template,
                $path,
                "{$label} issued {$transport->path()}, which normalises to {$path}, not {$template}",
            );

            // Key order is the manifest's: ns, name, http, path, shape.
            $entries[] = [
                'ns' => $namespace,
                'name' => $specName,
                'http' => $verb,
                'path' => $path,
                'shape' => $shape,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return [$a['ns'], $a['name']] <=> [$b['ns'], $b['name']];
        });

        return $entries;
    }

    /**
     * Serialise the lock the way this repository's formatter would.
     *
     * ─── TWO SPACES, NOT FOUR ─────────────────────────────────────────────
     *
     * `JSON_PRETTY_PRINT` indents with four spaces and PHP offers no way to
     * change that. prettier owns every `.json` file here at `tabWidth: 2`,
     * and it runs from a lint-staged hook, so a four-space lock is rewritten to two the
     * moment anybody commits — and rewritten back to four the next time the
     * suite runs. That is a committed artifact changing on every run in both
     * directions, for no reason, forever; it also means `format:check` and
     * this test cannot both be green.
     *
     * Halving the leading indentation is safe because no value in the lock
     * contains a newline — every one is a short `ns`, `name`, verb or path.
     *
     * @param array<string,mixed> $lock
     */
    private static function encodeLock(array $lock): string
    {
        $json = json_encode(
            $lock,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        $two = preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $m): string => str_repeat(' ', \strlen($m[0]) / 2),
            $json,
        );

        return (string) $two . "\n";
    }

    /**
     * `ns.name => "VERB path → shape"`, for readable comparisons.
     *
     * The shape is folded into the same string rather than compared
     * separately so that a disagreement about it reports the route it belongs
     * to, in the same line as the verb and path — and so a comparison written
     * before the shape existed cannot keep passing while ignoring it. It is
     * the same wire format `scripts/check-sdk-surface-parity.mjs` builds.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,string>
     */
    private static function routeMap(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $shape = $entry['shape'] ?? null;
            $map[$entry['ns'] . '.' . $entry['name']] = $entry['http'] . ' ' . $entry['path']
                . ' → ' . (\is_string($shape) ? $shape : '(no shape)');
        }
        ksort($map);

        return $map;
    }

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            'surface: all 163 methods execute the documented verb and path, and surface.lock.json is regenerated from what they sent' => static function (): void {
                $entries = self::recordLockEntries();
                Assert::same(163, \count($entries), 'the shared surface is 163 methods');

                $keys = array_map(static fn (array $e): string => $e['ns'] . '.' . $e['name'], $entries);
                Assert::same(
                    \count($keys),
                    \count(array_unique($keys)),
                    'two rows claim the same ns.name',
                );

                /*
                 * The RECORDED column, not just the declaration.
                 *
                 * ResponseShapes self-tests its own distribution; this
                 * asserts the same of what actually reached the lock, so a
                 * generator that flattened the field on the way out — or a
                 * sweep that dropped it — fails here rather than shipping 163
                 * identical values for the gate to accept.
                 */
                $recordedShapes = [];
                foreach ($entries as $entry) {
                    $recordedShapes[$entry['shape']] = ($recordedShapes[$entry['shape']] ?? 0) + 1;
                }
                ksort($recordedShapes);
                $expectedShapes = ResponseShapes::EXPECTED_DISTRIBUTION;
                ksort($expectedShapes);
                Assert::same(
                    $expectedShapes,
                    $recordedShapes,
                    'the shapes written to the lock must be the documented 33/13/116/1, all four present',
                );

                $lock = [
                    'sdk' => 'php',
                    'generatedBy' => 'tests/Cases/SurfaceCases.php',
                    'entries' => $entries,
                ];
                $json = self::encodeLock($lock);

                $path = \dirname(__DIR__, 2) . '/' . self::LOCK_FILE;
                $existing = is_file($path) ? (string) file_get_contents($path) : null;
                if ($existing === $json) {
                    // Already current. Do not rewrite — a test should not dirty
                    // the working tree for no reason.
                    return;
                }
                if (@file_put_contents($path, $json) === false) {
                    /*
                     * Unwritable AND stale. A read-only checkout (an install
                     * under `vendor/`) is a fine reason not to be able to
                     * write, but it is not a reason to accept a lock that
                     * disagrees with what the client just did.
                     */
                    throw new \RuntimeException(sprintf(
                        '%s is out of date and could not be rewritten. Run the suite somewhere writable.',
                        $path,
                    ));
                }
            },

            'surface: surface.lock.json on disk equals packages/sdk-spec/surface.json' => static function (): void {
                $lockPath = \dirname(__DIR__, 2) . '/' . self::LOCK_FILE;
                Assert::true(
                    is_file($lockPath),
                    self::LOCK_FILE . ' must exist — the case above writes it',
                );
                $lock = json_decode((string) file_get_contents($lockPath), true, 512, \JSON_THROW_ON_ERROR);
                Assert::same('php', $lock['sdk'] ?? null, 'the lock must name this SDK');
                Assert::same(
                    'tests/Cases/SurfaceCases.php',
                    $lock['generatedBy'] ?? null,
                    'the lock must name what produced it',
                );
                Assert::true(\is_array($lock['entries'] ?? null), 'the lock must carry an entries array');

                /*
                 * Field set AND order, per entry.
                 *
                 * The gate reads `shape` by name, so order is cosmetic to
                 * it — but the lock is a committed cross-language artifact
                 * that eight generators write, and eight different key
                 * orders make its diffs unreadable. Asserted here because a
                 * missing `shape` is the failure mode the gate reports as
                 * "this SDK has not been through the shape pass".
                 */
                foreach ($lock['entries'] as $entry) {
                    Assert::same(
                        ['ns', 'name', 'http', 'path', 'shape'],
                        array_keys($entry),
                        'every lock entry carries exactly ns, name, http, path, shape — in that order',
                    );
                    Assert::true(
                        \in_array($entry['shape'], ResponseShapes::ALL, true),
                        $entry['ns'] . '.' . $entry['name'] . ' has shape "' . var_export($entry['shape'], true)
                        . '", which is not one of the four',
                    );
                }

                /*
                 * The lock on disk must be what the client is doing NOW.
                 *
                 * Without this, a failure in the case above — which throws
                 * before it writes — would leave a stale committed lock for
                 * this case to compare happily against the manifest. Two
                 * green-looking halves and a wrong client. Re-recording costs
                 * a few milliseconds.
                 */
                $recorded = self::recordLockEntries();
                // Compared as `ns.name => "VERB path"` rather than as whole
                // arrays, so a stale lock reports the handful of rows that
                // differ instead of dumping all 163.
                $onDisk = self::routeMap($lock['entries']);
                $now = self::routeMap($recorded);
                foreach ($now as $key => $route) {
                    Assert::same(
                        $route,
                        $onDisk[$key] ?? null,
                        "the committed lock is stale for {$key}: re-run the suite somewhere writable "
                        . 'and commit the result',
                    );
                }
                Assert::same(
                    array_keys($now),
                    array_keys($onDisk),
                    'the committed lock lists different methods than the client has: re-run the suite',
                );

                // Monorepo first, then a mirror's vendored copy at its root.
                $manifestPath = \dirname(__DIR__, 3) . '/sdk-spec/surface.json';
                if (!\is_file($manifestPath)) {
                    $manifestPath = \dirname(__DIR__, 2) . '/sdk-spec/surface.json';
                }
                if (!is_file($manifestPath)) {
                    throw new Skipped(
                        'surface.json is not reachable from here (' . $manifestPath . '). Expected '
                        . 'inside the monorepo; absent in a published package, where the lock is '
                        . 'still regenerated and self-checked but has nothing to be compared against.',
                    );
                }
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, \JSON_THROW_ON_ERROR);
                Assert::true(\is_array($manifest['entries'] ?? null), 'surface.json must carry an entries array');
                Assert::same(
                    ChatlyClient::DEFAULT_BASE_URL,
                    $manifest['baseUrl'] ?? null,
                    'this SDK\'s default base URL must be the manifest\'s',
                );

                $expected = self::routeMap($manifest['entries']);
                $actual = self::routeMap($lock['entries']);

                $missing = array_diff_key($expected, $actual);
                Assert::same(
                    [],
                    $missing,
                    'in surface.json but not implemented here: ' . implode(', ', array_keys($missing)),
                );
                $extra = array_diff_key($actual, $expected);
                Assert::same(
                    [],
                    $extra,
                    'implemented here but not in surface.json: ' . implode(', ', array_keys($extra)),
                );
                foreach ($expected as $key => $route) {
                    Assert::same($route, $actual[$key], "the route recorded for {$key}");
                }
                Assert::same(\count($expected), \count($actual), 'the manifest and the lock must be the same size');
            },

            'surface: the lock is written the way the repo formatter writes JSON' => static function (): void {
                /*
                 * Two-space indent, one trailing newline. `JSON_PRETTY_PRINT`
                 * emits four and cannot be told otherwise, and prettier owns
                 * the .json files here at tabWidth 2 from a lint-staged hook
                 * — so a four-space lock is rewritten on every commit and
                 * rewritten back on every test run, which makes a committed
                 * artifact churn in both directions and `format:check`
                 * unpassable. This is what keeps those two from fighting.
                 */
                $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . self::LOCK_FILE);

                Assert::contains("\n  \"sdk\": \"php\"", $raw, 'top-level keys must be indented two spaces');
                Assert::notContains("\n    \"sdk\"", $raw, 'four-space indent means JSON_PRETTY_PRINT leaked back in');
                Assert::true(str_ends_with($raw, "}\n"), 'exactly one trailing newline, as prettier writes');
                Assert::false(str_ends_with($raw, "}\n\n"), 'and not two');
                Assert::notContains("\r\n", $raw, 'LF, per .prettierrc endOfLine');
                Assert::notContains('\\/', $raw, 'slashes must not be escaped — paths would be unreadable');
            },

            'surface: the lock is sorted by ns then name' => static function (): void {
                $lockPath = \dirname(__DIR__, 2) . '/' . self::LOCK_FILE;
                $lock = json_decode((string) file_get_contents($lockPath), true, 512, \JSON_THROW_ON_ERROR);

                $order = array_map(
                    static fn (array $e): array => [$e['ns'], $e['name']],
                    $lock['entries'],
                );
                $sorted = $order;
                usort($sorted, static fn (array $a, array $b): int => $a <=> $b);
                Assert::same(
                    $sorted,
                    $order,
                    'a stable order keeps the committed lock\'s diffs readable',
                );
            },

            'surface: no method is offered for a route an API key cannot reach' => static function (): void {
                /*
                 * Shipping a method that always fails is worse than not
                 * shipping it: the failure arrives at runtime, in production,
                 * in someone else's integration. A reaction records WHO
                 * reacted and a key is not a person. `GET /v1/teams` carries
                 * no `@AllowApiKey()` — the decorator a grep finds belongs to
                 * `/teams/{id}/members` below it — and `GET /v1/agents` has
                 * no handler at all.
                 *
                 * ─── TWO ENTRIES CAME OFF THIS LIST, AND WHY ──────────────
                 *
                 * `channels.create` and `channels.update` were here, on the
                 * claim that channel writes "need `channels:write`, which no
                 * key-accessible route grants". Both halves were false:
                 * `channels:write` IS in `API_KEY_SCOPES`
                 * (`api-key-scopes.ts:129`), added deliberately so a
                 * developer can stand a widget up from code, and both
                 * handlers carry `@AllowApiKey()`. They are implemented now.
                 *
                 * `channels.delete` STAYS. `DELETE /v1/channels/{id}` is the
                 * one write on that controller with no `@AllowApiKey()`
                 * (`channels-delete.controller.ts:75-76`) and the scope list
                 * says so in as many words: it "stays dashboard-only". So
                 * this list is no longer "everything `channels:write` cannot
                 * reach" — it is per-route, which is the only way to read it
                 * that is true.
                 */
                $client = new ChatlyClient('ck_test', transport: new FakeTransport());
                $forbidden = [
                    ['conversations', 'react'],
                    ['conversations', 'addReaction'],
                    ['conversations', 'reactions'],
                    ['channels', 'delete'],
                    ['contacts', 'block'],
                    ['contacts', 'unblock'],
                    ['workspace', 'teams'],
                    ['workspace', 'agents'],
                ];
                foreach ($forbidden as [$namespace, $method]) {
                    Assert::false(
                        method_exists($client->{self::property($namespace)}, $method),
                        "{$namespace}->{$method}() must not exist: that route refuses an API key",
                    );
                }

                /*
                 * And the two that came off are really here — otherwise this
                 * case would have been weakened rather than corrected, which
                 * is indistinguishable from the outside.
                 */
                foreach ([['channels', 'create'], ['channels', 'update']] as [$namespace, $method]) {
                    Assert::true(
                        method_exists($client->{self::property($namespace)}, $method),
                        "{$namespace}->{$method}() must exist: its handler carries @AllowApiKey() "
                        . 'and `channels:write` is a grantable key scope',
                    );
                }
            },
        ];
    }
}

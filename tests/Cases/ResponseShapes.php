<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\Pagination\Page;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;

/**
 * The top-level shape of a successful response, per route, HAND-WRITTEN.
 *
 * ─── WHY THIS IS NOT READ FROM surface.json ───────────────────────────────
 *
 * `packages/sdk-spec/surface.json` carries a `shape` on every entry, and this
 * file deliberately does not read it — even though {@see SurfaceCases} loads
 * that same manifest for the path templates it compares against.
 *
 * A fixture derived from the manifest agrees with whatever the manifest says,
 * including when the manifest is wrong, and that is not hypothetical. The
 * field next to `shape` — `paged` — was wrong in BOTH directions for months:
 * `macros.list` was flagged `paged: true` while its handler is
 * `@PaginationExempt` and answers a bare 200-row array, and
 * `contacts.journey`, `conversations.unattended` and `search.query` return
 * real `{items, nextCursor}` envelopes and were unflagged. The .NET and Rust
 * clients raised a decode error on `GET /v1/macros` against the real API with
 * green suites, because their fake transports answered EVERY route with
 * `{items, nextCursor}`. A fake fed from the manifest proves nothing: it is
 * the manifest marking its own homework.
 *
 * So this column is a SECOND OPINION, read out of the handlers'
 * `@ZodResponse` schemas in `apps/api/src/modules/` by hand. The suite feeds
 * each method the shape declared HERE, asserts the value handed back really
 * has that shape, and writes the declaration into `surface.lock.json`.
 * `scripts/check-sdk-surface-parity.mjs` then diffs eight independently
 * written columns against the manifest — so a wrong manifest row needs eight
 * authors to misread the same handler.
 *
 * **Change a value here only after reading the handler.** Not after reading
 * `surface.json`, and not because the gate complained.
 *
 * ─── FOUR ROWS LOOK WRONG AND ARE NOT ─────────────────────────────────────
 *
 *  * `contacts.remove_tag` is a `DELETE` that answers **200 with the
 *    surviving contact** — the same object the tag `POST` returns — not 204.
 *  * `conversations.cancel_scheduled` is a `DELETE` that answers **200 with
 *    the row it just cancelled** (`@ZodResponse(scheduledMessageSchema)`).
 *    In `sdk-node` this was typed as returning `void`, so the body was parsed
 *    and thrown away.
 *  * `macros.delete` is the ONLY real 204 on this surface.
 *  * `reports.agents` and `reports.conversations_by_day` are bare arrays —
 *    per-agent rows and per-day buckets, with no envelope around them — while
 *    the other two report routes are objects.
 *
 * ─── HOW `array` IS TOLD FROM `object` IN PHP ─────────────────────────────
 *
 * `json_decode($body, true)` — which is what {@see \Chatly\ChatlyClient} does
 * — collapses a JSON array and a JSON object into the SAME PHP type: `array`.
 * So `is_array()` cannot tell them apart, and a classifier built on it would
 * make this whole pass vacuous: every one of the 54 non-empty routes would
 * satisfy it.
 *
 * {@see describe()} uses `array_is_list()` instead, which is the right
 * discriminator for THIS SDK because assoc arrays are what it hands callers
 * (`$contact['email']`, `$macros[0]['id']` — see the README). A JSON array
 * always decodes to a list; a JSON object decodes to a list only when every
 * key is an integer in sequence from 0, which no object on this surface is.
 * The one genuine collision is `{}` against `[]`, which both decode to `[]`
 * — so the fixtures below are deliberately NON-EMPTY, and
 * {@see selfTestCases()} proves both halves of that: the classifier splits
 * the two fixtures, and `is_array()` does not.
 */
final class ResponseShapes
{
    /** A cursor envelope: `items` plus `nextCursor`, whatever else sits beside them. */
    public const PAGE = 'page';

    /** A bare JSON array. */
    public const ARRAY_SHAPE = 'array';

    /** A JSON object that is not a page. */
    public const OBJECT = 'object';

    /** No body at all — a real 204. */
    public const EMPTY_SHAPE = 'empty';

    /** The four, in the manifest's own order. */
    public const ALL = [self::ARRAY_SHAPE, self::PAGE, self::OBJECT, self::EMPTY_SHAPE];

    /**
     * The distribution, asserted so a flattened column cannot pass.
     *
     * Counted from the 55 `@ZodResponse` schemas on 2026-10-12. A column that
     * said `object` 55 times would satisfy every other assertion in this
     * suite and be worthless, which is the only reason these numbers are
     * written down. If the surface grows they change — but all four values
     * must stay represented, and `empty` must stay at exactly one until a
     * second genuine 204 ships.
     */
    public const EXPECTED_DISTRIBUTION = [
        self::ARRAY_SHAPE => 14,
        self::PAGE => 10,
        self::OBJECT => 30,
        self::EMPTY_SHAPE => 1,
    ];

    /**
     * The sentinel row every fixture body carries.
     *
     * One field, distinctive, non-empty — the non-emptiness is load-bearing,
     * see the class docblock. Asserting this row survives the decode is what
     * stops a method that returns a constant, or drops the body, from passing
     * the shape check.
     */
    public const SENTINEL_ROW = ['id' => 'x_1'];

    /**
     * `ns.name => shape`, for all 55 routes.
     *
     * @return array<string,string>
     */
    public static function declared(): array
    {
        return [
            'root.whoami' => self::OBJECT,

            'contacts.list' => self::PAGE,
            'contacts.get' => self::OBJECT,
            'contacts.upsert' => self::OBJECT,
            'contacts.bulk_upsert' => self::OBJECT,
            'contacts.merge' => self::OBJECT,
            'contacts.add_tags' => self::OBJECT,
            // A DELETE with a body: the surviving contact.
            'contacts.remove_tag' => self::OBJECT,
            'contacts.unsubscribe' => self::OBJECT,
            'contacts.journey' => self::PAGE,

            'conversations.list' => self::PAGE,
            'conversations.get' => self::OBJECT,
            'conversations.messages' => self::PAGE,
            'conversations.send' => self::OBJECT,
            // `{items, nextCursor, total}` — `total` is the badge, and it is
            // not `count($items)`.
            'conversations.unattended' => self::PAGE,
            'conversations.agent_handling' => self::OBJECT,
            'conversations.set_agent_handling' => self::OBJECT,
            'conversations.generated_replies' => self::ARRAY_SHAPE,
            'conversations.generate_reply' => self::OBJECT,
            'conversations.scheduled_messages' => self::ARRAY_SHAPE,
            'conversations.schedule_message' => self::OBJECT,
            // Also a DELETE with a body: the row it just cancelled.
            'conversations.cancel_scheduled' => self::OBJECT,

            'tickets.list' => self::PAGE,
            'tickets.comments' => self::ARRAY_SHAPE,
            'tickets.for_conversation' => self::ARRAY_SHAPE,
            'tickets.queues' => self::ARRAY_SHAPE,
            'tickets.merge' => self::OBJECT,
            'tickets.split' => self::OBJECT,
            'tickets.bulk' => self::OBJECT,

            // `@PaginationExempt`, bare array, capped at 200 rows by usage
            // rank. The one that cost two SDKs a decode error.
            'macros.list' => self::ARRAY_SHAPE,
            'macros.create' => self::OBJECT,
            'macros.update' => self::OBJECT,
            // The ONLY 204 here: `@HttpCode(204)`, handler returns nothing.
            'macros.delete' => self::EMPTY_SHAPE,

            'kb.articles' => self::PAGE,
            'kb.article' => self::OBJECT,
            'kb.collections' => self::ARRAY_SHAPE,

            // `{items, nextCursor, tier, facets}`.
            'search.query' => self::PAGE,

            'events.track' => self::OBJECT,
            'events.list' => self::PAGE,

            'webhooks.deliveries' => self::PAGE,
            'webhooks.delivery' => self::OBJECT,
            'webhooks.redeliver' => self::OBJECT,

            'reports.overview' => self::OBJECT,
            // Arrays, both: per-agent rows and per-day buckets, no envelope.
            'reports.agents' => self::ARRAY_SHAPE,
            'reports.conversations_by_day' => self::ARRAY_SHAPE,
            'reports.sla' => self::OBJECT,

            'channels.list' => self::ARRAY_SHAPE,
            'channels.get' => self::OBJECT,

            'billing.plans' => self::ARRAY_SHAPE,
            'billing.usage' => self::OBJECT,

            'workspace.settings' => self::OBJECT,
            'workspace.labels' => self::ARRAY_SHAPE,
            'workspace.inboxes' => self::ARRAY_SHAPE,
            'workspace.team_members' => self::ARRAY_SHAPE,
            'workspace.agent_skills' => self::OBJECT,
        ];
    }

    /**
     * The declared shape of one route.
     *
     * Throws rather than defaulting. A route with no declaration is a route
     * nobody read the handler for, and silently answering it with an object
     * is how the gap would survive.
     */
    public static function of(string $label): string
    {
        $declared = self::declared();
        if (!isset($declared[$label])) {
            throw new \LogicException(sprintf(
                '%s has no declared response shape. Read its @ZodResponse handler and add a row '
                . 'to ResponseShapes::declared() — do not copy the value out of surface.json.',
                $label,
            ));
        }

        return $declared[$label];
    }

    /** Every route declared with a given shape, sorted. @return array<int,string> */
    public static function withShape(string $shape): array
    {
        $labels = array_keys(array_filter(
            self::declared(),
            static fn (string $declared): bool => $declared === $shape,
        ));
        sort($labels);

        return $labels;
    }

    /**
     * Queue a success body of one shape onto a fake transport.
     *
     * Exactly the four bodies the other seven SDKs' suites use, so a
     * cross-language disagreement is about the client and not the fixture.
     */
    public static function queue(FakeTransport $transport, string $shape): FakeTransport
    {
        if ($shape === self::EMPTY_SHAPE) {
            /*
             * A real 204, with NO body at all — not `{}`, not `null`, not an
             * empty object. A decoder that insists on parsing a body fails
             * here, which is the entire reason this value exists as its own
             * shape rather than being folded into `object`.
             */
            return $transport->push(204);
        }

        return $transport->push(200, self::body($shape), ['content-type' => 'application/json']);
    }

    /**
     * The decoded fixture for a shape, as PHP, before JSON-encoding.
     *
     * @return array<array-key,mixed>
     */
    public static function body(string $shape): array
    {
        return match ($shape) {
            // {"items":[{"id":"x_1"}],"nextCursor":null}
            self::PAGE => ['items' => [self::SENTINEL_ROW], 'nextCursor' => null],
            // [{"id":"x_1"}]
            self::ARRAY_SHAPE => [self::SENTINEL_ROW],
            // {"id":"x_1"}
            self::OBJECT => self::SENTINEL_ROW,
            default => throw new \LogicException(
                $shape === self::EMPTY_SHAPE
                    ? 'the `empty` shape has no body — that is what it means. Use queue().'
                    : 'unknown response shape: ' . $shape,
            ),
        };
    }

    /**
     * The shape a value THIS SDK handed back actually has.
     *
     * Named in the same four-word vocabulary so a mismatch reads as
     * `expected page, actual array` rather than as a type dump. See the class
     * docblock for why this cannot be `is_array()`.
     */
    public static function describe(mixed $value): string
    {
        if ($value instanceof Page) {
            // The typed envelope seven of the ten paged methods return. The
            // other three hand back the raw decoded body, so that it keeps
            // `total` / `tier` / `facets`, and fall through below.
            return self::PAGE;
        }
        if ($value === null) {
            return self::EMPTY_SHAPE;
        }
        if (!\is_array($value)) {
            return 'scalar(' . get_debug_type($value) . ')';
        }
        if (array_is_list($value)) {
            return self::ARRAY_SHAPE;
        }
        $items = $value['items'] ?? null;
        if (\is_array($items) && array_is_list($items) && \array_key_exists('nextCursor', $value)) {
            return self::PAGE;
        }

        return self::OBJECT;
    }

    /**
     * The sentinel row, dug out of a value of the given shape.
     *
     * Asserting this is what makes the positive shape check non-vacuous in a
     * second direction: a method that returned a hard-coded `[]`, or dropped
     * the body on the floor, has the right *shape* and the wrong *content*.
     *
     * @return array<array-key,mixed>|null Null when the row is not where the
     *                                     shape says it should be.
     */
    public static function sentinelIn(mixed $value, string $shape): ?array
    {
        $row = match (true) {
            $shape === self::EMPTY_SHAPE => null,
            $value instanceof Page => $value->items[0] ?? null,
            !\is_array($value) => null,
            $shape === self::PAGE => (\is_array($value['items'] ?? null) ? ($value['items'][0] ?? null) : null),
            $shape === self::ARRAY_SHAPE => $value[0] ?? null,
            default => $value,
        };

        return \is_array($row) ? $row : null;
    }

    /**
     * Cases that prove {@see describe()} itself is not vacuous.
     *
     * A classifier is the kind of thing that passes 55 routes while answering
     * the same word every time, so it gets its own tests — including the
     * negative one that `is_array()`, the obvious implementation, would have
     * made the whole pass meaningless.
     *
     * @return array<string,\Closure():void>
     */
    public static function selfTestCases(): array
    {
        return [
            'shapes: the classifier splits array from object, which is_array() cannot' => static function (): void {
                $asObject = json_decode('{"id":"x_1"}', true, 512, \JSON_THROW_ON_ERROR);
                $asArray = json_decode('[{"id":"x_1"}]', true, 512, \JSON_THROW_ON_ERROR);

                /*
                 * The trap, stated as an assertion. `json_decode(…, true)`
                 * gives the SAME PHP type for both, so a classifier built on
                 * `is_array()` would answer one word for 54 of the 55 routes
                 * and the whole shape pass would be theatre.
                 */
                Assert::true(\is_array($asObject), 'a JSON object decodes to a PHP array');
                Assert::true(\is_array($asArray), 'and so does a JSON array — is_array() cannot tell them apart');

                Assert::same(
                    self::OBJECT,
                    self::describe($asObject),
                    'array_is_list() is false for an object with a string key',
                );
                Assert::same(
                    self::ARRAY_SHAPE,
                    self::describe($asArray),
                    'and true for a JSON array, which always decodes to a list',
                );
            },

            'shapes: every fixture classifies as the shape it was built for' => static function (): void {
                foreach ([self::PAGE, self::ARRAY_SHAPE, self::OBJECT] as $shape) {
                    // Round-tripped through JSON the way the client decodes
                    // it, so the fixture is tested as bytes and not as the
                    // PHP literal it was written as.
                    $json = json_encode(self::body($shape), \JSON_THROW_ON_ERROR);
                    $decoded = json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
                    Assert::same($shape, self::describe($decoded), "the {$shape} fixture reads back as {$shape}");
                    Assert::same(
                        self::SENTINEL_ROW,
                        self::sentinelIn($decoded, $shape),
                        "the sentinel row is reachable in a {$shape}",
                    );
                }

                Assert::same(self::EMPTY_SHAPE, self::describe(null), 'a 204 decodes to null, which is `empty`');
                Assert::same(
                    self::PAGE,
                    self::describe(Page::fromDecoded(self::body(self::PAGE))),
                    'and a typed Page is a page',
                );
            },

            'shapes: an envelope without a cursor key is an object, not a page' => static function (): void {
                /*
                 * `page` means `items` AND `nextCursor`. An object that
                 * happens to carry a field called `items` — a ticket with
                 * line items, say — must not be mistaken for a page, or a
                 * walker would be offered over something that cannot be
                 * walked.
                 */
                Assert::same(
                    self::OBJECT,
                    self::describe(['id' => 't_1', 'items' => [['sku' => 'a']]]),
                    'items without nextCursor is just an object with a list in it',
                );
                Assert::same(
                    self::PAGE,
                    self::describe(['items' => [], 'nextCursor' => null, 'total' => 9]),
                    'and an empty page is still a page — `total` sits beside the envelope',
                );
                Assert::same(
                    'scalar(string)',
                    self::describe('x_1'),
                    'a scalar body is named as one rather than silently counted as an object',
                );
            },

            '§10.10 shapes: the declared column covers all 55 routes, with the documented distribution' => static function (): void {
                $declared = self::declared();
                Assert::same(55, \count($declared), 'one declaration per route on the shared surface');

                foreach ($declared as $label => $shape) {
                    Assert::true(
                        \in_array($shape, self::ALL, true),
                        "{$label} is declared \"{$shape}\", which is not one of the four shapes",
                    );
                }

                $counts = array_fill_keys(self::ALL, 0);
                foreach ($declared as $shape) {
                    ++$counts[$shape];
                }
                /*
                 * Anti-vacuity. See EXPECTED_DISTRIBUTION — a column that
                 * said `object` 55 times satisfies every other assertion in
                 * this suite, and unanimity about nothing is what this
                 * catches.
                 */
                foreach (self::EXPECTED_DISTRIBUTION as $shape => $expected) {
                    Assert::same($expected, $counts[$shape], "the surface carries {$expected} `{$shape}` routes");
                }
                Assert::same(
                    array_keys(self::EXPECTED_DISTRIBUTION),
                    array_keys(array_filter($counts, static fn (int $n): bool => $n > 0)),
                    'all four shapes must stay represented',
                );

                /*
                 * The two hand-written lists in this suite must agree.
                 *
                 * `SurfaceCases::PAGED` is the `paged` column — "this SDK
                 * offers a walker here" — and this is the `shape` column.
                 * They are two statements of one fact and were written
                 * separately; the gate checks the same equivalence on the
                 * manifest's side. A disagreement here is a typo in one of
                 * them, caught before it reaches the lock.
                 */
                $paged = SurfaceCases::PAGED;
                sort($paged);
                Assert::same(
                    $paged,
                    self::withShape(self::PAGE),
                    'the routes declared `page` must be exactly the ones this SDK offers a walker over',
                );
            },
        ];
    }
}

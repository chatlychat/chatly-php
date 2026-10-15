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
 * ─── THE ROWS THAT LOOK WRONG AND ARE NOT ─────────────────────────────────
 *
 *  * `contacts.remove_tag` is a `DELETE` that answers **200 with the
 *    surviving contact** — the same object the tag `POST` returns — not 204.
 *  * `conversations.cancel_scheduled` is a `DELETE` that answers **200 with
 *    the row it just cancelled** (`@ZodResponse(scheduledMessageSchema)`).
 *    In `sdk-node` this was typed as returning `void`, so the body was parsed
 *    and thrown away.
 *  * `macros.delete` is the ONLY real 204 on this surface.
 *  * `reports.agents` and `reports.conversations_by_day` are bare arrays —
 *    per-agent rows and per-day buckets, with no envelope around them — and
 *    so are `reports.sources` and `reports.attribution`, while the other
 *    nine report routes are objects. There is no pattern by name.
 *  * `billing.invoices` PAGINATES and is still an `object`: its envelope is
 *    `{ data, nextCursor }`, which predates the shared helper, so the key
 *    this SDK's pager reads is simply not there.
 *  * `docs_sources.discover` is a POST that answers a bare ARRAY.
 *  * Six more DELETEs answer 200 with a body, so `macros.delete` remains the
 *    only `empty` on the surface — see EXPECTED_DISTRIBUTION.
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
 * {@see ShapeSelfTestCases} proves both halves of that: the classifier splits
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
     * Counted from the 163 handlers' schemas — 55 on 2026-10-12, 104 more
     * on 2026-10-14, then 3 the same day. Each was read off its handler. A
     * column that said `object` 163 times would satisfy every other
     * assertion in this suite and be worthless, which is the only reason
     * these numbers are written down. If the surface grows they change — but
     * all four values must stay represented, and `empty` must stay at exactly
     * one until a second genuine 204 ships.
     *
     * It was 14/10/30/1 at 55 routes. The 104 added 18 arrays, 3 pages and 83
     * objects; the last 3 added `workspace.mine` (array), `root.me` and
     * `webhooks.delete` (objects). `visitors.start_conversation` took `object`
     * from 115 to 116 on 2026-10-14 — the one row here derived from a service
     * return type rather than a `@ZodResponse`, because its handler has none. No addition has ever been `empty`: nothing
     * else on this surface is a real 204, and SIX routes that look like one
     * are not — `ai.delete_tool` and `bot_routes.delete` answer
     * `{ deleted: true }`, `triggers.delete` and `webhooks.delete` answer
     * `{ ok: true }`, `brands.delete` answers `{ id }` on a soft delete, and
     * `email_templates.delete` answers `{ restored }`.
     */
    public const EXPECTED_DISTRIBUTION = [
        self::ARRAY_SHAPE => 33,
        self::PAGE => 13,
        self::OBJECT => 116,
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
     * `ns.name => shape`, for all 163 routes.
     *
     * @return array<string,string>
     */
    public static function declared(): array
    {
        return [
            'root.whoami' => self::OBJECT,
            // `z.discriminatedUnion('principal', [...])` over two OBJECT
            // members — a union of objects is still `object`, not a fifth
            // shape, the same reading `bots.create` gets.
            'root.me' => self::OBJECT,

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
            // `z.array(z.object({ value, group }))`, built from the shared
            // `API_KEY_SCOPES` constant rather than a copy of it.
            'workspace.key_scopes' => self::ARRAY_SHAPE,
            // `{ items, total, truncated }` — an object BECAUSE `truncated`
            // has to go somewhere, and no cursor because nine heterogeneous
            // sources share no sort key.
            'workspace.secrets' => self::OBJECT,
            // `z.array(workspaceListItemSchema)`. It stays a bare array for a
            // key rather than collapsing to the one row it holds — the route
            // is the cross-workspace one and a key is a short list.
            'workspace.mine' => self::ARRAY_SHAPE,

            'contacts.mood' => self::OBJECT,
            // `{ cards: [...] }` — an object wrapping the list.
            'contacts.cards' => self::OBJECT,

            'conversations.unread_counts' => self::OBJECT,
            // `{ items }` and NOTHING ELSE — no `nextCursor`, so an object.
            // The exact body `describe()`'s items-without-a-cursor branch
            // exists to classify correctly.
            'conversations.linked' => self::OBJECT,
            // `{ conversationId, items }` — also not a page.
            'conversations.outcomes' => self::OBJECT,
            'conversations.record_outcome' => self::OBJECT,
            'conversations.return_to_bot' => self::OBJECT,
            'conversations.update_blocks' => self::OBJECT,

            // `z.array(...)`. Nullable fields on the rows, because the query
            // LEFT joins conversations — but the top level is a bare array.
            'tickets.conversations' => self::ARRAY_SHAPE,
            // `{ openTickets }` — one integer, wrapped.
            'tickets.open_count' => self::OBJECT,
            // `{ items, totalMinutes, billableMinutes }` — an `items` key
            // with no cursor beside it, so an object and not a page.
            'tickets.time' => self::OBJECT,
            'tickets.status_history' => self::OBJECT,
            // An IIFE that returns a `z.object`; the refs inside it are not
            // the top level.
            'tickets.lineage' => self::OBJECT,

            'macros.get' => self::OBJECT,
            // `{ limit, actions }`.
            'macros.actions' => self::OBJECT,
            // `{ items, total }` — reads like a list, is an OBJECT. `total`
            // is `count($items)` over a frozen catalogue and is not a hint at
            // a second page; there is no cursor.
            'macros.templates' => self::OBJECT,
            'macros.template' => self::OBJECT,

            // `z.array(endpointListItemSchema)`.
            'webhooks.list' => self::ARRAY_SHAPE,
            'webhooks.create' => self::OBJECT,
            // `{ eventTypes: [...] }` — wrapped, not bare.
            'webhooks.events' => self::OBJECT,
            // `{ latest, versions }`.
            'webhooks.schema_versions' => self::OBJECT,
            'webhooks.set_schema_version' => self::OBJECT,
            // `z.object({ ok: z.literal(true) })` with no `@HttpCode(204)`:
            // a DELETE that answers 200 with a body, like five others here.
            'webhooks.delete' => self::OBJECT,

            // Bare arrays, both, and both say so in their own
            // `@ZodResponse` description in capitals — `sources` because the
            // channel enum bounds it, `attribution` because the tail beyond
            // the row cap is summed into one row.
            'reports.sources' => self::ARRAY_SHAPE,
            'reports.attribution' => self::ARRAY_SHAPE,
            // And the rest are objects. There is no pattern by name: these
            // four carry an echoed window or a cap count, which is why.
            'reports.cohorts' => self::OBJECT,
            'reports.response_times' => self::OBJECT,
            'reports.teams' => self::OBJECT,
            'reports.labels' => self::OBJECT,
            'reports.voice' => self::OBJECT,
            'reports.themes' => self::OBJECT,
            'reports.theme_conversations' => self::OBJECT,

            // `z.array(z.string())` — the ONLY route on this surface whose
            // array holds scalars rather than objects. Still `array`: the
            // declaration is about the TOP LEVEL.
            'channels.adapters' => self::ARRAY_SHAPE,
            'channels.create' => self::OBJECT,
            'channels.update' => self::OBJECT,
            'channels.rotate_secret' => self::OBJECT,

            'billing.summary' => self::OBJECT,
            // `{ data, nextCursor }` — it PAGINATES and it is still an
            // `object`, because the envelope predates the shared helper and
            // uses `data` where every page route uses `items`. Classifying
            // it `page` would hand this SDK's pager a body it cannot read;
            // see Billing::invoices() for why there is no walker.
            'billing.invoices' => self::OBJECT,
            'billing.usage_monthly' => self::OBJECT,
            'billing.usage_daily' => self::OBJECT,

            // `{ providers: [...] }` — wrapped. The registry, not the
            // configured subset.
            'ai.providers' => self::OBJECT,
            'ai.add_provider' => self::OBJECT,
            'ai.test_provider' => self::OBJECT,
            'ai.quota' => self::OBJECT,
            // `toneRuleListResponseSchema` is `z.array(...)`, and BOTH the
            // GET and the PUT answer with it — the write re-reads the set and
            // returns the same bare array, which is unusual enough for the
            // handler's own description to say "Same bare array as GET".
            'ai.tone' => self::ARRAY_SHAPE,
            'ai.set_tone' => self::ARRAY_SHAPE,
            // `{ instruction }`, where an EMPTY string is the normal answer.
            'ai.tone_preview' => self::OBJECT,
            'ai.generate_tone' => self::OBJECT,
            // `z.array(toolResponseSchema)`.
            'ai.tools' => self::ARRAY_SHAPE,
            'ai.create_tool' => self::OBJECT,
            'ai.update_tool' => self::OBJECT,
            // `{ deleted: true }` with a 200 — NOT a 204. `macros.delete` is
            // still the only real 204 on this surface.
            'ai.delete_tool' => self::OBJECT,

            // `botsPageSchema` / `agentDeliveriesPageSchema` —
            // `{ items, nextCursor, hasMore }`, both of them.
            'bots.list' => self::PAGE,
            'bots.deliveries' => self::PAGE,
            'bots.get' => self::OBJECT,
            // `z.union([createdExternalAgentSchema, botDtoSchema])` — a union
            // of two OBJECTS, so object. A union is not a fifth shape.
            'bots.create' => self::OBJECT,
            'bots.update' => self::OBJECT,
            'bots.update_external' => self::OBJECT,
            'bots.rotate_external_secret' => self::OBJECT,
            // `agentDeliveryItemSchema.extend({ payload })`.
            'bots.delivery' => self::OBJECT,
            'bots.redeliver' => self::OBJECT,
            'bots.test_delivery' => self::OBJECT,
            'bots.analytics' => self::OBJECT,

            // `z.array(routeResponseSchema)`, in evaluation order.
            'bot_routes.list' => self::ARRAY_SHAPE,
            // `{ fields: [...] }` — wrapped.
            'bot_routes.fields' => self::OBJECT,
            'bot_routes.create' => self::OBJECT,
            'bot_routes.update' => self::OBJECT,
            // `{ deleted: true }`, a 200 with a body.
            'bot_routes.delete' => self::OBJECT,
            'bot_routes.resolve' => self::OBJECT,

            // `brandListSchema` is `z.array(brandDtoSchema.extend(...))`.
            'brands.list' => self::ARRAY_SHAPE,
            'brands.create' => self::OBJECT,
            'brands.update' => self::OBJECT,
            // A third DELETE that answers 200 with a body: `{ id }`. It is a
            // SOFT delete, which is why there is something to say.
            'brands.delete' => self::OBJECT,

            // `triggersPageSchema` — `{ items, nextCursor, hasMore }`.
            'triggers.list' => self::PAGE,
            'triggers.create' => self::OBJECT,
            // `{ ok: true }` with a 200, on a HARD delete.
            'triggers.delete' => self::OBJECT,

            'canvas_steps.get' => self::OBJECT,
            'canvas_steps.resolve' => self::OBJECT,

            // `z.array(customerCardViewSchema)` — header names, no values.
            'customer_cards.list' => self::ARRAY_SHAPE,
            'customer_cards.create' => self::OBJECT,
            'customer_cards.update' => self::OBJECT,
            'customer_cards.rotate_secret' => self::OBJECT,
            'customer_cards.delete' => self::OBJECT,

            // `z.array(customerCardEndpointViewSchema)` — its own description
            // calls it "a bare array".
            'customer_card_endpoints.list' => self::ARRAY_SHAPE,
            'customer_card_endpoints.create' => self::OBJECT,
            'customer_card_endpoints.rotate_secret' => self::OBJECT,
            'customer_card_endpoints.activate_secret' => self::OBJECT,
            'customer_card_endpoints.delete' => self::OBJECT,

            // Three bare arrays in a row, all `z.array(...)`: the provider
            // registry, the connected sources, and the discovered spaces.
            // `discover` is a POST that answers one, which is the row most
            // likely to be guessed `object` from the verb.
            'docs_sources.providers' => self::ARRAY_SHAPE,
            'docs_sources.list' => self::ARRAY_SHAPE,
            'docs_sources.discover' => self::ARRAY_SHAPE,
            'docs_sources.create' => self::OBJECT,
            'docs_sources.update' => self::OBJECT,
            'docs_sources.sync' => self::OBJECT,
            'docs_sources.webhook' => self::OBJECT,
            'docs_sources.delete' => self::OBJECT,

            // `z.array(emailTemplateResponseSchema)` — all five kinds, the
            // catalogue rather than the rows.
            'email_templates.list' => self::ARRAY_SHAPE,
            'email_templates.preview' => self::OBJECT,
            'email_templates.update' => self::OBJECT,
            // `{ restored }` with a 200. Its own description shouts "HAS A
            // BODY, not a 204".
            'email_templates.delete' => self::OBJECT,

            // `z.array(knowledgeGapResponseSchema)`, most-asked first.
            'knowledge_gaps.list' => self::ARRAY_SHAPE,
            'knowledge_gaps.update' => self::OBJECT,
            // `{ gap, articleId, reason }`, where `articleId` is nullable on
            // the happy path.
            'knowledge_gaps.draft' => self::OBJECT,

            /*
             * `{ conversationId }` — one key, and the only row on this column
             * whose shape could NOT be read off a `@ZodResponse`, because the
             * handler has none. It was read off the service it forwards to
             * instead: `VisitorsService::startProactiveConversation` is
             * declared `Promise<{ conversationId: string }>`
             * (`visitors.service.ts:409`).
             *
             * An object, not a page, and nothing about the route says so from
             * the outside — a POST that opens one thing and answers an id for
             * it is the same shape as `conversations.send`.
             */
            'visitors.start_conversation' => self::OBJECT,
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

}

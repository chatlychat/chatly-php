# chatly/chatly-php

The official PHP SDK for [Chatly](https://chatlychat.com) — live chat and
customer messaging. A REST client for the Chatly API, plus the two crypto
helpers you need to receive webhooks and to turn on widget identity
verification.

- **No runtime dependencies.** `ext-curl` and `ext-json`, both of which your
  PHP already has. No Guzzle, no PSR packages — you should not inherit our
  dependency tree to send a message.
- PHP 8.1+, PSR-4, `declare(strict_types=1)` throughout.
- Typed exceptions, automatic retries with backoff, idempotent writes, and
  auto-pagination as a `Generator`.

```bash
composer require chatly/chatly-php
```

## Quickstart

```php
<?php
require 'vendor/autoload.php';

$chatly = new Chatly\ChatlyClient($_ENV['CHATLY_API_KEY']);   // a ck_… workspace key

$me = $chatly->whoami();                                      // which workspace, which scopes
$contact = $chatly->contacts->upsert(['email' => 'ada@example.com', 'name' => 'Ada']);

foreach ($chatly->conversations->autoList(['limit' => 50]) as $conversation) {
    if (($conversation['status'] ?? null) === 'open') {
        $chatly->conversations->send($conversation['id'], 'Thanks — looking into this now.');
    }
}
```

That is the whole setup. The base URL defaults to `https://api.chatlychat.com`
and you only override it to reach another Chatly environment or a test server.

## This is a BACKEND SDK

It authenticates with a **workspace API key** (`ck_…`), from server-side code.

> **Never ship this key, or anything derived from it, to a browser.** It is a
> bearer credential for the entire workspace — every contact, every
> conversation, every ticket, every report. The browser-side library is a
> different package and authenticates with a public channel id instead.

Create a key in **Settings → API keys**, scope it to what your integration
actually needs, and keep it in your environment, not in your repository.

### Which routes are reachable

Most of the API answers a `ck_` key with **403** because it needs a signed-in
person — a reaction records _who_ reacted, and a key is not a person.

**163** routes are open to a key, and this SDK has a hand-written method for
every one of them: each is an entry in `packages/sdk-spec/surface.json`, the
table below. Anything that opens later is reachable through
[`request()`](#the-escape-hatch) with the same auth, retries, idempotency and
error handling until a typed method lands.

That count is the `keyAccessible` figure in `docs/api-counts.json`, generated
from the deployed OpenAPI document — 162 there and 163 in a local build,
because `POST /v1/visitors/{contactId}/start-conversation` opened on
2026-10-14 and has not shipped yet. Do **not** read it off
`node scripts/report-api-key-surface.mjs`, which counts `@AllowApiKey()`
_decorators_: one class-level decorator opens many handlers and counts once,
so that number is always the larger and is not in the same units. This README
subtracted the two for a while and reported a dozen missing typed methods that
did not exist.

Methods for routes that always 403 are deliberately **absent**. There is no
`conversations->react()` and no `channels->delete()`, because shipping a
method that always fails is worse than not shipping it: the failure arrives at
runtime, in production, in somebody else's integration.

`channels->create()` was on that list and should not have been. Channel
configuration needs `channels:write`, which **is** grantable to a key — it was
added so a developer can stand a widget up entirely from code — so the create,
the patch and the secret rotate are all here. Only the DELETE stays
dashboard-only.

## Construction

```php
use Chatly\ChatlyClient;
use Chatly\RetryPolicy;
use Chatly\Transport;

$chatly = new ChatlyClient(
    apiKey: $_ENV['CHATLY_API_KEY'],      // required; rejected at construction if empty
    baseUrl: null,                         // null or '' → https://api.chatlychat.com
    timeout: 15.0,                         // seconds, per attempt
    retries: new RetryPolicy(maxAttempts: 3),
    transport: new Transport(),            // curl; swap for your own
);
```

| Argument    | Default                      | Notes                                                                                                                                                                                                                                 |
| ----------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `apiKey`    | —                            | Required. An empty or whitespace-only key throws `InvalidArgumentException` immediately, not on the first call.                                                                                                                       |
| `baseUrl`   | `https://api.chatlychat.com` | Another Chatly environment, or a test server; it is also the second positional argument. `null` **and `''`** both mean the default, so an unset `CHATLY_API_URL` lands on production rather than nowhere. A non-`http(s)` URL throws. |
| `timeout`   | `15.0`                       | Seconds allowed per attempt, not per call. Three attempts can take three times this.                                                                                                                                                  |
| `retries`   | 3 attempts                   | See [Retries](#retries). `RetryPolicy::none()` turns it off.                                                                                                                                                                          |
| `transport` | `Chatly\Transport` (curl)    | Any `Chatly\TransportInterface`.                                                                                                                                                                                                      |

### Using your own HTTP stack

If your application already configures a PSR-18 client — proxy, retry
middleware, request logging — route the SDK through it:

```php
use Chatly\Psr18Transport;

$chatly = new ChatlyClient(
    apiKey: $key,
    transport: new Psr18Transport($psr18Client, $psr17RequestFactory, $psr17StreamFactory),
);
```

`psr/http-client` and `psr/http-factory` are **suggestions**, not
requirements: the three collaborators are checked structurally at
construction, so users of the curl transport install nothing extra. Note that
a PSR-18 client's timeout is its own configuration — the `timeout` argument
cannot reach it.

For custom curl behaviour (a corporate proxy, a private CA) without replacing
the transport:

```php
use Chatly\Transport;

$chatly = new ChatlyClient($key, transport: new Transport([
    CURLOPT_PROXY  => 'http://proxy.internal:3128',
    CURLOPT_CAINFO => '/etc/ssl/acme-root.pem',
]));
```

## Method table

Every method below maps to exactly one route. Arrays marked `$query` accept
`cursor`, `limit`, and — on the list endpoints — `filter[]`, `sort` and
`fields`.

### Root

| Method                                                      | Route                                                |
| ----------------------------------------------------------- | ---------------------------------------------------- |
| `$chatly->whoami()`                                         | `GET /v1/api-keys/whoami`                            |
| `$chatly->me()`                                             | `GET /v1/me`                                         |
| `$chatly->request($method, $path, $query, $body, $headers)` | anything — see [the escape hatch](#the-escape-hatch) |

`whoami()` returns `['workspaceId' => …, 'scopes' => [...]]`. Make it first in
any integration: it turns a mis-scoped key into an obvious error now instead
of a 403 from an unrelated endpoint in three weeks.

`me()` answers the same object for a key and is **discriminated on
`principal`** — `'api_key'` for a `ck_` key, `'user'` for a signed-in person —
so it is the one to call from code that might hold either credential. It
declares **no permission at all**, which makes it the better liveness check: a
key with no useful scope still gets a 200, where `whoami()` needs
`workspace:read` and would answer that same key a 403 that tells you nothing
about whether the key, the host or the network was at fault. Both are
`@AllowWhenSuspended`.

### `contacts`

| Method                                                                       | Route                                 |
| ---------------------------------------------------------------------------- | ------------------------------------- |
| `list(array $query = []): Page`                                              | `GET /v1/contacts`                    |
| `autoList(array $query = [], ?int $maxPages = null): Generator`              | walks `GET /v1/contacts`              |
| `get(string $id)`                                                            | `GET /v1/contacts/{id}`               |
| `upsert(array $contact)`                                                     | `POST /v1/contacts`                   |
| `bulkUpsert(array $contacts)`                                                | `POST /v1/contacts/bulk`              |
| `merge(string $id, string $mergedId)`                                        | `POST /v1/contacts/{id}/merge`        |
| `addTags(string $id, string $tag)`                                           | `POST /v1/contacts/{id}/tags`         |
| `removeTag(string $id, string $tag)`                                         | `DELETE /v1/contacts/{id}/tags/{tag}` |
| `unsubscribe(string $id, bool $unsubscribed = true, ?string $reason = null)` | `POST /v1/contacts/{id}/unsubscribe`  |
| `journey(string $id, array $query = [])`                                     | `GET /v1/contacts/{id}/journey`       |
| `mood(string $id, array $query = [])`                                        | `GET /v1/contacts/{id}/mood`          |
| `cards(string $contactId, ?string $conversationId = null)`                   | `POST /v1/contacts/{contactId}/cards` |

- `upsert` keys on `externalId`: sending the same one twice updates rather
  than duplicating.
- `bulkUpsert` takes up to **5000** rows and returns per-row outcome counts
  plus up to 100 row errors. **Read the response** — a 200 does not mean every
  row landed.
- `addTags` adds **one** tag per call, as its signature says; the plural name
  is the shared cross-language surface name.
- `unsubscribe` is **outbound only**. An unsubscribed contact can still open a
  conversation and still lands in your inbox. It is not a block.
- `merge` is irreversible: `$id` survives, `$mergedId` is consumed.
- `journey` is paginated by an ISO-8601 timestamp cursor rather than the
  opaque cursor the list endpoints use, so it has an `autoJourney()` walk but
  no constructible cursor: pass back what you were given.
- `cards` is a **POST that reads**. It calls your own card backends while you
  wait, so it is as slow as your slowest card, and it answers 201 without
  creating anything. `state` on an entry says why a card has no blocks.
- `mood` echoes the window back as `days`; read `sampleCount` before trusting
  the number.

### `conversations`

| Method                                                                                           | Route                                                       |
| ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| `list(array $query = []): Page`                                                                  | `GET /v1/conversations`                                     |
| `autoList(array $query = [], ?int $maxPages = null): Generator`                                  | walks `GET /v1/conversations`                               |
| `get(string $id)`                                                                                | `GET /v1/conversations/{id}`                                |
| `messages(string $id, array $query = []): Page`                                                  | `GET /v1/conversations/{id}/messages`                       |
| `autoMessages(string $id, array $query = [], ?int $maxPages = null): Generator`                  | walks the messages                                          |
| `send(string $id, string $body, array $options = [])`                                            | `POST /v1/conversations/{id}/messages`                      |
| `unattended(array $query = [])`                                                                  | `GET /v1/conversations/unattended`                          |
| `agentHandling(string $id)`                                                                      | `GET /v1/conversations/{id}/agent-handling`                 |
| `setAgentHandling(string $id, string $state, ?string $reason = null)`                            | `PUT /v1/conversations/{id}/agent-handling`                 |
| `generatedReplies(string $id)`                                                                   | `GET /v1/conversations/{id}/generated-replies`              |
| `generateReply(string $id, string $body, ?string $basedOnMessageId = null, array $options = [])` | `POST /v1/conversations/{id}/generated-replies`             |
| `scheduledMessages(string $id)`                                                                  | `GET /v1/conversations/{id}/scheduled-messages`             |
| `scheduleMessage(string $id, string $body, DateTimeInterface\|string $sendAt)`                   | `POST /v1/conversations/{id}/scheduled-messages`            |
| `cancelScheduled(string $scheduledId)`                                                           | `DELETE /v1/conversations/scheduled-messages/{scheduledId}` |

- `send` is the one write into a thread a key can make. `$options['kind']` is
  `text` (the default), `rich`, `note` or `system_event`.
- Each message carries an `llmText` field: that turn rendered for a language
  model, so you do not have to reconstruct it from `body`, `blocks`,
  `attachments` and the `kind`/`authorType` pair.
- Attribution is decided server-side from the key: a plain key sends as
  `system` with no author; a key linked to a machine user sends as that agent.
- `setAgentHandling` takes `in_progress`, `handled` or `handed_off`, and
  **requires a machine principal** — a key with no machine user gets a 403.
- `scheduleMessage` converts a `DateTimeInterface` to UTC `…Z`, which is the
  only form the API's schema accepts (an offset like `+02:00` is a 400). Pass
  a string to format it yourself.
- `cancelScheduled` hangs off the collection, not off a conversation: you need
  only the scheduled-message id.

### `tickets`

| Method                                                          | Route                                               |
| --------------------------------------------------------------- | --------------------------------------------------- |
| `list(array $query = []): Page`                                 | `GET /v1/tickets`                                   |
| `autoList(array $query = [], ?int $maxPages = null): Generator` | walks `GET /v1/tickets`                             |
| `comments(string $id)`                                          | `GET /v1/tickets/{id}/comments`                     |
| `forConversation(string $conversationId)`                       | `GET /v1/tickets/for-conversation/{conversationId}` |
| `queues()`                                                      | `GET /v1/tickets/queues`                            |
| `merge(string $id, string $loserId)`                            | `POST /v1/tickets/{id}/merge`                       |
| `split(string $id, string $title, array $fields = [])`          | `POST /v1/tickets/{id}/split`                       |
| `bulk(array $ids, string $action, array $payload = [])`         | `POST /v1/tickets/bulk`                             |
| `conversations(string $id)`                                     | `GET /v1/tickets/{id}/conversations`                |
| `openCount(string $conversationId)`                             | `GET /v1/tickets/open-count/{conversationId}`       |
| `time(string $id)`                                              | `GET /v1/tickets/{id}/time`                         |
| `statusHistory(string $id)`                                     | `GET /v1/tickets/{id}/status-history`               |
| `lineage(string $id)`                                           | `GET /v1/tickets/{id}/lineage`                      |

- `list` excludes `merged` tickets unless you filter on status explicitly.
- `comments` includes internal notes. Filter on `isInternal` before showing
  them to a customer.
- `bulk` actions are `status`, `assign`, `priority` and `queue`. The response
  reports `updated` plus the ids that were **not** updated and why; ids outside
  your key's inbox scope are refused individually.
- `split` requires a title, and inherits `priority`, `type`, `assigneeUserId`
  and `tags` from the origin when they are omitted.
- `openCount` takes a **conversation** id even though it lives under
  `/v1/tickets`.
- `conversations` LEFT joins, so `subject`, `status` and `channelType` are
  null on a row whose conversation was hard-deleted — the link survives as a
  tombstone.
- `statusHistory` is oldest first and caps at 500; **`truncated` is the only
  thing distinguishing a capped timeline from a short one**.
- `lineage` returns the raw id columns beside the resolved refs, so a null ref
  with a non-null id tells you the target was deleted rather than never set.
- `time` carries a computed `billableMinutes`; do not re-derive it by
  filtering `items`.

### `macros`

| Method                               | Route                             |
| ------------------------------------ | --------------------------------- |
| `list(array $query = [])`            | `GET /v1/macros`                  |
| `create(array $macro)`               | `POST /v1/macros`                 |
| `update(string $id, array $changes)` | `PATCH /v1/macros/{id}`           |
| `delete(string $id)`                 | `DELETE /v1/macros/{id}`          |
| `get(string $id)`                    | `GET /v1/macros/{id}`             |
| `actions()`                          | `GET /v1/macros/actions`          |
| `templates()`                        | `GET /v1/macros/templates`        |
| `template(string $slug)`             | `GET /v1/macros/templates/{slug}` |

**`list()` is not paginated and returns a plain array**, so there is no
`autoList()` for it and no `cursor` or `limit` to pass. The route caps the
result at 200 rows ordered by usage rank, which means the first page is the
answer — paging it would trade a round trip for the least-used macros. The
four filters it does accept are `scope`, `team` (a uuid), `query` (a
substring) and `language` (a BCP-47-ish tag, which **ranks** matching macros
first rather than hiding the rest).

A macro must carry a `body`, `actions`, or both. `scope` is required, and when
it is `team` so is `teamId`. `update` is a partial patch, not a replace.

### `kb`

| Method                                                              | Route                        |
| ------------------------------------------------------------------- | ---------------------------- |
| `articles(array $query = []): Page`                                 | `GET /v1/kb/articles`        |
| `autoArticles(array $query = [], ?int $maxPages = null): Generator` | walks the articles           |
| `article(string $slug)`                                             | `GET /v1/kb/articles/{slug}` |
| `collections()`                                                     | `GET /v1/kb/collections`     |

`articles` is the authoring view and includes drafts; narrow it with
`['filter[]' => ['status:eq:published']]`. `article` takes a **slug**, not an
id. `collections` returns a plain array — the route is deliberately
unpaginated.

### `search`

| Method                                  | Route                            |
| --------------------------------------- | -------------------------------- |
| `query(string $q, array $options = [])` | `GET /v1/search`                 |
| `kb(string $q, array $options = [])`    | `GET /v1/search` with `kinds=kb` |

```php
$hits = $chatly->search->query('refund', ['kinds' => ['ticket', 'conversation'], 'limit' => 10]);
$articles = $chatly->search->kb('how do I cancel');
```

Kinds are `contact`, `conversation`, `message`, `ticket` and `kb`. Note that
`kinds` here is sent **without** brackets, unlike `filter[]` on the list
endpoints — the search route validates a parameter literally named `kinds`,
and a bracketed `kinds[]` is silently ignored, which would hand you results
from every kind while you believed you had asked for one. The SDK gets this
right for you; it matters if you reach the route through `request()`.

### `events`

| Method                                                           | Route                      |
| ---------------------------------------------------------------- | -------------------------- |
| `track(string $contactId, string $name, array $properties = [])` | `POST /v1/cdp/events`      |
| `list(array $query = []): Page`                                  | `GET /v1/cdp/events`       |
| `autoList(array $query = [], ?int $maxPages = null): Generator`  | walks `GET /v1/cdp/events` |

```php
$chatly->events->track($contactId, 'order_placed', ['total' => 4999, 'currency' => 'EUR']);
```

Issue the key for this with the narrow **`contacts:write:events`** scope. The
broader `contacts:write` also reaches `POST /v1/contacts/{id}/merge`, which
destroys a contact row irreversibly — and this is the call you copy into every
service you run. Existing `contacts:write` keys keep working; the broader scope
implies the narrow one.

`list` answers "did my tracking call actually land?". Pass
`['contactId' => …]` to scope it to one person.

### `webhooks` (delivery history)

| Method                                                                | Route                                         |
| --------------------------------------------------------------------- | --------------------------------------------- |
| `deliveries(array $query = []): Page`                                 | `GET /v1/webhooks/deliveries`                 |
| `autoDeliveries(array $query = [], ?int $maxPages = null): Generator` | walks the deliveries                          |
| `delivery(string $id)`                                                | `GET /v1/webhooks/deliveries/{id}`            |
| `redeliver(string $id)`                                               | `POST /v1/webhooks/deliveries/{id}/redeliver` |
| `list()`                                                              | `GET /v1/webhooks`                            |
| `create(string $url, array $eventTypes, array $options = [])`         | `POST /v1/webhooks`                           |
| `events()`                                                            | `GET /v1/webhooks/events`                     |
| `schemaVersions()`                                                    | `GET /v1/webhooks/schema-versions`            |
| `setSchemaVersion(string $id, string $schemaVersion)`                 | `PATCH /v1/webhooks/{id}/schema-version`      |
| `delete(string $id)`                                                  | `DELETE /v1/webhooks/{id}`                    |

Narrow the list with `endpointId` and `status`. `delivery` includes the exact
body we POSTed, which is what you need to re-verify a signature offline.
`redeliver` **queues**: the response says `queued: true` and the outcome
appears as a new attempt in the list, sent to the endpoint's _current_ url.

`create()` is the only response that ever carries `secret` — there is no
rotate and no re-read. After `setSchemaVersion()`, read `missingEventTypes`:
it names the events your subscription asked for that the version you pinned
does not carry, which is the difference between a pin that worked and a
subscription that has gone quiet.

`delete()` was the one route on this controller a key could not reach until
2026-10-14 — create and the schema-version patch both carried
`@AllowApiKey()` and it did not, so a key could ADD a delivery target and
never remove one. A deploy pipeline that registers an endpoint per environment
leaked one on every run. **Do not "disable" a subscription by repointing it at
a dead url**: the subscription stays alive, every event still fans out to it,
and the delivery log fills with failures. Delete it.

Verifying an **incoming** delivery is [a different thing](#webhook-verification)
and needs no API call.

### `reports`

| Method                                                      | Route                                       |
| ----------------------------------------------------------- | ------------------------------------------- |
| `overview(?int $days = null)`                               | `GET /v1/reports/overview`                  |
| `agents(?int $days = null)`                                 | `GET /v1/reports/agents`                    |
| `conversationsByDay(?int $days = null)`                     | `GET /v1/reports/conversations-by-day`      |
| `sla(array $query = [])`                                    | `GET /v1/reports/sla`                       |
| `sources(?int $days = null)`                                | `GET /v1/reports/sources`                   |
| `attribution(?string $dimension = null, ?int $days = null)` | `GET /v1/reports/attribution`               |
| `cohorts(?int $weeks = null)`                               | `GET /v1/reports/cohorts`                   |
| `responseTimes(?int $days = null)`                          | `GET /v1/reports/response-times`            |
| `teams(array $query = [])`                                  | `GET /v1/reports/teams`                     |
| `labels(array $query = [])`                                 | `GET /v1/reports/labels`                    |
| `voice(?int $days = null)`                                  | `GET /v1/reports/voice`                     |
| `themes(?string $date = null)`                              | `GET /v1/reports/themes`                    |
| `themeConversations(string $id)`                            | `GET /v1/reports/themes/{id}/conversations` |

`days` is clamped server-side to 1–90 (default 7, except `conversationsByDay`
which defaults to 14). An unparseable value falls back to the default rather
than erroring, so read the window in the response instead of assuming yours
was used. `sla` takes a date range (`from`, `to`, `group_by`,
`business_hours`) and caps its breach list at 200 rows.

Four of these answer **bare arrays** (`agents`, `conversationsByDay`,
`sources`, `attribution`) and the other nine answer objects; there is no
pattern by name. None has a cursor — where a rollup could grow without bound
it is capped instead, and each reports its cap differently, so read the cap
rather than the row count. `attribution` is the one route here that **refuses**
an unknown parameter instead of defaulting, because a typo silently answered
as `utm_source` would be a confident report of the wrong thing.
`themeConversations` answers 200 with an empty list for an unknown id, so it
cannot tell you the id was wrong.

### `channels`, `billing`, `workspace`

| Method                                                               | Route                                  |
| -------------------------------------------------------------------- | -------------------------------------- |
| `$chatly->channels->list()`                                          | `GET /v1/channels`                     |
| `$chatly->channels->get(string $id)`                                 | `GET /v1/channels/{id}`                |
| `$chatly->channels->adapters()`                                      | `GET /v1/channels/adapters`            |
| `$chatly->channels->create(array $channel)`                          | `POST /v1/channels`                    |
| `$chatly->channels->update(string $id, array $changes)`              | `PATCH /v1/channels/{id}`              |
| `$chatly->channels->rotateSecret(string $id)`                        | `POST /v1/channels/{id}/secret/rotate` |
| `$chatly->billing->plans()`                                          | `GET /v1/billing/plans`                |
| `$chatly->billing->usage(string $eventType, array $query = [])`      | `GET /v1/billing/usage`                |
| `$chatly->billing->summary()`                                        | `GET /v1/billing`                      |
| `$chatly->billing->invoices(array $query = [])`                      | `GET /v1/billing/invoices`             |
| `$chatly->billing->usageMonthly(array $query = [])`                  | `GET /v1/billing/usage/monthly`        |
| `$chatly->billing->usageDaily(array $query = [])`                    | `GET /v1/billing/usage/daily`          |
| `$chatly->workspace->settings()`                                     | `GET /v1/workspace/settings`           |
| `$chatly->workspace->labels()`                                       | `GET /v1/labels`                       |
| `$chatly->workspace->inboxes()`                                      | `GET /v1/inboxes`                      |
| `$chatly->workspace->teamMembers(string $teamId, array $query = [])` | `GET /v1/teams/{id}/members`           |
| `$chatly->workspace->agentSkills()`                                  | `GET /v1/agents/skills`                |
| `$chatly->workspace->keyScopes()`                                    | `GET /v1/api-keys/scopes`              |
| `$chatly->workspace->secrets()`                                      | `GET /v1/workspace/secrets`            |
| `$chatly->workspace->mine()`                                         | `GET /v1/workspaces/me`                |

`billing->invoices()` **paginates but is not a `Page`**: its envelope is
`{ data, nextCursor }`, not the `{ items, nextCursor }` every other list
returns, so there is no `autoInvoices()` — pass `nextCursor` back as `cursor`
yourself. `channels->adapters()` is the one route here whose array holds
plain strings rather than objects. `workspace->secrets()` reports existence
and timestamps only, never a value, and its `truncated` flag is the thing to
read before concluding a credential does not exist. `workspace->mine()` answers a
**bare array** and stays one for a key — a list of the single workspace it was
issued in, which makes it the cheapest way to confirm which tenant a key
actually points at. Its `role` is a synthetic `restricted` for every key, not
a stored membership role, so do not branch on it.

Each channel row carries
`originsRestricted` — check it on a web channel, because an **empty**
allowed-origins list means _any_ origin and that is invisible if you only look
at the list.

`teamMembers()` is where you get the `userId` values that `assigneeUserId`
fields expect. A key cannot enumerate teams, so the team id has to come from
the dashboard or from a human session.

`billing->usage()` reports month-to-date usage for **one** event type, and the
event type is required — it is not a rollup across all of them. The four the
API accepts are `conversation.opened`, `message.sent.agent`,
`message.sent.bot` and `ai.tokens`, also available as
`Chatly\Resources\Billing::USAGE_EVENT_TYPES`; anything else throws
`InvalidArgumentException` at the call site rather than costing a round trip
to learn the same thing. The window defaults to the current calendar month in
UTC, and you can narrow it with `from` and `to`:

```php
$tokens = $chatly->billing->usage('ai.tokens');
$september = $chatly->billing->usage('conversation.opened', [
    'from' => new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
    'to'   => new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
]);
// ['workspaceId' => …, 'eventType' => …, 'from' => …, 'to' => …, 'total' => …, 'count' => …]
```

It needs `workspace:billing:read`, an owner-level scope.

`agentSkills()` returns the workspace's skill _taxonomy_ —
`['skills' => [...]]`, the distinct labels anyone holds. An empty array is a
normal first-load state. Which skills one agent holds
(`GET /v1/agents/{userId}/skills`) needs a signed-in person and is not
reachable with a key.

### `ai`

| Method                                          | Route                                   |
| ----------------------------------------------- | --------------------------------------- |
| `providers()`                                   | `GET /v1/ai-providers`                  |
| `addProvider(string $provider, string $apiKey)` | `POST /v1/ai-providers`                 |
| `testProvider(string $provider)`                | `POST /v1/ai-providers/{provider}/test` |
| `quota()`                                       | `GET /v1/ai/quota`                      |
| `tone()`                                        | `GET /v1/ai/tone`                       |
| `setTone(array $rules)`                         | `PUT /v1/ai/tone`                       |
| `tonePreview()`                                 | `GET /v1/ai/tone/preview`               |
| `generateTone(string $styleGuide)`              | `POST /v1/ai/tone/generate`             |
| `tools()`                                       | `GET /v1/ai/tools`                      |
| `createTool(array $tool)`                       | `POST /v1/ai/tools`                     |
| `updateTool(string $id, array $changes)`        | `PATCH /v1/ai/tools/{id}`               |
| `deleteTool(string $id)`                        | `DELETE /v1/ai/tools/{id}`              |

- `providers()` returns the **whole supported registry** with a `configured`
  flag per row, not the subset you have set up. Read the flag.
- `addProvider()` validates the key against the provider by making a real
  call, so it is slow and can fail for reasons that are not your request's.
  Only an authentication-class answer refuses the save; an outage stores the
  key as `unverified`, which is a state, not an error. Read
  `validation.status`.
- `tone()` and `setTone()` both answer a **bare array**. `setTone()` is a
  REPLACE: omitting a rule deletes it.
- `tonePreview()` returns an **empty** `instruction` for a workspace that has
  configured nothing. No house style is invented.
- `generateTone()` proposes and **does not save**.
- On `updateTool()`, `secretHeaders` has three states: omit to keep, `null` to
  clear, a map to replace. A form that round-trips an empty value wipes your
  credential.

### `bots`

| Method                                                                                 | Route                                                  |
| -------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| `list(array $query = []): Page`                                                        | `GET /v1/bots`                                         |
| `autoList(array $query = [], ?int $maxPages = null): Generator`                        | walks `GET /v1/bots`                                   |
| `get(string $id)`                                                                      | `GET /v1/bots/{id}`                                    |
| `create(array $bot)`                                                                   | `POST /v1/bots`                                        |
| `update(string $id, array $changes)`                                                   | `PATCH /v1/bots/{id}`                                  |
| `updateExternal(string $id, array $changes)`                                           | `PATCH /v1/bots/{id}/external`                         |
| `rotateExternalSecret(string $id, string\|DateTimeInterface\|null $activateAt = null)` | `POST /v1/bots/{id}/external/rotate-secret`            |
| `deliveries(string $id, array $query = []): Page`                                      | `GET /v1/bots/{id}/deliveries`                         |
| `autoDeliveries(string $id, array $query = [], ?int $maxPages = null): Generator`      | walks the deliveries                                   |
| `delivery(string $id, string $deliveryId)`                                             | `GET /v1/bots/{id}/deliveries/{deliveryId}`            |
| `redeliver(string $id, string $deliveryId)`                                            | `POST /v1/bots/{id}/deliveries/{deliveryId}/redeliver` |
| `testDelivery(string $id)`                                                             | `POST /v1/bots/{id}/test-delivery`                     |
| `analytics(string $id, ?int $days = null)`                                             | `GET /v1/bots/{id}/analytics`                          |

- `create()` takes a **union of two disjoint bodies** — a Chatly persona
  (omit `type`) or an external agent (`type: 'external'`). Neither half is
  strict, so **a field from the wrong one is dropped, not refused**: copy the
  external example while meaning to create a persona and you get a 201 and a
  bot with no prompt.
- On `type: 'external'`, the response carries a signing secret and a `cb_`
  token **shown once**.
- `rotateExternalSecret()` with no `$activateAt` is a **hard cut**: the old
  secret stops signing the moment it returns, and the response's
  `activatesAt` is `null`. Pass `$activateAt` (an ISO 8601 string, sent
  verbatim, or a `DateTimeInterface`, sent as UTC `Z`) for an **overlap
  window**: the old secret keeps signing every delivery until that instant,
  then the new one takes over, and `activatesAt` echoes it. Each delivery
  carries one signature, so hold both secrets in your verifier for the
  window. At most 168 hours ahead (a 400 beyond that, judged by the server's
  clock); an instant already past is a hard cut.
- A `deliveries()` row does **not** carry the signed payload; read one
  delivery for that.
- `analytics()` reports p50/p95/max and never a mean, and **every rate is
  `null`, not 0, when its `sampleSize` is 0**. It takes `days` only —
  `from`, `to`, `weeks` and `date` are refused by name.
- `testDelivery()` reports what your endpoint answered, with the signature
  header and the exact signed payload. It settles every "is it my verifier or
  your signature" argument without a packet capture.

### `botRoutes`

| Method                               | Route                         |
| ------------------------------------ | ----------------------------- |
| `list()`                             | `GET /v1/bot-routes`          |
| `fields()`                           | `GET /v1/bot-routes/fields`   |
| `create(array $route)`               | `POST /v1/bot-routes`         |
| `update(string $id, array $changes)` | `PATCH /v1/bot-routes/{id}`   |
| `delete(string $id)`                 | `DELETE /v1/bot-routes/{id}`  |
| `resolve(array $event = [])`         | `POST /v1/bot-routes/resolve` |

`list()` is in **evaluation order** — that ordering is the answer, so do not
re-sort it and expect the result to describe what the server does. `resolve()`
is a dry run that changes nothing and needs only `triggers:read`; it returns
the selected route or null, every route with its verdict, **and the event
object the conditions were evaluated against**, which is the part that finds a
condition keyed on a context key that is not where you thought.

### `brands`

| Method                               | Route                    |
| ------------------------------------ | ------------------------ |
| `list()`                             | `GET /v1/brands`         |
| `create(array $brand)`               | `POST /v1/brands`        |
| `update(string $id, array $changes)` | `PATCH /v1/brands/{id}`  |
| `delete(string $id)`                 | `DELETE /v1/brands/{id}` |

`domain` is the help-centre hostname; `replyDomain` is the mail domain
notification replies are addressed at and needs an MX record. They are
usually different, and pointing an MX at a help-centre hostname is the mistake
the two fields exist to keep apart. `delete()` is a **soft** delete and
answers `{ id }`, not a 204.

### `triggers`

| Method                                                          | Route                      |
| --------------------------------------------------------------- | -------------------------- |
| `list(array $query = []): Page`                                 | `GET /v1/triggers`         |
| `autoList(array $query = [], ?int $maxPages = null): Generator` | walks `GET /v1/triggers`   |
| `create(array $trigger)`                                        | `POST /v1/triggers`        |
| `delete(string $id)`                                            | `DELETE /v1/triggers/{id}` |

`create()` is an **upsert keyed on `id`** and a REPLACE, not a merge: send
every field you want to keep, because `{id, name}` drops the rule's conditions
and actions. It answers **201 on both arms**, so a 201 does not mean something
new was created. `delete()` is a **hard** delete that answers `{ ok: true }`
with a 200.

### `canvasSteps`

| Method                               | Route                                |
| ------------------------------------ | ------------------------------------ |
| `get(string $id)`                    | `GET /v1/canvas-steps/{id}`          |
| `resolve(string $id, array $canvas)` | `POST /v1/canvas-steps/{id}/resolve` |

When your app answers a widget canvas with `{ pending: true }`, the visitor
watches a progress line and a step row is written; `resolve()` replaces it
with the next screen. The conversation and message ids come from the step id
and are echoed back, which is the pair to write into your own ledger beside a
charge. A **404** means the id names nothing here — **including inside the
very request that answered `{ pending: true }`**, because the row is written
after we answer the visitor. A **409** means already resolved or expired, and
is safe to read as "somebody else won".

### `customerCards`

| Method                               | Route                                        |
| ------------------------------------ | -------------------------------------------- |
| `list()`                             | `GET /v1/customer-cards`                     |
| `create(array $card)`                | `POST /v1/customer-cards`                    |
| `update(string $id, array $changes)` | `PATCH /v1/customer-cards/{id}`              |
| `rotateSecret(string $id)`           | `POST /v1/customer-cards/{id}/rotate-secret` |
| `delete(string $id)`                 | `DELETE /v1/customer-cards/{id}`             |

`create()` returns `secret` beside the card and is the **only** response that
ever does. The url is fetched by our server, not a browser, so it is guarded
against SSRF: https only, no credentials, nothing resolving to a private or
link-local address. On `update()`, **`headers: {}` clears them** — absence and
an empty map are different. `rotateSecret()` has no overlap window; update
your endpoint first, or use a per-url endpoint secret, which does overlap.

### `customerCardEndpoints`

| Method                       | Route                                                   |
| ---------------------------- | ------------------------------------------------------- |
| `list()`                     | `GET /v1/customer-card-endpoints`                       |
| `create(string $url)`        | `POST /v1/customer-card-endpoints`                      |
| `rotateSecret(string $id)`   | `POST /v1/customer-card-endpoints/{id}/rotate-secret`   |
| `activateSecret(string $id)` | `POST /v1/customer-card-endpoints/{id}/activate-secret` |
| `delete(string $id)`         | `DELETE /v1/customer-card-endpoints/{id}`               |

One secret per **url** instead of per card, with a two-phase rotation that has
a real overlap window: `rotateSecret()` mints the next secret (`active:
false`, signing nothing), `activateSecret()` promotes it. The trade is that a
signed request to that url **stops carrying `x-chatly-card-id`**, so adopting
or dropping an endpoint secret is a change at your verifier, not a free
upgrade. Deploy the secret before `create()`, not after.

### `docsSources`

| Method                                 | Route                                |
| -------------------------------------- | ------------------------------------ |
| `providers()`                          | `GET /v1/docs-sources/providers`     |
| `list()`                               | `GET /v1/docs-sources`               |
| `discover(array $request)`             | `POST /v1/docs-sources/discover`     |
| `create(array $source)`                | `POST /v1/docs-sources`              |
| `update(string $id, array $changes)`   | `PATCH /v1/docs-sources/{id}`        |
| `sync(string $id, ?bool $full = null)` | `POST /v1/docs-sources/{id}/sync`    |
| `webhook(string $id)`                  | `POST /v1/docs-sources/{id}/webhook` |
| `delete(string $id)`                   | `DELETE /v1/docs-sources/{id}`       |

- `discover()` is a POST that answers a **bare array** and connects nothing.
  An org-scoped token cannot list organisations, so pass `organizationId` if
  discovery fails oddly.
- `create()` enqueues the first sync before returning, so the source comes
  back `syncStatus: 'queued'` with no counts. Two sources over one space with
  different `pathPrefix` values get their own collections, which is the only
  way to express per-audience help scoping.
- `webhook()` answers **201 with `subscribed: false`** and a reason when the
  token cannot manage webhooks. That is a success, not an error — read the
  flag.
- `delete()` keeps the articles; `kept` says how many stayed.
- No response on this namespace carries the provider token back.

### `emailTemplates`

| Method                                                 | Route                               |
| ------------------------------------------------------ | ----------------------------------- |
| `list()`                                               | `GET /v1/email-templates`           |
| `preview(string $kind, string $subject, string $body)` | `POST /v1/email-templates/preview`  |
| `update(string $kind, string $subject, string $body)`  | `PUT /v1/email-templates/{kind}`    |
| `delete(string $kind)`                                 | `DELETE /v1/email-templates/{kind}` |

The id in these paths is the **kind**, not a uuid — five fixed kinds, listed
in `EmailTemplates::KINDS`. `list()` always returns all five, with `override`
null where the workspace is on the built-in copy, so the length is never the
answer. `preview()` reports **tokens you wrote that the kind does not
supply**, which is how an editor catches a typo before it is ever sent.
`delete()` removes the override rather than copying today's default, so the
workspace follows the built-in copy from then on — and it answers
`{ restored: false }` with a 200 when there was nothing to remove.

### `knowledgeGaps`

| Method                               | Route                                |
| ------------------------------------ | ------------------------------------ |
| `list(array $query = [])`            | `GET /v1/knowledge-gaps`             |
| `update(string $id, string $status)` | `PATCH /v1/knowledge-gaps/{id}`      |
| `draft(string $id)`                  | `POST /v1/knowledge-gaps/{id}/draft` |

`list()` is **most-asked first** — the top row is the article worth writing
next — and a bare array, capped by the recorder at 200 open gaps. An
unrecognised `status` filter is **ignored, not refused**, so you get every gap
with a 200; the same value on `update()` is a 400. `draft()` answers 201 with
a **nullable `articleId`**: a gap with no conversation to draft from gets a
null and a `reason`. The article is a draft and is not published until a
person publishes it.

### `visitors`

| Method                          | Route                                              |
| ------------------------------- | -------------------------------------------------- |
| `startConversation(string $id)` | `POST /v1/visitors/{contactId}/start-conversation` |

Opens a thread with a contact who did **not** write to you first, and answers
`{ conversationId }`. Takes no body: the contact id is the whole input.

**Off by default.** A `PermissionException` here is more often the workspace
setting than your scopes — `settings.proactiveOutreach.apiKeysMayStartConversations`
has to be switched on by an owner or admin with
`PATCH /v1/workspace/settings`, which needs a signed-in person. You can read
the current value with `$chatly->workspace->settings()`. `conversations:write`
was already grantable and is already held by keys minted for ticket bulk
actions and schedule-send, so the scope was never the gate.

**Three per contact per hour**, counted per contact rather than per caller,
because the abuse shape is several threads aimed at one person. The fourth
call in the window is a `RateLimitException` — but **`getRetryAfter()` returns
null on it**. That limit sends no `Retry-After` header of any kind; the wait is
in the response body, at
`$e->getData()['details']['retryAfterSeconds']`. The route also inherits the
global throttler buckets, and a 429 from one of those does carry the suffixed
headers `getRetryAfter()` reads, so test for the value rather than assuming a
429 supplies one.

**An offline visitor is not notified** — no email, no push, no provider
message. The greeting is persisted and the widget renders it from history on
their next page view, so an integration that needs to reach somebody who has
left the site wants an email or SMS conversation instead.

The two other routes on that controller — `GET /v1/visitors/live` and
`GET /v1/visitors/{contactId}/pages` — carry no `@AllowApiKey()` and answer a
key with 403, so there is deliberately no method for either.

## Error handling

Every failure is a `Chatly\Errors\ChatlyException` or a subclass of it. Catch
the one you want to branch on; catch the base class for everything.

```php
use Chatly\Errors\ChatlyException;
use Chatly\Errors\NotFoundException;
use Chatly\Errors\PermissionException;
use Chatly\Errors\RateLimitException;
use Chatly\Errors\TransportException;
use Chatly\Errors\ValidationException;

try {
    $chatly->contacts->upsert(['email' => $email]);
} catch (ValidationException $e) {
    // 400/422 — your payload. $e->getDetails() has the per-field issues.
    report($e->getDetails());
} catch (PermissionException $e) {
    // 403 — the key is valid but lacks a scope. $chatly->whoami() shows which it has.
    throw new RuntimeException('Chatly key is under-scoped', 0, $e);
} catch (RateLimitException $e) {
    // 429 that survived the retry budget.
    retryIn($e->getRetryAfter() ?? 60);
} catch (TransportException $e) {
    // Never reached the API at all: DNS, connect, TLS, timeout.
    markDegraded($e->getMessage());
} catch (ChatlyException $e) {
    logger()->error('chatly', [
        'status' => $e->getStatus(),
        'code'   => $e->getErrorCode(),
        'body'   => $e->getBody(),
    ]);
}
```

| Class                     | When                                                           |
| ------------------------- | -------------------------------------------------------------- |
| `AuthenticationException` | 401 — the key is missing, malformed, revoked or expired        |
| `PermissionException`     | 403 — valid key, missing scope                                 |
| `NotFoundException`       | 404 — no such resource _in this workspace_                     |
| `ConflictException`       | 409 — conflicting write, including an idempotency-key mismatch |
| `ValidationException`     | 422 **and 400** — the request did not validate                 |
| `RateLimitException`      | 429 — plus `getRetryAfter(): ?int`                             |
| `ServerException`         | 5xx                                                            |
| `TransportException`      | no response at all; `getStatus()` is `0`                       |
| `ChatlyException`         | the base, and any other non-2xx                                |

Every one of them exposes:

| Accessor                                | Returns                                                               |
| --------------------------------------- | --------------------------------------------------------------------- |
| `getStatus(): int`                      | HTTP status, `0` for a transport failure                              |
| `getCode()` / `getErrorCode(): ?string` | the API's `code` (`not_found`, `validation_failed`, …)                |
| `getMessage(): string`                  | the API's `message`, or a readable fallback                           |
| `getRequestId(): ?string`               | the `x-request-id` response header                                    |
| `getBody(): string`                     | the raw response body, for anything the above loses                   |
| `getData(): ?array`                     | the decoded error object, unwrapped from its `{"error":{…}}` envelope |
| `getHeaders(): array`                   | response headers, names lowercased                                    |

Three things worth knowing:

- **A 400 is a `ValidationException`.** The API answers a failed schema parse
  with 400 and the code `validation_failed`, not 422. Both land in the same
  class because they are the same branch for you; `getStatus()` still tells
  you which arrived.
- **`getRequestId()` is the API's own request id.** The API mints one per
  request and sends `x-request-id` on every response, errors included, so it
  is normally present. Quote it in a support ticket — it finds the exact
  request in our logs. Send your own `x-request-id` and the API reuses that
  value, which is the cheaper way to correlate a call with your own logs.
- **A 404 is also what a cross-workspace id looks like.** Row-level security
  scopes every read to your key's workspace, so another tenant's id is
  indistinguishable from a deleted one.

## Retries

Connection failures, 429 and 5xx are retried. **No other 4xx ever is** — a
400, 401, 403, 404, 409 or 422 means the request was wrong, and repeating it
burns your rate-limit budget to arrive at the same refusal.

```php
use Chatly\RetryPolicy;

new RetryPolicy(
    maxAttempts: 3,          // total, including the first. 1 disables retrying.
    baseDelay: 0.5,          // seconds for the first backoff step
    maxDelay: 8.0,           // ceiling for a computed step
    maxRetryAfter: 60.0,     // ceiling for a server-supplied wait
    honourRetryAfter: true,
);

$chatly = new ChatlyClient($key, retries: RetryPolicy::none());  // own your own loop
```

Backoff is **equal jitter**: half the computed step, plus a random share of
the other half. `Retry-After` wins when the server sends one; if it asks for
longer than `maxRetryAfter` the error is raised instead, so a fifteen-minute
wait cannot park a web request — read `getRetryAfter()` and decide for
yourself.

Rate limiting on this API is per-bucket, and the headers are suffixed
(`Retry-After-short`, `Retry-After-login`); **there is no plain
`Retry-After`**. `getRetryAfter()` reads both forms and returns the largest
value found, because waiting for the shortest bucket would retry into a wall
that is still up.

## Idempotency

Every write (`POST`, `PUT`, `PATCH`, `DELETE`) carries an `Idempotency-Key`. By
default the SDK mints a fresh UUID per call, so an SDK-level retry cannot
double-write: the key is minted **once**, before the first attempt, and every
retry re-sends it.

To retry the _same_ logical operation yourself — across a job restart, say —
pin a key you derive from your own data:

```php
$key = 'order-' . $order->id . ':welcome-contact';

$chatly->withIdempotencyKey($key)->contacts->upsert([
    'externalId' => (string) $order->customer_id,
    'email'      => $order->email,
]);
```

Same key + same body replays the first response. Same key + a **different**
body is a 409 (`ConflictException`). `withIdempotencyKey()` returns a new
client and leaves the original alone; a key must be 16–128 characters from the
unreserved + sub-delims URI set, and anything else throws where you set it
rather than 400ing later.

## Pagination

List endpoints return `Chatly\Pagination\Page`:

```php
$page = $chatly->contacts->list(['limit' => 100]);

$page->items;        // array<int,mixed>
$page->nextCursor;   // ?string — null on the last page
$page->raw;          // the whole decoded body, so nothing is lost
$page->hasMore();    // ?bool — the API's own flag, where it sends one
$page->isLastPage(); // bool
count($page);        // rows on this page
foreach ($page as $row) { /* … */ }
```

Or let the SDK walk it. Every paged namespace has an `auto…()` method that
returns a `Generator`, so memory stays flat however many rows there are:

```php
foreach ($chatly->contacts->autoList(['limit' => 100]) as $contact) {
    echo $contact['email'] ?? '(none)', PHP_EOL;
}

// bounded, when you only want a sample
foreach ($chatly->tickets->autoList([], maxPages: 3) as $ticket) { /* … */ }
```

`autoList`, `autoJourney`, `autoMessages`, `autoUnattended`, `autoArticles`,
`autoQuery`, `autoDeliveries` — one per paged route, and there are **ten** of
those. `macros->list()` is **not** one of them: that route is
pagination-exempt and returns a plain array.

A walk stops on a null cursor, and it stops if the server ever hands back a
cursor it has already followed, so a server-side bug becomes a truncated
result rather than an infinite loop inside your `foreach`.

It also stops **loudly** if a paged route answers with something that is not
a page. A bare JSON array, or an object with no `items`, raises a
`ChatlyException` with code `unexpected_response_shape` rather than being read
as a single cursorless page — because that reading makes a truncated result
indistinguishable from a complete one, and it is what one of the other
language clients shipped. To read a genuinely unpaginated route through the
same interface, say so with `Page::fromList($rows)`.

> **Never sort or page on `id`.** Ids in this product are a mix of UUID v4 and
> v7, so `id` order is not time order. The cursor is the only correct
> sequence; lists come back newest-first by their own timestamp.

Filtering uses the bracketed form, and repeated keys stay repeated —
`['filter[]' => ['status:open', 'priority:urgent']]` becomes
`?filter[]=status:open&filter[]=priority:urgent`, never one comma-joined
value.

## Webhook verification

Every delivery Chatly POSTs to your endpoint carries:

```
x-livechat-signature: t=<unix seconds>,v1=<hex>
```

`v1` is `HMAC-SHA256("<t>." + raw body, secret)` — the timestamp is bound
**into** the digest, which is what makes the replay window enforceable.

```php
use Chatly\Webhooks;

if (!Webhooks::verifySignature($rawBody, $signatureHeader, $_ENV['CHATLY_WEBHOOK_SECRET'])) {
    http_response_code(400);
    exit;
}
```

It returns `true` or `false` and **never throws** — not for a malformed
header, a missing field, a non-numeric timestamp, a stale timestamp, or an
empty secret. A verifier that throws is an accepted webhook in a handler that
forgot to catch.

| Parameter          | Default      |                                                          |
| ------------------ | ------------ | -------------------------------------------------------- |
| `$body`            | —            | the **raw bytes** received                               |
| `$signatureHeader` | —            | the `x-livechat-signature` value                         |
| `$secret`          | —            | the endpoint's `whsec_…` signing secret                  |
| `$tolerance`       | `300.0`      | seconds the timestamp may differ from now; `INF` to skip |
| `$now`             | system clock | unix seconds, for tests and archive replay               |

### Pass the RAW body

`json_encode(json_decode($body))` reorders keys and changes whitespace, and
the digest will not match. Take the bytes.

**Laravel**

```php
// routes/web.php — exclude this route from CSRF verification.
use Chatly\Webhooks;
use Illuminate\Http\Request;

Route::post('/webhooks/chatly', function (Request $request) {
    $ok = Webhooks::verifySignature(
        $request->getContent(),                        // raw, not $request->all()
        (string) $request->header(Webhooks::SIGNATURE_HEADER, ''),
        config('services.chatly.webhook_secret'),
    );

    if (!$ok) {
        return response()->noContent(400);
    }

    ProcessChatlyEvent::dispatch($request->json()->all());

    // 2xx fast. The platform retries anything else, and your queue is the
    // right place for work that can fail.
    return response()->noContent(202);
});
```

**Symfony**

```php
use Chatly\Webhooks;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChatlyWebhookController
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $chatlyWebhookSecret,
    ) {
    }

    #[Route('/webhooks/chatly', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $ok = Webhooks::verifySignature(
            $request->getContent(),                    // raw, not ->toArray()
            (string) $request->headers->get(Webhooks::SIGNATURE_HEADER, ''),
            $this->chatlyWebhookSecret,
        );

        if (!$ok) {
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        $event = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        // … enqueue it …

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
```

**Plain PHP**

```php
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_LIVECHAT_SIGNATURE'] ?? '';
```

### Re-verifying an archived delivery

```php
$delivery = $chatly->webhooks->delivery($id);
Webhooks::verifySignature($delivery['body'], $delivery['signature'], $secret, INF);
```

## Identity hash

When a workspace turns identity verification on, the widget must prove the
`externalId` it claims came from your backend rather than a browser console —
otherwise anyone can open a chat as anyone else's account and read their
history.

```php
use Chatly\Identity;

$userHash = Identity::hash($_ENV['CHATLY_IDV_SECRET'], (string) $user->id);
```

```html
<script>
  Chatly('identify', {
    externalId: <?= json_encode((string) $user->id) ?>,
    userHash:   <?= json_encode($userHash) ?>,
    email:      <?= json_encode($user->email) ?>,
  });
</script>
```

Lowercase hex HMAC-SHA256 of the id under the workspace's
identity-verification secret. **The secret must never reach a browser — only
the digest does.** It is a workspace-wide credential: with it, anyone can
impersonate every one of your users to your own support team. An empty secret
or an empty id throws `InvalidArgumentException`, because both would produce a
perfectly well-formed digest that authenticates nothing.

## The escape hatch

12 of the 174 key-accessible routes have no typed method here. Reach them
without losing auth, retries, idempotency or typed errors:

```php
// Any read
$roster = $chatly->request('GET', '/v1/teams/' . $teamId . '/members');

// Any write — an Idempotency-Key is added automatically
$comment = $chatly->request('POST', '/v1/tickets/' . $id . '/comments', body: [
    'body'       => 'Refund processed.',
    'isInternal' => true,
]);

// Repeated query parameters: a list value becomes repeated keys
$open = $chatly->request('GET', '/v1/tickets', ['filter[]' => ['status:open'], 'limit' => 50]);
```

```php
public function request(
    string $method,
    string $path,
    array $query = [],
    mixed $body = null,
    array $headers = [],
): mixed
```

- Returns the decoded JSON, or `null` for a 204.
- `POST`/`PUT`/`PATCH`/`DELETE` get an `Idempotency-Key`; a `null` body on
  `POST`/`PUT`/`PATCH` is sent as `{}`.
- Interpolate ids into `$path` yourself, with `rawurlencode()`.
- `$headers` override the SDK's own, the auth header included.
- Need a page object from it? `Chatly\Pagination\Page::fromDecoded($result)`
  for a `{items, nextCursor}` envelope — it raises
  `unexpected_response_shape` on anything else — or
  `Page::fromList($result)` for a route that is deliberately unpaginated.

To replace the whole transport — for recording, for a test double, for an
HTTP stack we have never heard of — implement `Chatly\TransportInterface`:

```php
public function send(
    string $method,
    string $url,
    array $headers,
    ?string $body,
    float $timeout,
): Chatly\HttpResponse;
```

Return an `HttpResponse(status, headers, body)`; throw
`Chatly\Errors\TransportException` when no response could be obtained. An HTTP
error status is **not** a transport failure — return it and let the client map
it.

## Tests

```bash
php tests/run.php
```

That is the whole command. No Composer step, no network, no API key, no
running API — the suite runs against an injected fake transport. Filter it with
`php tests/run.php webhook`.

PHPUnit runs the same cases, if you would rather:

```bash
composer install
vendor/bin/phpunit
```

`tests/Suite.php` is the single source of truth for what gets tested, so the
two runners cannot disagree about what passed. Both cover all nine items of
the cross-language SDK contract, including the two pinned cryptographic
vectors — if this SDK's digests ever stop matching the other languages', that
is a failure here and not a reason to change the expectation.

### `surface.lock.json`

Running the suite regenerates `surface.lock.json`: one entry per method,
recording the `METHOD /path` that method **actually issued** when the test
called it, with interpolated segments normalised back to `{id}` form.

```json
{
  "sdk": "php",
  "generatedBy": "tests/Cases/SurfaceCases.php",
  "entries": [{ "ns": "contacts", "name": "list", "http": "GET", "path": "/v1/contacts" }]
}
```

It is a committed artifact and the cross-language parity gate reads it. The
point is that nothing but an executed request can put an entry in it — a
method that hits the wrong path records the wrong path, a method that
hard-codes an id records that id, and a doc comment naming a route records
nothing at all. Regenerating it is part of running the tests, not a separate
script to forget; if you change a path, run the suite and commit the diff.

## Versioning and support

Semantic versioning. `0.x` means the surface may still move; pin a minor
version if that matters to you.

- Docs: <https://chatlychat.com/docs>
- Issues: <https://github.com/chatlychat/chatly/issues>

## Licence

MIT. See [LICENSE](LICENSE).

---

## About this repository

This is a **mirror**. `chatly-php` is built from a private monorepo where the rest
of the platform, its migrations and its contract checks live, so a pull request here
cannot be merged directly — but a patch is still the fastest way to get something
fixed and we will port it with attribution.

What you can do here: read the source, run the tests, open an issue, or open a PR
and we will treat the diff as the proposal.

Issues: https://github.com/chatlychat/chatly-php/issues · Docs: https://chatlychat.com/docs

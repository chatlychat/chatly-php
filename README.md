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

140 of the API's 886 routes accept an API key. The rest answer a `ck_` key
with **403** because they need a signed-in person — a reaction records *who*
reacted, and a key is not a person.

This SDK has hand-written methods for 55 of those routes (the table below).
The other 85 are reachable through [`request()`](#the-escape-hatch) with the
same auth, retries, idempotency and error handling.

Methods for routes that always 403 are deliberately **absent**. There is no
`conversations->react()` and no `channels->create()`, because shipping a
method that always fails is worse than not shipping it: the failure arrives at
runtime, in production, in somebody else's integration.

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

| Argument | Default | Notes |
| --- | --- | --- |
| `apiKey` | — | Required. An empty or whitespace-only key throws `InvalidArgumentException` immediately, not on the first call. |
| `baseUrl` | `https://api.chatlychat.com` | Another Chatly environment, or a test server; it is also the second positional argument. `null` **and `''`** both mean the default, so an unset `CHATLY_API_URL` lands on production rather than nowhere. A non-`http(s)` URL throws. |
| `timeout` | `15.0` | Seconds allowed per attempt, not per call. Three attempts can take three times this. |
| `retries` | 3 attempts | See [Retries](#retries). `RetryPolicy::none()` turns it off. |
| `transport` | `Chatly\Transport` (curl) | Any `Chatly\TransportInterface`. |

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

| Method | Route |
| --- | --- |
| `$chatly->whoami()` | `GET /v1/api-keys/whoami` |
| `$chatly->request($method, $path, $query, $body, $headers)` | anything — see [the escape hatch](#the-escape-hatch) |

`whoami()` returns `['workspaceId' => …, 'scopes' => [...]]`. Make it first in
any integration: it turns a mis-scoped key into an obvious error now instead
of a 403 from an unrelated endpoint in three weeks.

### `contacts`

| Method | Route |
| --- | --- |
| `list(array $query = []): Page` | `GET /v1/contacts` |
| `autoList(array $query = [], ?int $maxPages = null): Generator` | walks `GET /v1/contacts` |
| `get(string $id)` | `GET /v1/contacts/{id}` |
| `upsert(array $contact)` | `POST /v1/contacts` |
| `bulkUpsert(array $contacts)` | `POST /v1/contacts/bulk` |
| `merge(string $id, string $mergedId)` | `POST /v1/contacts/{id}/merge` |
| `addTags(string $id, string $tag)` | `POST /v1/contacts/{id}/tags` |
| `removeTag(string $id, string $tag)` | `DELETE /v1/contacts/{id}/tags/{tag}` |
| `unsubscribe(string $id, bool $unsubscribed = true, ?string $reason = null)` | `POST /v1/contacts/{id}/unsubscribe` |
| `journey(string $id, array $query = [])` | `GET /v1/contacts/{id}/journey` |

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
  opaque cursor the list endpoints use, so it has no `auto…()` walk.

### `conversations`

| Method | Route |
| --- | --- |
| `list(array $query = []): Page` | `GET /v1/conversations` |
| `autoList(array $query = [], ?int $maxPages = null): Generator` | walks `GET /v1/conversations` |
| `get(string $id)` | `GET /v1/conversations/{id}` |
| `messages(string $id, array $query = []): Page` | `GET /v1/conversations/{id}/messages` |
| `autoMessages(string $id, array $query = [], ?int $maxPages = null): Generator` | walks the messages |
| `send(string $id, string $body, array $options = [])` | `POST /v1/conversations/{id}/messages` |
| `unattended(array $query = [])` | `GET /v1/conversations/unattended` |
| `agentHandling(string $id)` | `GET /v1/conversations/{id}/agent-handling` |
| `setAgentHandling(string $id, string $state, ?string $reason = null)` | `PUT /v1/conversations/{id}/agent-handling` |
| `generatedReplies(string $id)` | `GET /v1/conversations/{id}/generated-replies` |
| `generateReply(string $id, string $body, ?string $basedOnMessageId = null, array $options = [])` | `POST /v1/conversations/{id}/generated-replies` |
| `scheduledMessages(string $id)` | `GET /v1/conversations/{id}/scheduled-messages` |
| `scheduleMessage(string $id, string $body, DateTimeInterface\|string $sendAt)` | `POST /v1/conversations/{id}/scheduled-messages` |
| `cancelScheduled(string $scheduledId)` | `DELETE /v1/conversations/scheduled-messages/{scheduledId}` |

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

| Method | Route |
| --- | --- |
| `list(array $query = []): Page` | `GET /v1/tickets` |
| `autoList(array $query = [], ?int $maxPages = null): Generator` | walks `GET /v1/tickets` |
| `comments(string $id)` | `GET /v1/tickets/{id}/comments` |
| `forConversation(string $conversationId)` | `GET /v1/tickets/for-conversation/{conversationId}` |
| `queues()` | `GET /v1/tickets/queues` |
| `merge(string $id, string $loserId)` | `POST /v1/tickets/{id}/merge` |
| `split(string $id, string $title, array $fields = [])` | `POST /v1/tickets/{id}/split` |
| `bulk(array $ids, string $action, array $payload = [])` | `POST /v1/tickets/bulk` |

- `list` excludes `merged` tickets unless you filter on status explicitly.
- `comments` includes internal notes. Filter on `isInternal` before showing
  them to a customer.
- `bulk` actions are `status`, `assign`, `priority` and `queue`. The response
  reports `updated` plus the ids that were **not** updated and why; ids outside
  your key's inbox scope are refused individually.
- `split` requires a title, and inherits `priority`, `type`, `assigneeUserId`
  and `tags` from the origin when they are omitted.

### `macros`

| Method | Route |
| --- | --- |
| `list(array $query = []): Page` | `GET /v1/macros` |
| `autoList(array $query = [], ?int $maxPages = null): Generator` | walks `GET /v1/macros` |
| `create(array $macro)` | `POST /v1/macros` |
| `update(string $id, array $changes)` | `PATCH /v1/macros/{id}` |
| `delete(string $id)` | `DELETE /v1/macros/{id}` |

A macro must carry a `body`, `actions`, or both. `scope` is required, and when
it is `team` so is `teamId`. `update` is a partial patch, not a replace.

### `kb`

| Method | Route |
| --- | --- |
| `articles(array $query = []): Page` | `GET /v1/kb/articles` |
| `autoArticles(array $query = [], ?int $maxPages = null): Generator` | walks the articles |
| `article(string $slug)` | `GET /v1/kb/articles/{slug}` |
| `collections()` | `GET /v1/kb/collections` |

`articles` is the authoring view and includes drafts; narrow it with
`['filter[]' => ['status:eq:published']]`. `article` takes a **slug**, not an
id. `collections` returns a plain array — the route is deliberately
unpaginated.

### `search`

| Method | Route |
| --- | --- |
| `query(string $q, array $options = [])` | `GET /v1/search` |
| `kb(string $q, array $options = [])` | `GET /v1/search` with `kinds=kb` |

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

| Method | Route |
| --- | --- |
| `track(string $contactId, string $name, array $properties = [])` | `POST /v1/cdp/events` |
| `list(array $query = []): Page` | `GET /v1/cdp/events` |
| `autoList(array $query = [], ?int $maxPages = null): Generator` | walks `GET /v1/cdp/events` |

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

| Method | Route |
| --- | --- |
| `deliveries(array $query = []): Page` | `GET /v1/webhooks/deliveries` |
| `autoDeliveries(array $query = [], ?int $maxPages = null): Generator` | walks the deliveries |
| `delivery(string $id)` | `GET /v1/webhooks/deliveries/{id}` |
| `redeliver(string $id)` | `POST /v1/webhooks/deliveries/{id}/redeliver` |

Narrow the list with `endpointId` and `status`. `delivery` includes the exact
body we POSTed, which is what you need to re-verify a signature offline.
`redeliver` **queues**: the response says `queued: true` and the outcome
appears as a new attempt in the list, sent to the endpoint's *current* url.

Verifying an **incoming** delivery is [a different thing](#webhook-verification)
and needs no API call.

### `reports`

| Method | Route |
| --- | --- |
| `overview(?int $days = null)` | `GET /v1/reports/overview` |
| `agents(?int $days = null)` | `GET /v1/reports/agents` |
| `conversationsByDay(?int $days = null)` | `GET /v1/reports/conversations-by-day` |
| `sla(array $query = [])` | `GET /v1/reports/sla` |

`days` is clamped server-side to 1–90 (default 7, except `conversationsByDay`
which defaults to 14). An unparseable value falls back to the default rather
than erroring, so read the window in the response instead of assuming yours
was used. `sla` takes a date range (`from`, `to`, `group_by`,
`business_hours`) and caps its breach list at 200 rows.

### `channels`, `billing`, `workspace`

| Method | Route |
| --- | --- |
| `$chatly->channels->list()` | `GET /v1/channels` |
| `$chatly->channels->get(string $id)` | `GET /v1/channels/{id}` |
| `$chatly->billing->plans()` | `GET /v1/billing/plans` |
| `$chatly->billing->usage()` | `GET /v1/billing/usage` |
| `$chatly->workspace->settings()` | `GET /v1/workspace/settings` |
| `$chatly->workspace->labels()` | `GET /v1/labels` |
| `$chatly->workspace->inboxes()` | `GET /v1/inboxes` |
| `$chatly->workspace->teamMembers(string $teamId, array $query = [])` | `GET /v1/teams/{id}/members` |
| `$chatly->workspace->agentSkills()` | `GET /v1/agents/skills` |

Channels are read-only from a key: configuration needs `channels:write`, which
no key-accessible route grants. Each channel row carries
`originsRestricted` — check it on a web channel, because an **empty**
allowed-origins list means *any* origin and that is invisible if you only look
at the list.

`teamMembers()` is where you get the `userId` values that `assigneeUserId`
fields expect. A key cannot enumerate teams, so the team id has to come from
the dashboard or from a human session.

`agentSkills()` returns the workspace's skill *taxonomy* —
`['skills' => [...]]`, the distinct labels anyone holds. An empty array is a
normal first-load state. Which skills one agent holds
(`GET /v1/agents/{userId}/skills`) needs a signed-in person and is not
reachable with a key.

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

| Class | When |
| --- | --- |
| `AuthenticationException` | 401 — the key is missing, malformed, revoked or expired |
| `PermissionException` | 403 — valid key, missing scope |
| `NotFoundException` | 404 — no such resource *in this workspace* |
| `ConflictException` | 409 — conflicting write, including an idempotency-key mismatch |
| `ValidationException` | 422 **and 400** — the request did not validate |
| `RateLimitException` | 429 — plus `getRetryAfter(): ?int` |
| `ServerException` | 5xx |
| `TransportException` | no response at all; `getStatus()` is `0` |
| `ChatlyException` | the base, and any other non-2xx |

Every one of them exposes:

| Accessor | Returns |
| --- | --- |
| `getStatus(): int` | HTTP status, `0` for a transport failure |
| `getCode()` / `getErrorCode(): ?string` | the API's `code` (`not_found`, `validation_failed`, …) |
| `getMessage(): string` | the API's `message`, or a readable fallback |
| `getRequestId(): ?string` | the `x-request-id` response header |
| `getBody(): string` | the raw response body, for anything the above loses |
| `getData(): ?array` | the decoded error object, unwrapped from its `{"error":{…}}` envelope |
| `getHeaders(): array` | response headers, names lowercased |

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

To retry the *same* logical operation yourself — across a job restart, say —
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

`autoList`, `autoMessages`, `autoArticles`, `autoDeliveries` — one per paged
route. It stops on a null cursor, and it stops if the server ever hands back a
cursor it has already followed, so a server-side bug becomes a truncated
result rather than an infinite loop inside your `foreach`.

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

| Parameter | Default | |
| --- | --- | --- |
| `$body` | — | the **raw bytes** received |
| `$signatureHeader` | — | the `x-livechat-signature` value |
| `$secret` | — | the endpoint's `whsec_…` signing secret |
| `$tolerance` | `300.0` | seconds the timestamp may differ from now; `INF` to skip |
| `$now` | system clock | unix seconds, for tests and archive replay |

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

85 of the 140 key-accessible routes have no typed method here. Reach them
without losing auth, retries, idempotency or typed errors:

```php
// Any read
$timeEntries = $chatly->request('GET', '/v1/tickets/' . $id . '/time');

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
- Need a page object from it? `Chatly\Pagination\Page::fromDecoded($result)`.

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
  "entries": [ { "ns": "contacts", "name": "list", "http": "GET", "path": "/v1/contacts" } ]
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

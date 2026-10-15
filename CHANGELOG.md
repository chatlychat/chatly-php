# Changelog

All notable changes to `chatly/chatly-php` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

`0.x` means the surface may still move. Pin a minor version if that matters to
you.

## [Unreleased]

### Added

- **`Webhooks::verifyChatlySignature(array $input)`** — one verifier for all
  eight signed Chatly surfaces (event webhooks, agent deliveries, AI tool
  calls, canvas steps, customer-card fetches, workflow actions and the
  unified `x-chatly-signature-v2`). It takes the surface explicitly, because
  three surfaces share `x-chatly-signature` and sign different strings, and
  returns an immutable `ChatlySignatureResult` with a stable `reason` and a
  printable `message` instead of a bare bool. Supports secret lists during a
  rotation (`secretIndex` indexes the list you passed), multiple `v1` digests,
  the v2 `prefer`/`require`/`ignore` policy and the `b=` base marker. Never
  throws, on any input. A timestamp beyond `PHP_INT_MAX` (or whose
  milliseconds would be) is `malformed_timestamp`, never a float. Proven
  against the cross-SDK corpus, one test case per vector. The table and
  constants are on `Chatly\ChatlySignatures`. `verifySignature()` is
  unchanged.
- `bots->rotateExternalSecret()` takes an optional second argument,
  `string|DateTimeInterface|null $activateAt`, for an **overlap window**: the
  old secret keeps signing deliveries until that instant. It is sent as
  `{"activateAt": "..."}` — a string verbatim, a `DateTimeInterface` as UTC
  `Y-m-d\TH:i:s.v\Z`. Without it the call is unchanged, on the wire too: a
  hard cut that sends `{}`. The response's new `activatesAt` is `null` when
  the new secret is already signing, otherwise the switch instant.
- **The surface is complete: 162 typed methods, up from 55.** Every entry in
  `packages/sdk-spec/surface.json` now has a method. 11 new namespaces —
  `ai`, `bots`, `botRoutes`, `brands`, `triggers`, `canvasSteps`,
  `customerCards`, `customerCardEndpoints`, `docsSources`, `emailTemplates`,
  `knowledgeGaps` — and 32 methods added to the eight that existed, plus
  `$chatly->me()`. `request()` still reaches the 12 key-accessible routes the
  manifest does not list.
- Three new auto-paginators, for the three new `page` routes: `bots->list()`,
  `bots->deliveries()` and `triggers->list()`. Thirteen walkers now, not ten.
- A PHP property name is `camelCase` where the manifest's `ns` is
  `snake_case` — `$chatly->botRoutes`, not `$chatly->bot_routes`, per
  CONTRACT.md §9. The lock still records the manifest's own spelling;
  `SurfaceTable::property()` holds the single mapping.

### Fixed

- **Three key-accessible routes this SDK could not reach, found while
  completing the surface and now closed.** Each carried `@AllowApiKey()` and
  was absent from `packages/sdk-spec/surface.json`; all three were added to
  the manifest on 2026-10-14 and have methods here:
  - `webhooks->delete()` (`DELETE /v1/webhooks/{id}`) — the only write on
    that controller a key could not make, while create and the schema-version
    patch both could, so a key could add a delivery target and never remove
    one. The workaround people reached for — repointing the endpoint at a dead
    url — leaves the subscription live and the delivery log filling with
    failures.
  - `$chatly->me()` (`GET /v1/me`) — discriminated on `principal`, declares no
    permission, and is therefore a better liveness check than `whoami()`: a
    key with no useful scope still gets a 200.
  - `workspace->mine()` (`GET /v1/workspaces/me`) — a bare array that stays an
    array for a key, holding the one workspace it was issued in. The cheapest
    way to confirm which tenant a key points at.
- **`channels->create()` and `channels->update()` existed and were
  deliberately withheld.** The class docblock and the suite both asserted
  that channel writes "need `channels:write`, which no key-accessible route
  grants". Both halves were false: `channels:write` is in `API_KEY_SCOPES`,
  added so a developer can stand a widget up from code, and both handlers
  carry `@AllowApiKey()`. They are implemented, along with
  `channels->rotateSecret()`. `DELETE /v1/channels/{id}` really is
  dashboard-only and is still deliberately absent — so that list is now
  per-route rather than per-scope, which is the only way to read it that is
  true.
- Route counts in `ChatlyClient`'s docblock and the README said "140 of 886"
  key-accessible routes and "55" typed. The live figures are 174 of 909, from
  `node scripts/report-api-key-surface.mjs --json`, which is the one place
  they should ever be read from. `request()`'s docblock also listed the three
  routes above as reachable only through the escape hatch, which stopped being
  true when they were added to the manifest.

### Changed

- **A paged route that answers with a non-page body is now an error.**
  `Chatly\Pagination\Page::fromDecoded()` required a cursor envelope
  (`items` as a list, inside a JSON object) and raises a `ChatlyException`
  with code `unexpected_response_shape` on anything else — a bare JSON array
  included. It used to accept a bare array and hand back a single cursorless
  page, which turned a non-page body into a walk that stopped after one page
  and reported success: a truncated result indistinguishable from a complete
  one, with a 200 on it. No method in this SDK ever read an unpaginated route
  through `Page`, so the tolerance only ever applied to bodies the caller did
  not expect.

### Added

- **`Page::fromList()`** — the deliberate way to wrap a genuinely
  unpaginated route's array (`/v1/kb/collections`, `/v1/channels`,
  `/v1/labels`, `/v1/macros`) in a single page, now that `fromDecoded()`
  refuses to guess.
- **`shape` in `surface.lock.json`** — every entry carries the top-level
  shape its decoder expects (`array`, `page`, `object`, `empty`), declared by
  hand in `tests/Cases/ResponseShapes.php` from the API handlers' own
  `@ZodResponse` schemas and **not** read from `surface.json`. The suite
  feeds each method a fixture of its own declared shape — not one body for
  all 159 — and asserts the value handed back really has it;
  `scripts/check-sdk-surface-parity.mjs` diffs eight independently written
  columns against the manifest.
- Documentation fix: there are **thirteen** paged routes and thirteen
  `auto…()` walkers — seven, then ten, now thirteen. The first two changes
  were corrections to a manifest `paged` flag that was wrong in both
  directions; the third was the surface being completed. `billing->invoices()`
  is the near miss: it paginates and is deliberately **not** a `Page`, because
  its envelope is `{ data, nextCursor }` and this SDK's pager reads `items`.
- `tests/Cases/SurfaceCases.php` and `tests/Cases/ResponseShapes.php` were
  split (into `SurfaceTable.php` and `ShapeSelfTestCases.php`) once the new
  rows pushed them to 807 and 687 lines against the 650 CONTRACT.md §9
  allows. The shape distribution is 33 array / 13 page / 115 object / 1
  empty; `macros.delete` is still the only real 204, and six routes that look
  like one are not. Data in one file, the assertions over it in the other; no assertion
  changed meaning.

## [0.1.0] — 2026-10-12

First release. The PHP implementation of the cross-language Chatly backend-SDK
contract.

### Added

- **`Chatly\ChatlyClient`** — 55 typed methods across 12 resource namespaces
  (`contacts`, `conversations`, `tickets`, `macros`, `kb`, `search`, `events`,
  `webhooks`, `reports`, `channels`, `billing`, `workspace`) plus `whoami()`.
  Readonly properties, `camelCase` methods.
- **`request()`** — the escape hatch. Any route, through the same auth,
  idempotency, retry and error handling. 85 of the 140 key-accessible routes
  have no typed method, and this is how you reach them without hand-rolling
  HTTP.
- **Typed exceptions** — `ChatlyException` with `AuthenticationException`
  (401), `PermissionException` (403), `NotFoundException` (404),
  `ConflictException` (409), `ValidationException` (422 **and** 400, which is
  what this API sends for a failed schema parse), `RateLimitException` (429,
  with `getRetryAfter()`), `ServerException` (5xx) and `TransportException`
  (no response at all). Each exposes `getStatus()`, `getCode()` /
  `getErrorCode()`, `getRequestId()`, `getBody()`, `getData()` and
  `getHeaders()`.
- **Retries** — connection errors, 429 and 5xx only, with equal jitter and
  `Retry-After` honoured. Never a 4xx that is not 429. Configurable through
  `RetryPolicy`; `RetryPolicy::none()` turns it off.
- **Idempotent writes** — a fresh UUID v4 per write, minted once and reused
  across every retry of that write, so an SDK-level retry cannot double-write.
  `withIdempotencyKey()` pins one for retrying the same logical operation.
- **Auto-pagination** — `autoList()`, `autoMessages()`, `autoArticles()`,
  `autoDeliveries()`, each a `Generator`. Stops on a null cursor, and stops if
  the server ever repeats a cursor, so a server bug is a truncated result
  rather than an infinite loop. `Chatly\Pagination\Page` for a single page.
  Seven paged routes is the whole set: `GET /v1/macros` is pagination-exempt
  and returns a plain array, so `macros->list()` returns that array and has no
  auto-walk.
- **`Chatly\Webhooks::verifySignature()`** — HMAC-SHA256 over
  `<t>.<raw body>`, constant-time compare, 300-second default tolerance.
  Returns false and never throws, including for an empty secret.
- **`Chatly\Identity::hash()`** — the widget identity-verification digest.
- **Transports** — curl by default (`Chatly\Transport`, with an escape hatch
  for `CURLOPT_*`), an optional `Chatly\Psr18Transport` for an existing PSR-18
  client, and `Chatly\TransportInterface` for anything else.
- **`surface.lock.json`** — cross-language parity evidence, regenerated by the
  test suite from the requests the 55 methods actually issue. Not a text scan
  of the source: a doc comment naming a route cannot put an entry in it, and a
  method that issues the wrong path records the wrong path. The suite also
  asserts the lock equals `packages/sdk-spec/surface.json` when that manifest
  is reachable.
- **Tests** — 88 cases covering all nine items of the shared SDK contract,
  including both pinned cryptographic vectors. Runnable two ways:
  `php tests/run.php` with no Composer step at all, or `vendor/bin/phpunit`.
  Both execute the same case list, against an injected fake transport: no
  network, no API key, no running API.

### Notes for anyone porting from another Chatly SDK

Three behaviours here differ from the Node SDK, and all three are deliberate:

- **`search` sends `kinds`, not `kinds[]`.** The `/v1/search` route validates
  a parameter named `kinds`; a bracketed `kinds[]` arrives under a key the
  schema strips, so the restriction is silently dropped and the caller gets
  every kind back. The `filter[]` surface on the LIST endpoints is the
  opposite and takes brackets.
- **A 400 raises `ValidationException`**, not the base class. The API answers
  a failed zod parse with 400 `validation_failed`, so treating only 422 as
  validation would put the common case in the wrong branch.
- **`billing->usage()` takes a required `$eventType`.** The handler validates
  it against a closed list and throws before doing any work, so the
  no-argument form that four of these SDKs shipped could never succeed.
- **`getRetryAfter()` reads the suffixed headers.** Rate limiting here is
  per-bucket (`Retry-After-short`, `Retry-After-login`) and there is no plain
  `Retry-After`; it returns the largest value found.

### Known limitations

- `getRequestId()` is normally `null`: the API neither reads nor emits
  `X-Request-Id`. The header is read because the contract specifies it and a
  reverse proxy in front of the API may add one.
- `Psr18Transport` cannot apply the client's `timeout` argument — PSR-18 has
  no per-request timeout. Configure it on the client you pass in.

[0.1.0]: https://github.com/chatlychat/chatly/releases/tag/sdk-php-v0.1.0

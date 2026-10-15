<?php

declare(strict_types=1);

namespace Chatly;

use Chatly\Errors\ChatlyException;
use Chatly\Errors\RateLimitException;
use Chatly\Errors\TransportException;
use Chatly\Pagination\Page;
use Chatly\Pagination\Paginator;
use Chatly\Resources\Ai;
use Chatly\Resources\Billing;
use Chatly\Resources\BotRoutes;
use Chatly\Resources\Bots;
use Chatly\Resources\Brands;
use Chatly\Resources\CanvasSteps;
use Chatly\Resources\Channels;
use Chatly\Resources\Contacts;
use Chatly\Resources\Conversations;
use Chatly\Resources\CustomerCardEndpoints;
use Chatly\Resources\CustomerCards;
use Chatly\Resources\DocsSources;
use Chatly\Resources\EmailTemplates;
use Chatly\Resources\Events;
use Chatly\Resources\Kb;
use Chatly\Resources\KnowledgeGaps;
use Chatly\Resources\Macros;
use Chatly\Resources\Reports;
use Chatly\Resources\Search;
use Chatly\Resources\Tickets;
use Chatly\Resources\Triggers;
use Chatly\Resources\Visitors;
use Chatly\Resources\Webhooks as WebhookDeliveries;
use Chatly\Resources\Workspace;

/**
 * The Chatly API client.
 *
 * ```php
 * $chatly = new Chatly\ChatlyClient($_ENV['CHATLY_API_KEY']);
 * $me = $chatly->whoami();
 * $chatly->conversations->send($conversationId, 'Hello from PHP');
 * ```
 *
 * ─── THIS IS A BACKEND SDK ────────────────────────────────────────────────
 *
 * It authenticates with a workspace API key (`ck_…`) from server-side code.
 * **Never ship this key, or anything built from it, to a browser**: it is a
 * bearer credential for the entire workspace — every contact, every
 * conversation, every ticket. The browser-side library is a different
 * package and uses a public channel id instead.
 *
 * ─── WHAT IS REACHABLE ────────────────────────────────────────────────────
 *
 * Most of the API needs a signed-in person and answers a `ck_` key with 403
 * (a reaction records *who* reacted, and a key is not a person). The
 * namespaces on this class are the **163** routes a key CAN drive and that we
 * hand-wrote methods for — every entry in
 * `packages/sdk-spec/surface.json`. Anything else is still reachable through
 * {@see request()} without abandoning auth, retries, idempotency and typed
 * errors.
 *
 * ─── DO NOT SUBTRACT THESE NUMBERS FROM report-api-key-surface.mjs ───────
 *
 * This docblock used to say "174 of the API's 909 routes accept an API key"
 * and then subtract: "the gap is not slack: 12 routes accept a key and have
 * no method here". That subtraction is invalid, and the manifest's own
 * `$auditedAgainst` note records the day it cost. `report-api-key-surface.mjs`
 * counts `@AllowApiKey()` **decorators**, and a class-level decorator opens
 * many handlers while counting once — so its figure is always the larger and
 * is not in the same units as a route count.
 *
 * The authoritative ROUTE count is `keyAccessible` in `docs/api-counts.json`,
 * generated from the deployed OpenAPI document. It reads 162 there and 163 in
 * a local build, because `POST /v1/visitors/{contactId}/start-conversation`
 * opened on 2026-10-14 and has not shipped yet. Against that number the
 * manifest covers the whole key-accessible surface, so there is no backlog of
 * typed methods to write — only new routes as they open.
 */
final class ChatlyClient
{
    /*
     * The request pipeline lives in `SendsRequests`.
     *
     * A trait and not a collaborator class, deliberately: `send()` and
     * `decode()` read six pieces of this client's state (`baseUrl`,
     * `authHeaders`, `idempotencyKey`, `retries`, `timeout`, `transport`)
     * and three of its private constants, so a collaborator would mean
     * either passing all nine in or making them public. A trait is the same
     * `$this` with the same visibility, which makes this extraction a pure
     * move with no behaviour to re-verify.
     *
     * It was extracted because the file reached 659 lines against this
     * repo's 650-line ceiling — a ceiling `check-file-size` only began
     * applying to `.php` on 2026-10-14, having scanned three of the
     * repository's eleven languages until then.
     */
    use SendsRequests;

    public const VERSION = '0.1.0';

    /**
     * Production. **Never a development or example host.**
     *
     * `api.livechat.local` shipped as the default in two of these SDKs and
     * `api.chatly.example` in two more; all four resolve nowhere, so every
     * call failed for anyone who followed the docs and did not override it. A
     * customer reported it, and no test caught it, because every test passes
     * an explicit URL. This package's suite asserts this constant instead.
     */
    public const DEFAULT_BASE_URL = 'https://api.chatlychat.com';

    public const DEFAULT_TIMEOUT = 15.0;

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private readonly string $baseUrl;

    /** @var array<string,string> */
    private readonly array $authHeaders;

    private readonly RetryPolicy $retries;

    private readonly TransportInterface $transport;

    public readonly Contacts $contacts;
    public readonly Conversations $conversations;
    public readonly Tickets $tickets;
    public readonly Macros $macros;
    public readonly Kb $kb;
    public readonly Search $search;
    public readonly Events $events;

    /** Webhook DELIVERY history. Signature verification is {@see Webhooks}, a static helper. */
    public readonly WebhookDeliveries $webhooks;

    public readonly Reports $reports;
    public readonly Channels $channels;
    public readonly Billing $billing;
    public readonly Workspace $workspace;

    /*
     * ─── PROPERTY NAMES ARE camelCase; THE MANIFEST'S `ns` IS snake_case ──
     *
     * `bot_routes` in `surface.json` is `$chatly->botRoutes` here, per
     * CONTRACT.md §9: "the language's own idiom wins over cross-language
     * symmetry". `tests/Cases/SurfaceCases::property()` holds the one
     * mapping, so the lock still records the manifest's own `ns` and the
     * parity gate compares like with like.
     */
    public readonly Ai $ai;
    public readonly Bots $bots;
    public readonly BotRoutes $botRoutes;
    public readonly Brands $brands;
    public readonly CanvasSteps $canvasSteps;
    public readonly CustomerCards $customerCards;
    public readonly CustomerCardEndpoints $customerCardEndpoints;
    public readonly DocsSources $docsSources;
    public readonly EmailTemplates $emailTemplates;
    public readonly KnowledgeGaps $knowledgeGaps;
    public readonly Triggers $triggers;

    /**
     * Proactive outreach. ONE method, and it is off by default per workspace —
     * see {@see Visitors::startConversation()}, whose 403 is usually the
     * workspace setting rather than the key's scopes.
     */
    public readonly Visitors $visitors;

    /**
     * @param string                  $apiKey    A workspace API key (`ck_…`). Required.
     * @param string|null             $baseUrl   Another Chatly environment, or
     *                                           a test server. Null or empty
     *                                           means {@see DEFAULT_BASE_URL}.
     * @param float                   $timeout   Seconds per attempt.
     * @param RetryPolicy|null        $retries   Null means the default: 3
     *                                           attempts, equal jitter,
     *                                           `Retry-After` honoured.
     * @param TransportInterface|null $transport Null means curl. Inject a fake
     *                                           to test without a network.
     * @param string|null             $idempotencyKey Pin a key for every write
     *                                           this instance makes. Prefer
     *                                           {@see withIdempotencyKey()}.
     *
     * @throws \InvalidArgumentException For an empty key or an unusable base URL.
     *                                  At CONSTRUCTION, not at the first call:
     *                                  a misconfiguration should surface where
     *                                  the configuration is, not three layers
     *                                  into a request.
     */
    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        ?RetryPolicy $retries = null,
        ?TransportInterface $transport = null,
        private readonly ?string $idempotencyKey = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'ChatlyClient: the API key is empty. Create one in Settings → API keys; it starts with "ck_".',
            );
        }
        /*
         * An empty string falls back to PRODUCTION, not to nowhere.
         *
         * `CHATLY_API_URL=` in a compose file, or an unset env var read with
         * `getenv() ?: ''`, arrives here as `''`. Treating that as a literal
         * base URL would build `/v1/contacts` as a relative path and fail in a
         * way that looks like a network problem. Treating it as "not
         * configured" lands on production, which is the host that is correct
         * for everybody who has not deliberately pointed at another one.
         */
        $resolved = $baseUrl === null || trim($baseUrl) === '' ? self::DEFAULT_BASE_URL : trim($baseUrl);
        $scheme = parse_url($resolved, \PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException(sprintf(
                'ChatlyClient: baseUrl must be an http(s) URL, got "%s".',
                $resolved,
            ));
        }
        $this->baseUrl = rtrim($resolved, '/');

        if ($idempotencyKey !== null) {
            self::assertIdempotencyKey($idempotencyKey);
        }

        $this->authHeaders = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'chatly-sdk-php/' . self::VERSION,
        ];
        $this->retries = $retries ?? new RetryPolicy();
        $this->transport = $transport ?? new Transport();

        /*
         * Every namespace is built here, in one place, pointing at $this.
         *
         * `withIdempotencyKey()` therefore constructs a whole new client
         * rather than cloning and re-binding the namespaces it remembers to
         * re-bind — which is how the Node SDK ended up with a derived client
         * whose `search` namespace still called the ORIGINAL client's
         * transport. There is no list to forget here.
         */
        $this->contacts = new Contacts($this);
        $this->conversations = new Conversations($this);
        $this->tickets = new Tickets($this);
        $this->macros = new Macros($this);
        $this->kb = new Kb($this);
        $this->search = new Search($this);
        $this->events = new Events($this);
        $this->webhooks = new WebhookDeliveries($this);
        $this->reports = new Reports($this);
        $this->channels = new Channels($this);
        $this->billing = new Billing($this);
        $this->workspace = new Workspace($this);
        $this->ai = new Ai($this);
        $this->bots = new Bots($this);
        $this->botRoutes = new BotRoutes($this);
        $this->brands = new Brands($this);
        $this->canvasSteps = new CanvasSteps($this);
        $this->customerCards = new CustomerCards($this);
        $this->customerCardEndpoints = new CustomerCardEndpoints($this);
        $this->docsSources = new DocsSources($this);
        $this->emailTemplates = new EmailTemplates($this);
        $this->knowledgeGaps = new KnowledgeGaps($this);
        $this->triggers = new Triggers($this);
        $this->visitors = new Visitors($this);
    }

    /**
     * A client whose every write carries this exact `Idempotency-Key`.
     *
     * The original is untouched — this returns a new client.
     *
     * Use it to retry the *same* logical operation across process boundaries
     * or across your own retry loop. Same key + same body replays the first
     * response; same key + **different** body is a 409
     * ({@see \Chatly\Errors\ConflictException}).
     *
     * ```php
     * $key = $order->id . ':welcome';          // stable, derived from your data
     * $chatly->withIdempotencyKey($key)->contacts->upsert([...]);
     * ```
     *
     * You do not need this for the SDK's own retries: an automatic retry
     * always reuses the key minted for the attempt that failed.
     *
     * @throws \InvalidArgumentException If the key is not 16–128 characters
     *                                  from the unreserved + sub-delims URI
     *                                  set, which is what the API accepts.
     */
    public function withIdempotencyKey(string $key): self
    {
        self::assertIdempotencyKey($key);

        return new self(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            retries: $this->retries,
            transport: $this->transport,
            idempotencyKey: $key,
        );
    }

    /** A client with a different retry policy. The original is untouched. */
    public function withRetryPolicy(RetryPolicy $retries): self
    {
        return new self(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            retries: $retries,
            transport: $this->transport,
            idempotencyKey: $this->idempotencyKey,
        );
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Which workspace is this key in, and what may it do?
     *
     * `GET /v1/api-keys/whoami` is the only route on the api-keys controller
     * that accepts a key — managing keys with a key is a privilege-escalation
     * shape. It is the call to make first in any integration: it turns a
     * mis-scoped key into an obvious error now instead of a 403 from an
     * unrelated endpoint in three weeks.
     *
     * @return array{workspaceId:string,scopes:array<int,string>}|mixed
     */
    public function whoami(): mixed
    {
        return $this->request('GET', '/v1/api-keys/whoami');
    }

    /**
     * `GET /v1/me` — who is calling, whoever that is.
     *
     * ─── HOW THIS DIFFERS FROM {@see whoami()} ────────────────────────────
     *
     * Same answer, looser door, wider audience.
     *
     * The body is **discriminated on `principal`**. For a `ck_` key it is
     * `principal: 'api_key'` carrying the same object `whoami()` returns —
     * there is no user behind a key — and for a signed-in person it is
     * `principal: 'user'` with their profile and active membership. So this
     * is the route to call from code that might be handed either credential;
     * `whoami()` is the one to call when you know it is a key.
     *
     * **It declares no permission at all**, which is the one way it is looser
     * than `whoami()` (`workspace:read`). Deliberate: what a key learns here
     * is its own workspace id and its own grant list — the two things it
     * presented in order to ask — so requiring a scope could only withhold a
     * credential's description from the credential itself, which is how an
     * integrator ends up discovering their scopes by provoking 403s.
     *
     * That makes it **the liveness check to reach for**: a key with no useful
     * scope still gets a 200, and that answer is true. `whoami()` would
     * answer the same key with a 403 and tell you nothing about whether the
     * key, the host or the network was the problem.
     *
     * It is also `@AllowWhenSuspended`, so it answers for a workspace
     * suspended for non-payment. And it touches the database not at all on
     * the key path.
     */
    public function me(): mixed
    {
        return $this->request('GET', '/v1/me');
    }

    /**
     * The escape hatch: call any route directly.
     *
     * 12 of the 174 key-accessible routes have no typed method here. Without
     * this, reaching one means abandoning the SDK and hand-rolling HTTP with
     * a bearer token — and losing retries, idempotency and typed errors with
     * it. This goes through all of them.
     *
     * This paragraph named three routes as reachable ONLY here —
     * `DELETE /v1/webhooks/{id}`, `GET /v1/me` and `GET /v1/workspaces/me`.
     * Each was key-accessible and missing from the shared manifest; all three
     * were added to it on 2026-10-14 and are now
     * {@see \Chatly\Resources\Webhooks::delete()}, {@see me()} and
     * {@see \Chatly\Resources\Workspace::mine()}. Fifteen became twelve, and
     * the remaining twelve are manifest gaps rather than deliberate
     * omissions — a method here would fail the cross-SDK parity gate, so the
     * manifest is where they get closed.
     *
     * ```php
     * $chatly->request('GET', '/v1/tickets', ['filter[]' => ['status:open'], 'limit' => 50]);
     * $chatly->request('POST', '/v1/tickets/' . $id . '/comments', body: ['body' => 'done']);
     * ```
     *
     * @param string              $method  HTTP verb. POST/PUT/PATCH/DELETE get an
     *                                     `Idempotency-Key` automatically.
     * @param string              $path    Path from the host, e.g. `/v1/contacts`.
     *                                     Interpolate ids yourself with
     *                                     `rawurlencode()`.
     * @param array<string,mixed> $query   Query parameters. A list value
     *                                     becomes REPEATED parameters:
     *                                     `['filter[]' => ['a','b']]` is
     *                                     `?filter[]=a&filter[]=b`, never a
     *                                     comma-joined value.
     * @param mixed               $body    JSON-encoded as given. Null sends `{}`
     *                                     on POST/PUT/PATCH and no body at all
     *                                     on GET/DELETE.
     * @param array<string,string> $headers Extra headers; they override the
     *                                     SDK's own, including the auth header.
     *
     * @return mixed The decoded JSON, or null for a 204.
     *
     * @throws ChatlyException   Any non-2xx that survived the retry policy.
     * @throws TransportException The request never reached the API.
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        mixed $body = null,
        array $headers = [],
    ): mixed {
        return $this->decode($this->send($method, $path, $query, $body, $headers));
    }

    /**
     * One page of a list endpoint.
     *
     * Throws a {@see ChatlyException} with code `unexpected_response_shape`
     * if the route answers with anything but a cursor envelope — a bare array
     * included. That is not pedantry: a non-page body silently read as a
     * one-page result makes a truncated walk indistinguishable from a
     * complete one.
     *
     * @internal Resources call this. Use the resource methods, or
     *           {@see request()} plus {@see Page::fromDecoded()}.
     *
     * @param array<string,mixed> $query
     */
    public function requestPage(string $path, array $query = []): Page
    {
        // The path goes in so the error names the route that misbehaved
        // rather than just the shape.
        return Page::fromDecoded($this->request('GET', $path, $query), $path);
    }

    /**
     * Every row of a list endpoint, across pages.
     *
     * @internal Resources call this; each exposes it as `auto…()`.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function paginate(string $path, array $query = [], ?int $maxPages = null): \Generator
    {
        $paginator = new Paginator(
            fn (array $pageQuery): Page => $this->requestPage($path, $pageQuery),
            $query,
            $maxPages,
        );

        yield from $paginator->items();
    }

}

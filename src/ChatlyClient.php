<?php

declare(strict_types=1);

namespace Chatly;

use Chatly\Errors\ChatlyException;
use Chatly\Errors\RateLimitException;
use Chatly\Errors\TransportException;
use Chatly\Pagination\Page;
use Chatly\Pagination\Paginator;
use Chatly\Resources\Billing;
use Chatly\Resources\Channels;
use Chatly\Resources\Contacts;
use Chatly\Resources\Conversations;
use Chatly\Resources\Events;
use Chatly\Resources\Kb;
use Chatly\Resources\Macros;
use Chatly\Resources\Reports;
use Chatly\Resources\Search;
use Chatly\Resources\Tickets;
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
 * 140 of the API's 886 routes accept an API key; the rest answer a `ck_` key
 * with 403 because they need a signed-in person (a reaction records *who*
 * reacted, and a key is not a person). The namespaces on this class are the
 * 55 we hand-wrote methods for. Every other key-accessible route is reachable
 * through {@see request()} without abandoning auth, retries, idempotency and
 * typed errors.
 */
final class ChatlyClient
{
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
     * The escape hatch: call any route directly.
     *
     * 85 of the 140 key-accessible routes have no typed method here. Without
     * this, reaching one means abandoning the SDK and hand-rolling HTTP with
     * a bearer token — and losing retries, idempotency and typed errors with
     * it. This goes through all of them.
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
     * @internal Resources call this. Use the resource methods, or
     *           {@see request()} plus {@see Page::fromDecoded()}.
     *
     * @param array<string,mixed> $query
     */
    public function requestPage(string $path, array $query = []): Page
    {
        return Page::fromDecoded($this->request('GET', $path, $query));
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

    /**
     * Perform the request, retrying what is safe to retry.
     *
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers
     */
    private function send(
        string $method,
        string $path,
        array $query,
        mixed $body,
        array $headers,
    ): HttpResponse {
        $method = strtoupper($method);
        $isWrite = \in_array($method, self::WRITE_METHODS, true);
        $url = $this->baseUrl . self::normalisePath($path) . self::buildQuery($query);

        $payload = null;
        $requestHeaders = $this->authHeaders;

        if ($body !== null) {
            // An empty array is an empty OBJECT here. `json_encode([])` is
            // `[]`, which every body schema on this API rejects as "expected
            // object, received array".
            $payload = $body === []
                ? '{}'
                : json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $requestHeaders['Content-Type'] = 'application/json';
        } elseif ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            // A bodyless POST still needs a JSON body: several write routes
            // (`/redeliver`) take no fields, and a request with no body and no
            // content-type is at the mercy of whatever body parser is in
            // front of the API.
            $payload = '{}';
            $requestHeaders['Content-Type'] = 'application/json';
        }

        if ($isWrite) {
            /*
             * Minted ONCE, outside the retry loop.
             *
             * Every retry below re-sends this same key, which is the whole
             * reason the header exists: a write that timed out after the
             * server committed is deduplicated rather than applied twice.
             * Move this inside the loop and the SDK's own retries become a
             * double-write machine.
             */
            $requestHeaders['Idempotency-Key'] = $this->idempotencyKey ?? self::uuid4();
        }

        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }

        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                $response = $this->transport->send($method, $url, $requestHeaders, $payload, $this->timeout);
            } catch (TransportException $e) {
                if ($this->retries->shouldRetryTransport($attempt) && $this->retries->wait($attempt)) {
                    continue;
                }
                throw $e;
            }

            if ($response->isSuccess()) {
                return $response;
            }

            $error = ChatlyException::fromResponse($response);
            if (!$this->retries->shouldRetryStatus($response->status, $attempt)) {
                throw $error;
            }
            $retryAfter = $error instanceof RateLimitException ? $error->getRetryAfter() : null;
            if (!$this->retries->wait($attempt, $retryAfter === null ? null : (float) $retryAfter)) {
                // The server asked for longer than the policy allows. Raising
                // the error beats blocking a web request for ten minutes, and
                // the caller can read `getRetryAfter()` and decide.
                throw $error;
            }
        }
    }

    /** @throws ChatlyException When a 2xx body is not the JSON it claims to be. */
    private function decode(HttpResponse $response): mixed
    {
        if ($response->status === 204 || $response->body === '') {
            return null;
        }
        try {
            return json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ChatlyException(
                'The API returned a ' . $response->status . ' whose body is not valid JSON: ' . $e->getMessage(),
                $response->status,
                'invalid_json',
                $response->header('x-request-id'),
                $response->body,
                $response->headers,
                $e,
            );
        }
    }

    private static function normalisePath(string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('ChatlyClient: path is empty');
        }

        return $path[0] === '/' ? $path : '/' . $path;
    }

    /**
     * Build a query string in which repeated keys stay repeated.
     *
     * `http_build_query(['filter[]' => ['a','b']])` produces
     * `filter%5B%5D%5B0%5D=a&filter%5B%5D%5B1%5D=b` — indexed keys the API
     * does not read. This emits `filter%5B%5D=a&filter%5B%5D=b`, which is two
     * parameters with the same name, which is what `?filter[]=` means.
     *
     * @param array<string,mixed> $query
     */
    private static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                // Skip rather than send `key=`. An empty value is a real,
                // different thing to several of these filters.
                continue;
            }
            foreach (\is_array($value) ? $value : [$value] as $single) {
                if ($single === null) {
                    continue;
                }
                if (\is_array($single)) {
                    throw new \InvalidArgumentException(sprintf(
                        'ChatlyClient: query parameter "%s" is nested. Pass a scalar or a flat list; '
                        . 'the API expresses structure in the VALUE, e.g. ["filter[]" => ["status:open"]].',
                        (string) $key,
                    ));
                }
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::queryValue($single));
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    private static function queryValue(mixed $value): string
    {
        if (\is_bool($value)) {
            // `true`/`false`, not `1`/``. An empty string for false is how a
            // boolean filter silently becomes "unset".
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'ChatlyClient: query values must be scalar, Stringable or DateTimeInterface, got %s.',
            get_debug_type($value),
        ));
    }

    /**
     * The API accepts "a UUID, or any opaque token of 16–128 characters from
     * the unreserved + sub-delims URI set". Checked here so a bad pinned key
     * is an exception where you set it, rather than a 400 on an unrelated
     * write later.
     */
    private static function assertIdempotencyKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9\-._~!$&\'()*+,;=:@]{16,128}$/', $key) !== 1) {
            throw new \InvalidArgumentException(
                'ChatlyClient: an Idempotency-Key must be 16–128 characters from the unreserved + '
                . 'sub-delims URI set. A UUID always qualifies; so does "order-4821:welcome-email".',
            );
        }
    }

    /**
     * A v4 UUID from `random_bytes`.
     *
     * CSPRNG rather than `mt_rand`: two processes seeded alike would mint the
     * same key, and an idempotency key that collides across unrelated writes
     * makes the API replay one caller's response to another.
     */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

<?php

declare(strict_types=1);

namespace Chatly;

use Chatly\Errors\ChatlyException;
use Chatly\Errors\RateLimitException;
use Chatly\Errors\TransportException;

/**
 * The request pipeline: retries, idempotency and response decoding.
 *
 * Extracted verbatim from `ChatlyClient` when that file reached 659 lines
 * against the repository's 650-line ceiling. A trait rather than a
 * collaborator class because every method here reads the client's own state
 * and private constants — see the note at the `use SendsRequests;` site for
 * the full argument. Nothing here changed in the move.
 *
 * @internal Not part of the published surface. It is `public` only because
 *           PHP has no package-private; a caller should use `ChatlyClient`.
 */
trait SendsRequests
{
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

<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlyClient;
use Chatly\Errors\AuthenticationException;
use Chatly\Errors\ChatlyException;
use Chatly\Errors\ConflictException;
use Chatly\Errors\NotFoundException;
use Chatly\Errors\PermissionException;
use Chatly\Errors\RateLimitException;
use Chatly\Errors\ServerException;
use Chatly\Errors\TransportException;
use Chatly\Errors\ValidationException;
use Chatly\RetryPolicy;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;

/** CONTRACT §3 (errors), §4 (retries), §10.4 and §10.5. */
final class ErrorCases
{
    /**
     * The error envelope the API actually sends.
     *
     * `{"error":{"code","message","details"}}` — nested, which is what
     * `wireError()` in the platform's own source produces. A client that read
     * `code` at the TOP level has shipped here before and the session-recovery
     * path it guarded never fired in production, because `body.code` is
     * `undefined` for every real response. Built in one place so no case can
     * invent a shape the server does not send.
     *
     * @return array<string,mixed>
     */
    private static function envelope(string $code, string $message, ?array $details = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return ['error' => $error];
    }

    private static function client(FakeTransport $transport, int $maxAttempts = 3): ChatlyClient
    {
        return new ChatlyClient('ck_test', transport: $transport, retries: new RetryPolicy(
            maxAttempts: $maxAttempts,
            sleeper: static function (float $seconds): void {},
            jitter: static fn (float $budget): float => 0.0,
        ));
    }

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            '§10.4 a 404 raises NotFoundException carrying code and request id' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(
                    404,
                    self::envelope('not_found', 'No such contact'),
                    ['x-request-id' => 'req_abc'],
                );
                $chatly = self::client($transport);
                $error = Assert::throws(
                    NotFoundException::class,
                    static fn () => $chatly->contacts->get('missing'),
                    'a 404 must be the not-found type',
                );

                Assert::same(404, $error->getStatus(), 'status');
                Assert::same('not_found', $error->getErrorCode(), 'the code comes from the nested error object');
                Assert::same('not_found', $error->getCode(), 'getCode() exposes the API code too');
                Assert::same('No such contact', $error->getMessage(), 'message');
                Assert::same('req_abc', $error->getRequestId(), 'the x-request-id header must be captured');
                Assert::contains('not_found', $error->getBody(), 'the raw body is kept');
            },

            '§10.4 a 422 raises ValidationException' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(
                    422,
                    self::envelope('validation_failed', 'Invalid request body', ['issues' => [['path' => ['email']]]]),
                    ['x-request-id' => 'req_422'],
                );
                $chatly = self::client($transport);
                $error = Assert::throws(
                    ValidationException::class,
                    static fn () => $chatly->contacts->upsert(['email' => 'nope']),
                    'a 422 must be the validation type',
                );

                Assert::same(422, $error->getStatus(), 'status');
                Assert::same('req_422', $error->getRequestId(), 'request id');
                Assert::same(
                    ['issues' => [['path' => ['email']]]],
                    $error->getDetails(),
                    'the per-field issues must be reachable without parsing the body by hand',
                );
            },

            '§10.4 a 400 is a ValidationException too, because that is what this API sends' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(400, self::envelope('validation_failed', 'Invalid request body'));
                $chatly = self::client($transport);
                $error = Assert::throws(
                    ValidationException::class,
                    static fn () => $chatly->contacts->upsert([]),
                    'a failed zod parse on this API is a 400, and it is the same branch for a caller',
                );
                Assert::same(400, $error->getStatus(), 'the real status is preserved');
            },

            '§10.4 a 429 raises RateLimitException with retry_after populated' => static function (): void {
                $transport = new FakeTransport();
                /*
                 * `Retry-After-short`, not `Retry-After`. Rate limiting on
                 * this API is per-bucket and every header is suffixed with the
                 * bucket name; the API's own reference says there is no plain
                 * form. A client reading only `Retry-After` finds nothing on
                 * every real 429.
                 */
                $transport->push(
                    429,
                    self::envelope('rate_limited', 'Too many requests'),
                    ['retry-after-short' => '7', 'x-request-id' => 'req_429'],
                );
                $chatly = self::client($transport, maxAttempts: 1);
                $error = Assert::throws(
                    RateLimitException::class,
                    static fn () => $chatly->contacts->list(),
                    'a 429 must be the rate-limit type',
                );

                Assert::same(429, $error->getStatus(), 'status');
                Assert::same(7, $error->getRetryAfter(), 'the suffixed Retry-After header must be read');
                Assert::same('req_429', $error->getRequestId(), 'request id');
            },

            '§3 a plain Retry-After is read as well, and the longest wait wins' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(429, self::envelope('rate_limited', 'slow down'), ['retry-after' => '3']);
                $chatly = self::client($transport, maxAttempts: 1);
                $plain = Assert::throws(RateLimitException::class, static fn () => $chatly->contacts->list(), '429');
                Assert::same(3, $plain->getRetryAfter(), 'a proxy-supplied plain Retry-After must still work');

                $second = new FakeTransport();
                $second->push(429, self::envelope('rate_limited', 'slow down'), [
                    'retry-after-short' => '2',
                    'retry-after-login' => '30',
                ]);
                $chatly2 = self::client($second, maxAttempts: 1);
                $both = Assert::throws(RateLimitException::class, static fn () => $chatly2->contacts->list(), '429');
                Assert::same(
                    30,
                    $both->getRetryAfter(),
                    'waiting for the shortest bucket would retry into a wall that is still up',
                );
            },

            '§3 a 429 with no Retry-After header reports null, not zero' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(429, self::envelope('rate_limited', 'slow down'));
                $chatly = self::client($transport, maxAttempts: 1);
                $error = Assert::throws(RateLimitException::class, static fn () => $chatly->contacts->list(), '429');

                Assert::same(
                    null,
                    $error->getRetryAfter(),
                    'null means "the server said nothing"; 0 would mean "retry immediately"',
                );
            },

            '§3 each status maps to its own type' => static function (): void {
                $expectations = [
                    [401, AuthenticationException::class, 'unauthenticated'],
                    [403, PermissionException::class, 'forbidden'],
                    [409, ConflictException::class, 'conflict'],
                    [500, ServerException::class, 'unknown'],
                    [503, ServerException::class, 'service_unavailable'],
                    [418, ChatlyException::class, 'teapot'],
                ];
                foreach ($expectations as [$status, $class, $code]) {
                    $transport = new FakeTransport();
                    $transport->push($status, self::envelope($code, 'nope'), ['x-request-id' => 'req_' . $status]);
                    $chatly = self::client($transport, maxAttempts: 1);
                    $error = Assert::throws(
                        $class,
                        static fn () => $chatly->contacts->list(),
                        "HTTP {$status} must raise {$class}",
                    );
                    Assert::same($status, $error->getStatus(), "status {$status} preserved");
                    Assert::same($code, $error->getErrorCode(), "code for {$status}");
                    Assert::same('req_' . $status, $error->getRequestId(), "request id for {$status}");
                }
            },

            '§3 401 and 403 are distinguishable without parsing a message' => static function (): void {
                $wrongKey = new FakeTransport();
                $wrongKey->push(401, self::envelope('unauthenticated', 'bad key'));
                $chatly1 = self::client($wrongKey, maxAttempts: 1);
                $a = Assert::throws(ChatlyException::class, static fn () => $chatly1->contacts->list(), '401');

                $missingScope = new FakeTransport();
                $missingScope->push(403, self::envelope('forbidden', 'missing scope'));
                $chatly2 = self::client($missingScope, maxAttempts: 1);
                $b = Assert::throws(ChatlyException::class, static fn () => $chatly2->contacts->list(), '403');

                Assert::true($a instanceof AuthenticationException, '"my key is wrong" is its own type');
                Assert::false($a instanceof PermissionException, 'and is not the permission type');
                Assert::true($b instanceof PermissionException, '"my key lacks a scope" is its own type');
                Assert::false($b instanceof AuthenticationException, 'and is not the authentication type');
            },

            '§3 a flat error body still yields the code' => static function (): void {
                $transport = new FakeTransport();
                // Not the shape the API sends, but what a proxy or gateway in
                // front of it might. Losing the status over an unexpected
                // envelope would be worse than tolerating both.
                $transport->push(404, ['code' => 'not_found', 'message' => 'gone']);
                $chatly = self::client($transport);
                $error = Assert::throws(NotFoundException::class, static fn () => $chatly->contacts->get('x'), '404');

                Assert::same('not_found', $error->getErrorCode(), 'a top-level code is read as a fallback');
                Assert::same('gone', $error->getMessage(), 'and so is the message');
            },

            '§3 a non-JSON error body still produces the right type' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(404, '<html>404 Not Found</html>');
                $chatly = self::client($transport);
                $error = Assert::throws(NotFoundException::class, static fn () => $chatly->contacts->get('x'), '404');

                Assert::same(null, $error->getErrorCode(), 'there is no code to report');
                Assert::contains('Not found', $error->getMessage(), 'a readable fallback message is supplied');
                Assert::contains('<html>', $error->getBody(), 'the raw body is still there');
                Assert::same(null, $error->getData(), 'getData() is null, not an invented array');
            },

            '§3 a transport failure is its own type, distinct from any status' => static function (): void {
                $transport = new FakeTransport();
                $transport->pushTransportFailure('Could not resolve host: api.chatlychat.com');
                $chatly = self::client($transport, maxAttempts: 1);
                $error = Assert::throws(
                    TransportException::class,
                    static fn () => $chatly->contacts->list(),
                    'a DNS failure must not look like an HTTP error',
                );

                Assert::same(0, $error->getStatus(), 'there is no HTTP status to report');
                Assert::contains('resolve host', $error->getMessage(), 'the cause must survive');
                Assert::true($error instanceof ChatlyException, 'but it is still catchable as one SDK error');
            },

            '§10.5 a 429 is retried and then succeeds' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(429, self::envelope('rate_limited', 'slow down'), ['retry-after-short' => '0']);
                $transport->push(200, ['items' => [['id' => 'c1']], 'nextCursor' => null]);
                $page = self::client($transport)->contacts->list();

                Assert::same(2, $transport->count(), 'exactly two attempts');
                Assert::same(1, \count($page->items), 'the second attempt\'s result is returned');
            },

            '§10.5 a 400 is NOT retried' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(400, self::envelope('validation_failed', 'Invalid request body'));
                $chatly = self::client($transport);
                Assert::throws(
                    ValidationException::class,
                    static fn () => $chatly->contacts->upsert([]),
                    'a 400 must raise',
                );
                Assert::same(
                    1,
                    $transport->count(),
                    'repeating a request the server has already rejected wastes the rate-limit budget',
                );
            },

            '§4 no 4xx other than 429 is retried' => static function (): void {
                foreach ([401, 403, 404, 409, 422] as $status) {
                    $transport = new FakeTransport();
                    $transport->push($status, self::envelope('nope', 'nope'));
                    $chatly = self::client($transport);
                    Assert::throws(
                        ChatlyException::class,
                        static fn () => $chatly->contacts->get('x'),
                        "HTTP {$status} must raise",
                    );
                    Assert::same(1, $transport->count(), "HTTP {$status} must not be retried");
                }
            },

            '§4 a 5xx is retried up to the budget and then raises' => static function (): void {
                $transport = new FakeTransport();
                $transport->pushRepeated(3, 500, self::envelope('unknown', 'Internal server error'));
                $chatly = self::client($transport, maxAttempts: 3);
                $error = Assert::throws(
                    ServerException::class,
                    static fn () => $chatly->contacts->list(),
                    'a 5xx that survives the budget must reach the caller',
                );

                Assert::same(3, $transport->count(), 'three attempts: one try plus two retries');
                Assert::same(500, $error->getStatus(), 'the last status is reported');
            },

            '§4 a connection error is retried and can then succeed' => static function (): void {
                $transport = new FakeTransport();
                $transport->pushTransportFailure('connection reset by peer');
                $transport->push(200, ['workspaceId' => 'w1', 'scopes' => []]);
                $result = self::client($transport)->whoami();

                Assert::same(2, $transport->count(), 'the connection error was retried');
                Assert::same('w1', $result['workspaceId'] ?? null, 'and the retry\'s result is returned');
            },

            '§4 RetryPolicy::none() disables retrying entirely' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(503, self::envelope('service_unavailable', 'try later'));
                $chatly = new ChatlyClient('ck_test', transport: $transport, retries: RetryPolicy::none());
                Assert::throws(ServerException::class, static fn () => $chatly->contacts->list(), '503 raises');
                Assert::same(1, $transport->count(), 'a caller that owns its own retry loop gets exactly one attempt');
            },

            '§4 a Retry-After longer than the policy allows raises instead of blocking' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(429, self::envelope('rate_limited', 'slow down'), ['retry-after-short' => '900']);
                $slept = [];
                $chatly = new ChatlyClient('ck_test', transport: $transport, retries: new RetryPolicy(
                    maxAttempts: 3,
                    maxRetryAfter: 60.0,
                    sleeper: static function (float $seconds) use (&$slept): void {
                        $slept[] = $seconds;
                    },
                ));
                $error = Assert::throws(
                    RateLimitException::class,
                    static fn () => $chatly->contacts->list(),
                    'a fifteen-minute wait must not park a web request',
                );

                Assert::same(1, $transport->count(), 'no retry was attempted');
                Assert::same(0, \count($slept), 'and nothing slept');
                Assert::same(900, $error->getRetryAfter(), 'the caller can read the wait and decide for itself');
            },

            '§4 the backoff honours Retry-After, then falls back to jittered exponential' => static function (): void {
                $slept = [];
                $policy = new RetryPolicy(
                    maxAttempts: 4,
                    baseDelay: 0.5,
                    maxDelay: 8.0,
                    sleeper: static function (float $seconds) use (&$slept): void {
                        $slept[] = $seconds;
                    },
                    jitter: static fn (float $budget): float => $budget, // deterministic: take the whole budget
                );
                $transport = new FakeTransport();
                $transport->push(429, self::envelope('rate_limited', 'x'), ['retry-after-short' => '2']);
                $transport->push(500, self::envelope('unknown', 'x'));
                $transport->push(500, self::envelope('unknown', 'x'));
                $transport->push(200, ['ok' => true]);
                (new ChatlyClient('ck_test', transport: $transport, retries: $policy))->whoami();

                Assert::same(4, $transport->count(), 'four attempts');
                Assert::same(3, \count($slept), 'three waits');
                Assert::same(2.0, $slept[0], 'the server-supplied wait is used verbatim');
                Assert::same(1.0, $slept[1], 'then 0.5 * 2^1 = 1.0 with the jitter budget taken in full');
                Assert::same(2.0, $slept[2], 'then 0.5 * 2^2 = 2.0');
            },
        ];
    }
}

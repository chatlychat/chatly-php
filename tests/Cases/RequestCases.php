<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlyClient;
use Chatly\RetryPolicy;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;

/** CONTRACT §2 (request shape), §8 (escape hatch) and §10.3. */
final class RequestCases
{
    private static function client(FakeTransport $transport, ?RetryPolicy $retries = null): ChatlyClient
    {
        return new ChatlyClient('ck_test', transport: $transport, retries: $retries ?? self::instantRetries());
    }

    /** The default policy with the sleeping taken out, so the suite is fast. */
    private static function instantRetries(int $maxAttempts = 3): RetryPolicy
    {
        return new RetryPolicy(
            maxAttempts: $maxAttempts,
            sleeper: static function (float $seconds): void {},
            jitter: static fn (float $budget): float => 0.0,
        );
    }

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            '§10.3 a write sends an Idempotency-Key' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 'c1']);
                self::client($transport)->contacts->upsert(['email' => 'a@b.test']);

                $key = $transport->header('idempotency-key');
                Assert::true($key !== null, 'a POST must carry an Idempotency-Key');
                Assert::true(
                    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $key) === 1,
                    'the minted key should be a v4 UUID, got: ' . (string) $key,
                );
            },

            '§10.3 two writes send DIFFERENT idempotency keys' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 'c1'])->push(201, ['id' => 'c2']);
                $chatly = self::client($transport);
                $chatly->contacts->upsert(['email' => 'a@b.test']);
                $chatly->contacts->upsert(['email' => 'c@d.test']);

                Assert::notSame(
                    $transport->header('idempotency-key', 0),
                    $transport->header('idempotency-key', 1),
                    'two unrelated writes must not be deduplicated into one',
                );
            },

            '§10.3 a pinned key sends the SAME one twice' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 'c1'])->push(201, ['id' => 'c1']);
                $pinned = self::client($transport)->withIdempotencyKey('order-4821:welcome-email');
                $pinned->contacts->upsert(['email' => 'a@b.test']);
                $pinned->contacts->upsert(['email' => 'a@b.test']);

                Assert::same(
                    'order-4821:welcome-email',
                    $transport->header('idempotency-key', 0),
                    'a pinned key must be sent verbatim',
                );
                Assert::same(
                    $transport->header('idempotency-key', 0),
                    $transport->header('idempotency-key', 1),
                    'a pinned key must be identical across calls',
                );
            },

            '§2 pinning returns a derived client; the original still mints fresh keys' => static function (): void {
                $transport = new FakeTransport();
                $transport->pushRepeated(2, 201, ['id' => 'c1']);
                $chatly = self::client($transport);
                $chatly->withIdempotencyKey('pinned-key-0000001')->contacts->upsert(['email' => 'a@b.test']);
                $chatly->contacts->upsert(['email' => 'a@b.test']);

                Assert::same('pinned-key-0000001', $transport->header('idempotency-key', 0), 'the derived client is pinned');
                Assert::notSame(
                    'pinned-key-0000001',
                    $transport->header('idempotency-key', 1),
                    'withIdempotencyKey must not mutate the client it was called on',
                );
            },

            '§2 every namespace on a derived client uses the derived client' => static function (): void {
                /*
                 * The Node SDK cloned its client by re-binding each namespace
                 * by hand and forgot `search`, so a derived client's search
                 * methods called the ORIGINAL client's transport. This client
                 * builds every namespace in its constructor, so there is no
                 * list to forget — this proves it for all thirteen.
                 */
                $transport = new FakeTransport();
                $chatly = self::client($transport)->withIdempotencyKey('derived-key-000001');
                foreach (
                    [
                        'contacts', 'conversations', 'tickets', 'macros', 'kb',
                        'search', 'events', 'webhooks', 'reports', 'channels',
                        'billing', 'workspace',
                    ] as $namespace
                ) {
                    $resource = $chatly->{$namespace};
                    $reflected = new \ReflectionProperty($resource, 'client');
                    Assert::same(
                        $chatly,
                        $reflected->getValue($resource),
                        "the {$namespace} namespace must point at the derived client, not the original",
                    );
                }
            },

            '§2 a pinned key must be a shape the API accepts' => static function (): void {
                $chatly = self::client(new FakeTransport());
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => $chatly->withIdempotencyKey('too-short'),
                    'a key under 16 characters must be refused where it is set, not 400 later',
                );
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => $chatly->withIdempotencyKey('has spaces in it and is long enough'),
                    'a key with characters outside the accepted URI set must be refused',
                );
            },

            '§4 a retried write reuses the key from the attempt that failed' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(429, ['error' => ['code' => 'rate_limited', 'message' => 'slow down']], ['retry-after-short' => '0']);
                $transport->push(201, ['id' => 'c1']);
                self::client($transport)->contacts->upsert(['email' => 'a@b.test']);

                Assert::same(2, $transport->count(), 'the write should have been attempted twice');
                Assert::same(
                    $transport->header('idempotency-key', 0),
                    $transport->header('idempotency-key', 1),
                    'a retry that minted a new key would double-write; that is what the header exists to stop',
                );
            },

            '§2 repeated query keys stay repeated' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [], 'nextCursor' => null]);
                self::client($transport)->tickets->list([
                    'filter[]' => ['status:open', 'priority:urgent'],
                    'limit' => 50,
                ]);

                $query = $transport->query();
                Assert::same(
                    'filter%5B%5D=status%3Aopen&filter%5B%5D=priority%3Aurgent&limit=50',
                    $query,
                    'two filters must be two parameters, never one comma-joined value',
                );
                Assert::notContains(',', $query, 'a comma-joined filter list would be read as a single filter');
            },

            '§2 search sends `kinds` WITHOUT brackets' => static function (): void {
                /*
                 * `/v1/search` validates a key literally named `kinds`.
                 * `kinds[]=kb` arrives as a key called `kinds[]`, which the
                 * schema strips — the restriction is silently dropped and the
                 * caller gets every kind back while believing they asked for
                 * one. The Node SDK sends the bracketed form.
                 */
                $transport = new FakeTransport();
                $transport->push(200, ['items' => []]);
                self::client($transport)->search->kb('refund policy');

                Assert::same(
                    'q=refund%20policy&kinds=kb',
                    $transport->query(),
                    'search must send kinds=kb, not kinds[]=kb',
                );
            },

            '§2 search->query passes options through and `kinds` can be widened' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => []]);
                self::client($transport)->search->query('refund', [
                    'kinds' => ['ticket', 'conversation'],
                    'limit' => 10,
                ]);

                Assert::same(
                    'q=refund&kinds=ticket&kinds=conversation&limit=10',
                    $transport->query(),
                    'a kinds list must become repeated unbracketed parameters',
                );
            },

            '§2 a boolean query value is `true`/`false`, not `1`/empty' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [], 'nextCursor' => null]);
                self::client($transport)->contacts->list(['blocked' => false]);

                Assert::same('blocked=false', $transport->query(), 'false must not be sent as an empty value');
            },

            '§2 a null query value is omitted entirely' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [], 'nextCursor' => null]);
                self::client($transport)->contacts->list(['cursor' => null, 'limit' => 25]);

                Assert::same('limit=25', $transport->query(), 'a null must not be sent as `cursor=`');
            },

            '§2 a path segment is percent-encoded' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['id' => 'c1']);
                self::client($transport)->contacts->removeTag('c 1', 'vip customer');

                Assert::same(
                    '/v1/contacts/c%201/tags/vip%20customer',
                    $transport->path(),
                    'a space must be %20 in a path segment, never a plus',
                );
                Assert::same('DELETE', $transport->method(), 'removeTag is a DELETE');
            },

            '§2 an empty id is refused rather than aimed at the collection' => static function (): void {
                $transport = new FakeTransport();
                $chatly = self::client($transport);
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => $chatly->contacts->get(''),
                    'an empty id would address /v1/contacts and return a page where an object was expected',
                );
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => $chatly->macros->delete(''),
                    'an empty id would aim a DELETE at the whole collection',
                );
                Assert::same(0, $transport->count(), 'neither call may reach the transport');
            },

            '§2 a bodyless POST still sends an empty JSON object' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['queued' => true]);
                self::client($transport)->webhooks->redeliver('d1');

                Assert::same('{}', $transport->body(), 'a POST with no fields must send {} and a JSON content type');
                Assert::same('application/json', $transport->header('content-type'), 'Content-Type must be set');
            },

            '§2 an empty array body is sent as an object, not as []' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['ok' => true]);
                self::client($transport)->request('POST', '/v1/anything', body: []);

                Assert::same(
                    '{}',
                    $transport->body(),
                    'json_encode([]) is "[]", which every body schema on this API rejects as not-an-object',
                );
            },

            '§2 a write body is sent as the JSON the caller gave' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 'm1']);
                self::client($transport)->conversations->send('v7-conv', 'hello & welcome', ['kind' => 'note']);

                Assert::same('/v1/conversations/v7-conv/messages', $transport->path(), 'send targets the thread');
                Assert::same(
                    ['body' => 'hello & welcome', 'kind' => 'note'],
                    $transport->decodedBody(),
                    'the body and options must be merged into one object',
                );
            },

            '§8 the escape hatch goes through auth and idempotency' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 'cm1']);
                $result = self::client($transport)->request(
                    'POST',
                    '/v1/tickets/t1/comments',
                    body: ['body' => 'looked into it', 'isInternal' => true],
                );

                Assert::same(['id' => 'cm1'], $result, 'the escape hatch returns the decoded JSON');
                Assert::same('Bearer ck_test', $transport->header('authorization'), 'auth still applies');
                Assert::true($transport->header('idempotency-key') !== null, 'a write through the hatch still carries a key');
                Assert::same('/v1/tickets/t1/comments', $transport->path(), 'the path is used as given');
            },

            '§8 the escape hatch accepts a path with no leading slash' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, []);
                self::client($transport)->request('GET', 'v1/labels');

                Assert::same('https://api.chatlychat.com/v1/labels', $transport->url(), 'a missing leading slash is added');
            },

            '§8 a caller-supplied header overrides the SDK default' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, []);
                self::client($transport)->request('GET', '/v1/labels', headers: ['Accept' => 'text/csv']);

                Assert::same('text/csv', $transport->header('accept'), 'an explicit header must win');
            },

            '§2 a nested query value is refused with an explanation' => static function (): void {
                $chatly = self::client(new FakeTransport());
                $error = Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => $chatly->request('GET', '/v1/tickets', ['filter' => [['status' => 'open']]]),
                    'a nested array cannot become a query string',
                );
                Assert::contains('nested', $error->getMessage(), 'the message should say what is wrong');
            },

            '§3 a 204 decodes to null rather than failing to parse' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(204);
                $result = self::client($transport)->macros->delete('m1');

                Assert::same(null, $result, 'a No Content response is null, not a JSON error');
            },

            '§3 a 2xx body that is not JSON is an error, not silent null' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, '<html>502 Bad Gateway</html>');
                $chatly = self::client($transport);
                $error = Assert::throws(
                    \Chatly\Errors\ChatlyException::class,
                    static fn () => $chatly->whoami(),
                    'an HTML error page served with a 200 must not decode to null',
                );
                Assert::same('invalid_json', $error->getErrorCode(), 'the failure should name itself');
                Assert::contains('<html>', $error->getBody(), 'the raw body must be preserved for diagnosis');
            },

            '§2 scheduleMessage converts a DateTime to the UTC form the API accepts' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 's1']);
                $when = new \DateTimeImmutable('2026-03-01 12:30:00', new \DateTimeZone('Europe/Berlin'));
                self::client($transport)->conversations->scheduleMessage('v1', 'later', $when);

                $body = $transport->decodedBody();
                Assert::same(
                    '2026-03-01T11:30:00Z',
                    $body['sendAt'] ?? null,
                    'z.string().datetime() rejects an offset like +01:00, so this must be converted to UTC Z',
                );
            },

            '§2 scheduleMessage passes a string through untouched' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 's1']);
                self::client($transport)->conversations->scheduleMessage('v1', 'later', '2026-03-01T11:30:00Z');

                Assert::same(
                    '2026-03-01T11:30:00Z',
                    $transport->decodedBody()['sendAt'] ?? null,
                    'a caller-formatted instant must not be reformatted',
                );
            },

            '§2 unsubscribe sends the flag the API requires' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['id' => 'c1'])->push(201, ['id' => 'c1']);
                $chatly = self::client($transport);
                $chatly->contacts->unsubscribe('c1');
                $chatly->contacts->unsubscribe('c1', false, 'asked to be re-added');

                Assert::same(
                    ['unsubscribed' => true],
                    $transport->decodedBody(0),
                    'a bodyless call to this route is a 400; the flag must be explicit',
                );
                Assert::same(
                    ['unsubscribed' => false, 'reason' => 'asked to be re-added'],
                    $transport->decodedBody(1),
                    'false must re-subscribe, and the reason must be carried',
                );
            },

            '§2 bulkUpsert wraps the rows in the envelope the route expects' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['inserted' => 1, 'updated' => 0, 'errors' => []]);
                self::client($transport)->contacts->bulkUpsert([['email' => 'a@b.test']]);

                Assert::same(
                    ['contacts' => [['email' => 'a@b.test']]],
                    $transport->decodedBody(),
                    'the route takes {contacts:[…]}, not a bare array',
                );
            },

            '§2 billing->usage requires an eventType and sends it' => static function (): void {
                /*
                 * The handler validates `eventType` against a closed list and
                 * throws before doing any work, so a call with no query is a
                 * 400 and never a total. It shipped as an OPTIONAL argument in
                 * four of these SDKs, which made the method impossible to call
                 * successfully — so it is required here, and the value is
                 * checked at the call site rather than costing a round trip to
                 * learn the same thing.
                 */
                $transport = new FakeTransport();
                $transport->push(200, [
                    'workspaceId' => 'ws_1',
                    'eventType' => 'ai.tokens',
                    'from' => '2026-10-01T00:00:00.000Z',
                    'to' => '2026-11-01T00:00:00.000Z',
                    'total' => 184203,
                    'count' => 912,
                ]);
                $result = self::client($transport)->billing->usage('ai.tokens');

                Assert::same('eventType=ai.tokens', $transport->query(), 'the event type must reach the API');
                Assert::same('/v1/billing/usage', $transport->path(), 'on the usage route');
                Assert::same(184203, $result['total'] ?? null, 'and the summary comes back');

                $reflected = new \ReflectionMethod(\Chatly\Resources\Billing::class, 'usage');
                Assert::same(
                    1,
                    $reflected->getNumberOfRequiredParameters(),
                    'eventType must be a REQUIRED parameter, not an optional one',
                );
            },

            '§2 billing->usage refuses an event type the API does not know' => static function (): void {
                $chatly = self::client(new FakeTransport());
                $error = Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => $chatly->billing->usage('messages.sent'),
                    'a near-miss event type must fail at the call site',
                );
                Assert::contains(
                    'message.sent.agent',
                    $error->getMessage(),
                    'the message should list what is accepted',
                );
            },

            '§2 billing->usage carries an explicit window when given one' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['total' => 0, 'count' => 0]);
                self::client($transport)->billing->usage('conversation.opened', [
                    'from' => new \DateTimeImmutable('2026-09-01 00:00:00', new \DateTimeZone('UTC')),
                    'to' => '2026-10-01T00:00:00Z',
                ]);

                $query = $transport->query();
                Assert::contains('eventType=conversation.opened', $query, 'the event type');
                Assert::contains('from=2026-09-01T00%3A00%3A00%2B00%3A00', $query, 'a DateTime is formatted for you');
                Assert::contains('to=2026-10-01T00%3A00%3A00Z', $query, 'a string passes through');
            },

            '§2 tickets->bulk sends ids, action and payload' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['updated' => 2, 'skipped' => []]);
                self::client($transport)->tickets->bulk(['t1', 't2'], 'status', ['status' => 'closed']);

                Assert::same(
                    ['ids' => ['t1', 't2'], 'action' => 'status', 'payload' => ['status' => 'closed']],
                    $transport->decodedBody(),
                    'the bulk body shape must match the route',
                );
            },
        ];
    }
}

<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlyClient;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;

/** CONTRACT §1 (construction) and §10.1–§10.2. */
final class ConstructionCases
{
    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            '§10.1 the default base URL is the production host' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['workspaceId' => 'w1', 'scopes' => []]);
                // No apiUrl passed. This is the whole point of the case.
                $chatly = new ChatlyClient('ck_test', transport: $transport);
                $chatly->whoami();

                Assert::same(
                    'https://api.chatlychat.com/v1/api-keys/whoami',
                    $transport->url(),
                    'a client constructed with only a key must call api.chatlychat.com',
                );
            },

            '§1 no dead default host can creep back in' => static function (): void {
                /*
                 * `api.livechat.local` shipped as the default in two SDKs and
                 * `api.chatly.example` in two more; all four resolve nowhere,
                 * so every call failed for anyone who followed the docs. This
                 * asserts the constant itself, because the case above would
                 * also pass if someone changed BOTH it and the expectation.
                 */
                foreach (['livechat.local', 'chatly.example', 'localhost', '127.0.0.1', 'example.com'] as $dead) {
                    Assert::notContains(
                        $dead,
                        ChatlyClient::DEFAULT_BASE_URL,
                        'DEFAULT_BASE_URL must never be a development or example host',
                    );
                }
                Assert::same(
                    'https://api.chatlychat.com',
                    ChatlyClient::DEFAULT_BASE_URL,
                    'DEFAULT_BASE_URL is production',
                );
            },

            '§1 an empty base URL falls back to production, not to nowhere' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, []);
                // `CHATLY_API_URL=` in a compose file arrives as ''.
                $chatly = new ChatlyClient('ck_test', '', transport: $transport);
                $chatly->whoami();

                Assert::same(
                    'https://api.chatlychat.com/v1/api-keys/whoami',
                    $transport->url(),
                    "an empty-string base URL must land on production",
                );
            },

            '§1 a self-hosted base URL is honoured, trailing slash and all' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, []);
                $chatly = new ChatlyClient('ck_test', 'https://chat.acme.internal/', transport: $transport);
                $chatly->whoami();

                Assert::same(
                    'https://chat.acme.internal/v1/api-keys/whoami',
                    $transport->url(),
                    'a trailing slash must not produce a double slash',
                );
            },

            '§1 an empty key is rejected at construction, not at the first call' => static function (): void {
                $transport = new FakeTransport();
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => new ChatlyClient('', transport: $transport),
                    'an empty API key must be refused by the constructor',
                );
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => new ChatlyClient('   ', transport: $transport),
                    'a whitespace-only API key must be refused too',
                );
                Assert::same(0, $transport->count(), 'a rejected construction must make no request');
            },

            '§1 a base URL that is not http(s) is rejected' => static function (): void {
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => new ChatlyClient('ck_test', 'api.chatlychat.com', transport: new FakeTransport()),
                    'a scheme-less base URL must be refused',
                );
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => new ChatlyClient('ck_test', 'ftp://api.chatlychat.com', transport: new FakeTransport()),
                    'a non-http scheme must be refused',
                );
            },

            '§10.2 Authorization carries the key, with the SDK Accept and User-Agent' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['workspaceId' => 'w1', 'scopes' => ['contacts:read']]);
                (new ChatlyClient('ck_live_abc123', transport: $transport))->whoami();

                Assert::same(
                    'Bearer ck_live_abc123',
                    $transport->header('authorization'),
                    'the key must travel as a bearer token',
                );
                Assert::same(
                    'application/json',
                    $transport->header('accept'),
                    'Accept must ask for JSON',
                );
                Assert::same(
                    'chatly-sdk-php/' . ChatlyClient::VERSION,
                    $transport->header('user-agent'),
                    'User-Agent must identify the SDK and its version',
                );
            },

            '§2 a GET carries no body and no Content-Type' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, ['items' => [], 'nextCursor' => null]);
                (new ChatlyClient('ck_test', transport: $transport))->contacts->list();

                Assert::same(null, $transport->body(), 'a GET must send no body');
                Assert::same(
                    null,
                    $transport->header('content-type'),
                    'a GET must not claim a Content-Type it has no body for',
                );
            },

            '§1 the per-request timeout reaches the transport' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, []);
                (new ChatlyClient('ck_test', timeout: 2.5, transport: $transport))->whoami();

                Assert::same(2.5, $transport->last()['timeout'], 'the configured timeout must be passed down');
            },

            '§1 the default timeout is 15 seconds' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(200, []);
                (new ChatlyClient('ck_test', transport: $transport))->whoami();

                Assert::same(15.0, $transport->last()['timeout'], 'the default timeout is 15s');
            },
        ];
    }
}

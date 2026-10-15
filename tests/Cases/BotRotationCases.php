<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\ChatlyClient;
use Chatly\Tests\Assert;
use Chatly\Tests\FakeTransport;

/**
 * `bots->rotateExternalSecret()` and its optional overlap window.
 *
 * Every assertion with teeth is on what the SDK SENT. The response is canned
 * by construction, so the one case that reads it back only proves the value
 * reaches the caller — it cannot prove the request was right.
 */
final class BotRotationCases
{
    private const PATH = '/v1/bots/bot_1/external/rotate-secret';

    private static function client(FakeTransport $transport): ChatlyClient
    {
        return new ChatlyClient('ck_test', transport: $transport);
    }

    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            'rotateExternalSecret with a string activateAt sends exactly {"activateAt": …}' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['signingSecret' => 'whsec_x', 'activatesAt' => '2026-10-16T09:00:00.000Z']);
                self::client($transport)->bots->rotateExternalSecret('bot_1', '2026-10-16T11:00:00+02:00');

                Assert::same(1, $transport->count(), 'exactly one request');
                Assert::same('POST', $transport->method(), 'the route is a POST');
                Assert::same(self::PATH, $transport->path(), 'the bot id goes in the path');
                Assert::same('', $transport->query(), 'nothing in the query string');
                Assert::true(
                    $transport->header('idempotency-key') !== null,
                    'a write must carry an Idempotency-Key',
                );
                Assert::same('application/json', $transport->header('content-type'), 'a JSON body');

                $raw = (string) $transport->body();
                Assert::notContains('activate_at', $raw, 'the server schema is .strict(): snake_case is a 400');
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                Assert::same(
                    ['activateAt' => '2026-10-16T11:00:00+02:00'],
                    $decoded,
                    'the body is exactly one camelCase key, and a string is passed through verbatim',
                );
            },

            'rotateExternalSecret converts a DateTimeInterface to the UTC Z instant with millis' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['signingSecret' => 'whsec_x', 'activatesAt' => '2026-10-16T09:00:00.250Z']);
                $when = new \DateTimeImmutable('2026-10-16T11:00:00.250+02:00');
                self::client($transport)->bots->rotateExternalSecret('bot_1', $when);

                Assert::same(
                    ['activateAt' => '2026-10-16T09:00:00.250Z'],
                    $transport->decodedBody(),
                    'a +02:00 instant must be sent as the SAME instant in UTC, with Z and milliseconds',
                );
            },

            'rotateExternalSecret converts a mutable DateTime in a named zone too' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['signingSecret' => 'whsec_x', 'activatesAt' => '2026-01-15T16:30:00.000Z']);
                $when = new \DateTime('2026-01-15 11:30:00', new \DateTimeZone('America/New_York'));
                self::client($transport)->bots->rotateExternalSecret('bot_1', $when);

                Assert::same(
                    '2026-01-15T16:30:00.000Z',
                    $transport->decodedBody()['activateAt'] ?? null,
                    'EST is UTC-5, so 11:30 local is 16:30Z',
                );
                Assert::same(
                    'America/New_York',
                    $when->getTimezone()->getName(),
                    "the caller's DateTime must not be mutated into UTC",
                );
            },

            'rotateExternalSecret with no activateAt sends what it always sent: {} and no fields' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['signingSecret' => 'whsec_x', 'activatesAt' => null]);
                self::client($transport)->bots->rotateExternalSecret('bot_1');

                Assert::same('POST', $transport->method(), 'a POST');
                Assert::same(self::PATH, $transport->path(), 'the same path');
                Assert::same('{}', $transport->body(), 'the hard cut sends the transport-wide {} and nothing else');
                $headers = $transport->request()['headers'];
                unset($headers['Idempotency-Key'], $headers['User-Agent']);
                Assert::same(
                    [
                        'Authorization' => 'Bearer ck_test',
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    $headers,
                    'the header set is unchanged from before activateAt existed',
                );
                Assert::true($transport->header('idempotency-key') !== null, 'still keyed');
            },

            'rotateExternalSecret with an explicit null is the hard cut as well' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['signingSecret' => 'whsec_x', 'activatesAt' => null]);
                self::client($transport)->bots->rotateExternalSecret('bot_1', null);

                Assert::same('{}', $transport->body(), 'null must not serialise as {"activateAt":null}');
            },

            'rotateExternalSecret returns activatesAt to the caller, string or null' => static function (): void {
                $transport = new FakeTransport();
                $transport->push(201, ['signingSecret' => 'whsec_x', 'activatesAt' => '2026-10-16T09:00:00.000Z']);
                $transport->push(201, ['signingSecret' => 'whsec_y', 'activatesAt' => null]);
                $bots = self::client($transport)->bots;

                $deferred = $bots->rotateExternalSecret('bot_1', '2026-10-16T09:00:00.000Z');
                Assert::same('whsec_x', $deferred['signingSecret'] ?? null, 'the new secret is returned');
                Assert::same('2026-10-16T09:00:00.000Z', $deferred['activatesAt'] ?? null, 'the switch instant');

                $now = $bots->rotateExternalSecret('bot_1');
                Assert::true(\is_array($now) && \array_key_exists('activatesAt', $now), 'the key is present');
                Assert::same(null, $now['activatesAt'], 'null means the new secret is already signing');
            },
        ];
    }
}

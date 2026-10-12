<?php

declare(strict_types=1);

namespace Chatly\Tests\Cases;

use Chatly\Identity;
use Chatly\Tests\Assert;

/** CONTRACT §7 (identity hash) and §10.8. */
final class IdentityCases
{
    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        return [
            '§10.8 the pinned identity-hash vector matches' => static function (): void {
                /*
                 * Cross-language pinned vector, computed and cross-checked in
                 * two implementations. If this SDK disagrees, this SDK is
                 * wrong — the parity gate compares these digests across
                 * languages, so changing the expectation here would break
                 * every other SDK's agreement rather than fix this one.
                 */
                Assert::same(
                    '5e26772dce97d5ba995796e621f83f2e97e6f8c3f9386dbf153a98ab24c10271',
                    Identity::hash('sek_test_secret', 'user_42'),
                    'identity_hash("sek_test_secret", "user_42")',
                );
            },

            '§7 the digest is lowercase hex of the documented length' => static function (): void {
                $digest = Identity::hash('sek_test_secret', 'user_42');

                Assert::same(64, \strlen($digest), 'SHA-256 is 32 bytes, 64 hex characters');
                Assert::true(
                    preg_match('/^[0-9a-f]{64}$/', $digest) === 1,
                    'lowercase hex only — the widget compares the string as given',
                );
            },

            '§7 the id is hashed under the secret, not the other way round' => static function (): void {
                /*
                 * HMAC is not symmetric, and swapping the arguments produces a
                 * perfectly well-formed digest that authenticates nothing.
                 * This is the mistake a port makes, so it is pinned.
                 */
                Assert::notSame(
                    Identity::hash('sek_test_secret', 'user_42'),
                    hash_hmac('sha256', 'sek_test_secret', 'user_42'),
                    'the message is the external id; the key is the secret',
                );
                Assert::same(
                    hash_hmac('sha256', 'user_42', 'sek_test_secret'),
                    Identity::hash('sek_test_secret', 'user_42'),
                    'HMAC-SHA256(message: externalId, key: secret)',
                );
            },

            '§7 different ids and different secrets give different digests' => static function (): void {
                Assert::notSame(
                    Identity::hash('sek_test_secret', 'user_42'),
                    Identity::hash('sek_test_secret', 'user_43'),
                    'two users must not share a hash',
                );
                Assert::notSame(
                    Identity::hash('sek_test_secret', 'user_42'),
                    Identity::hash('sek_other_secret', 'user_42'),
                    'rotating the secret must invalidate old hashes',
                );
            },

            '§7 an empty secret or an empty id is refused' => static function (): void {
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => Identity::hash('', 'user_42'),
                    'an unset secret would produce a digest that authenticates nothing',
                );
                Assert::throws(
                    \InvalidArgumentException::class,
                    static fn () => Identity::hash('sek_test_secret', ''),
                    'an empty external id would let every anonymous visitor share one identity',
                );
            },
        ];
    }
}

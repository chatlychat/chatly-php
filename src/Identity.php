<?php

declare(strict_types=1);

namespace Chatly;

/**
 * Widget identity verification — the backend half.
 *
 * When a workspace turns identity verification on, the widget must prove the
 * `externalId` it claims was issued by your backend rather than typed into a
 * browser console. Otherwise anyone can open a chat as anyone else's account
 * and read their history.
 */
final class Identity
{
    /**
     * The `userHash` to hand the widget alongside an `externalId`.
     *
     * Lowercase hex HMAC-SHA256 of the id under the workspace's
     * identity-verification secret.
     *
     * ```php
     * $userHash = Chatly\Identity::hash($_ENV['CHATLY_IDV_SECRET'], (string) $user->id);
     * // then render, server-side:
     * //   Chatly('identify', { externalId: …, userHash: '<?= $userHash ?>', email: … })
     * ```
     *
     * **The secret must never reach a browser — only the digest does.** It is
     * a workspace-wide credential: with it, anyone can impersonate every one
     * of your users to your own support team.
     *
     * @throws \InvalidArgumentException For an empty secret or an empty id.
     *                                  Both are configuration bugs that would
     *                                  otherwise produce a well-formed digest
     *                                  that authenticates nothing — and unlike
     *                                  webhook verification, failing closed
     *                                  here means failing loudly.
     */
    public static function hash(string $secret, string $externalId): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Chatly\\Identity::hash: secret is empty');
        }
        if ($externalId === '') {
            throw new \InvalidArgumentException('Chatly\\Identity::hash: externalId is empty');
        }

        return hash_hmac('sha256', $externalId, $secret);
    }
}

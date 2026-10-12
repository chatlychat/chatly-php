<?php

declare(strict_types=1);

namespace Chatly\Tests;

use Chatly\Tests\Cases\ConstructionCases;
use Chatly\Tests\Cases\ErrorCases;
use Chatly\Tests\Cases\IdentityCases;
use Chatly\Tests\Cases\PaginationCases;
use Chatly\Tests\Cases\RequestCases;
use Chatly\Tests\Cases\SurfaceCases;
use Chatly\Tests\Cases\WebhookCases;

/**
 * Every case in the suite, as `name => closure`.
 *
 * One list, two runners: `tests/run.php` executes it with bare PHP, and
 * {@see ContractTest} feeds it to PHPUnit. Neither is the source of truth for
 * what gets tested — this is — so the two cannot disagree about what passed.
 */
final class Suite
{
    /** @return array<string,\Closure():void> */
    public static function cases(): array
    {
        $groups = [
            'construction' => ConstructionCases::cases(),
            'request' => RequestCases::cases(),
            'errors' => ErrorCases::cases(),
            'pagination' => PaginationCases::cases(),
            'webhooks' => WebhookCases::cases(),
            'identity' => IdentityCases::cases(),
            'surface' => SurfaceCases::cases(),
        ];

        $flat = [];
        foreach ($groups as $group => $cases) {
            foreach ($cases as $name => $case) {
                $key = $group . ' :: ' . $name;
                if (isset($flat[$key])) {
                    throw new \LogicException("duplicate test case name: {$key}");
                }
                $flat[$key] = $case;
            }
        }

        return $flat;
    }

    /**
     * The nine items of CONTRACT.md §10, and the case-name prefix that covers
     * each.
     *
     * Checked by the runner, so dropping a case that covers a contract item
     * fails the suite instead of quietly shrinking it. A list of names is a
     * weak check — it proves a case with that label exists, not that the case
     * is any good — but the failure it catches is the one that happens:
     * coverage disappearing in a refactor and nobody noticing.
     *
     * @return array<string,string>
     */
    public static function contractCoverage(): array
    {
        return [
            '1. default base URL is production' => '§10.1',
            '2. Authorization carries the key' => '§10.2',
            '3. idempotency: fresh, distinct, pinnable' => '§10.3',
            '4. typed errors with request_id' => '§10.4',
            '5. 429 retried, 400 not' => '§10.5',
            '6. auto-paginator walks and stops' => '§10.6',
            '7. webhook signature accept/reject' => '§10.7',
            '8. identity-hash pinned vector' => '§10.8',
            '9. pinned webhook delivery vector' => '§10.9',
        ];
    }
}
